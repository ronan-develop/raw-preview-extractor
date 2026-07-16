<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Parser\Tiff;

use RonanLenouvel\RawPreviewExtractor\Exception\CorruptedFileException;

/**
 * Low-level reading of a TIFF 6.0 container.
 *
 * This reader knows the container's **structure** — header, IFD chain, typed
 * entries — and nothing of the content's semantics: it has no idea what a
 * preview is. It is this boundary that makes it testable without a real RAW file.
 *
 * All reads treat the file as **untrusted** input: every offset is validated
 * against the real size, every `fread` is checked for length before `unpack`,
 * and the IFD chain is protected against loops.
 */
final class TiffReader
{
    private const HEADER_LENGTH = 8;
    private const TIFF_MAGIC = 42;
    private const ENTRY_LENGTH = 12;

    /** Beyond this, an IFD betrays a corrupted or hostile file. */
    private const MAX_ENTRIES_PER_IFD = 4096;

    /** Safeguard against an artificially long IFD chain. */
    private const MAX_IFD_CHAIN = 64;

    /**
     * Beyond this, a value is not resolved — the entry stays readable.
     *
     * No tag used by this package comes close to this size: they are offsets,
     * sizes, dimensions, a manufacturer name. The big blocks are proprietary
     * metadata (`MakerNote` commonly weighs 75 KB in a CR2) that we traverse
     * without ever reading them.
     *
     * Not resolving them avoids two things: wasting the read, and rejecting a
     * perfectly valid file — which is what happened to the 2005 Canon 5D.
     */
    private const MAX_RESOLVED_VALUE_LENGTH = 65536;

    /** Size in bytes of each TIFF 6.0 type, indexed by type code. */
    private const TYPE_SIZES = [
        1 => 1,   // BYTE
        2 => 1,   // ASCII
        3 => 2,   // SHORT
        4 => 4,   // LONG
        5 => 8,   // RATIONAL
        6 => 1,   // SBYTE
        7 => 1,   // UNDEFINED
        8 => 2,   // SSHORT
        9 => 4,   // SLONG
        10 => 8,  // SRATIONAL
        11 => 4,  // FLOAT
        12 => 8,  // DOUBLE
    ];

    /** @var resource */
    private $handle;

    private readonly bool $bigEndian;
    private readonly int $fileSize;
    private readonly int $firstIfdOffset;

    /**
     * @param string $path path of the TIFF file to read
     *
     * @throws CorruptedFileException if the file is unreadable or is not a TIFF
     */
    public function __construct(private readonly string $path)
    {
        if (!is_file($path)) {
            throw new CorruptedFileException(sprintf('Unreadable file: %s', $path));
        }

        // is_file() has already ruled out the missing file and the directory:
        // fopen can now only fail on a permissions problem, which @ would
        // absorb silently — so we let it bubble up.
        $this->handle = fopen($path, 'rb');
        $this->fileSize = (int) filesize($path);

        $header = (string) fread($this->handle, self::HEADER_LENGTH);

        if (self::HEADER_LENGTH !== strlen($header)) {
            throw new CorruptedFileException('Truncated TIFF header: fewer than 8 bytes.');
        }

        $this->bigEndian = match (substr($header, 0, 2)) {
            'II' => false,
            'MM' => true,
            default => throw new CorruptedFileException(
                'Unknown byte order: neither "II" nor "MM".',
            ),
        };

        $magic = $this->unpackShort(substr($header, 2, 2));

        if (self::TIFF_MAGIC !== $magic) {
            throw new CorruptedFileException(
                sprintf('Invalid TIFF magic number: %d instead of 42.', $magic),
            );
        }

        $this->firstIfdOffset = $this->unpackLong(substr($header, 4, 4));
    }

    public function __destruct()
    {
        fclose($this->handle);
    }

    /**
     * Is the file big-endian ("MM")?
     */
    public function isBigEndian(): bool
    {
        return $this->bigEndian;
    }

    /**
     * Walks the IFD chain and returns the offsets encountered, in order.
     *
     * An already visited IFD ends the walk: that is the anti-loop safeguard. A
     * corrupted — or malicious — file can make an IFD point to itself, which no
     * real file does.
     *
     * @return list<int>
     *
     * @throws CorruptedFileException if an offset of the chain is out of bounds
     */
    public function readIfdOffsets(): array
    {
        $offsets = [];
        $visited = [];
        $offset = $this->firstIfdOffset;

        while (0 !== $offset && count($offsets) < self::MAX_IFD_CHAIN) {
            if (isset($visited[$offset])) {
                break;
            }

            $visited[$offset] = true;
            $offsets[] = $offset;
            $offset = $this->readNextIfdOffset($offset);
        }

        return $offsets;
    }

    /**
     * Reads the entries of an IFD located at the given offset.
     *
     * @return list<IfdEntry>
     *
     * @throws CorruptedFileException if the offset is out of bounds or the IFD truncated
     */
    public function readIfd(int $offset): array
    {
        $count = $this->readEntryCount($offset);
        $entries = [];

        for ($i = 0; $i < $count; ++$i) {
            $entries[] = $this->readEntry(
                $this->readBytes($offset + 2 + $i * self::ENTRY_LENGTH, self::ENTRY_LENGTH),
            );
        }

        return $entries;
    }

    /**
     * Builds an entry from its 12 bytes, resolved values included.
     *
     * @throws CorruptedFileException if an indirect offset is out of bounds
     */
    private function readEntry(string $bytes): IfdEntry
    {
        $tag = $this->unpackShort(substr($bytes, 0, 2));
        $type = $this->unpackShort(substr($bytes, 2, 2));
        $count = $this->unpackLong(substr($bytes, 4, 4));
        $field = substr($bytes, 8, 4);

        $size = self::TYPE_SIZES[$type] ?? null;

        // An unknown type is not a corruption: the entry stays readable, only
        // its value is undeterminable.
        if (null === $size || $count < 1) {
            return new IfdEntry($tag, $type, $count);
        }

        $length = $size * $count;

        // A value cannot be larger than the file that carries it: that is an
        // absurd size, hence a structure that lies.
        if ($length > $this->fileSize) {
            throw new CorruptedFileException(sprintf(
                'Entry 0x%04X: %d bytes announced, more than the whole file (%d).',
                $tag,
                $length,
                $this->fileSize,
            ));
        }

        // Too big to be a tag this package uses: we keep the entry — it stays
        // traversable — but we do not read its value. A 75 KB MakerNote is not
        // a corruption, it is metadata that is none of our business.
        if ($length > self::MAX_RESOLVED_VALUE_LENGTH) {
            return new IfdEntry($tag, $type, $count);
        }

        // The 4-byte rule: beyond that, the field carries an absolute offset and
        // not the value itself. Getting it wrong yields offsets that look like
        // valid data.
        $raw = $length <= 4
            ? substr($field, 0, $length)
            : $this->readBytes($this->unpackLong($field), $length);

        if (2 === $type) {
            return new IfdEntry($tag, $type, $count, [], rtrim($raw, "\x00"));
        }

        return new IfdEntry($tag, $type, $count, $this->unpackIntegers($raw, $size, $count));
    }

    /**
     * Reads `$length` raw bytes starting from `$offset`.
     *
     * @throws CorruptedFileException if the range falls outside the file
     */
    public function readBytes(int $offset, int $length): string
    {
        if ($length <= 0) {
            throw new CorruptedFileException(sprintf('Invalid read length: %d.', $length));
        }

        if ($offset < 0 || $offset + $length > $this->fileSize) {
            throw new CorruptedFileException(sprintf(
                'Read out of bounds: %d bytes at offset %d (file size: %d).',
                $length,
                $offset,
                $this->fileSize,
            ));
        }

        fseek($this->handle, $offset);

        // The range is already bounded against fileSize: fread returns exactly
        // $length bytes.
        return (string) fread($this->handle, $length);
    }

    /**
     * Decodes a sequence of integers according to the file's endianness.
     *
     * The bytes always come from readBytes(), which already guarantees their
     * length: no need to check it again here. TYPE_SIZES only contains sizes of
     * 1, 2, 4 or 8 bytes.
     *
     * @return list<int>
     */
    private function unpackIntegers(string $bytes, int $size, int $count): array
    {
        $values = [];

        for ($i = 0; $i < $count; ++$i) {
            $chunk = substr($bytes, $i * $size, $size);

            $values[] = match ($size) {
                1 => ord($chunk),
                2 => $this->unpackShort($chunk),
                // RATIONAL and DOUBLE (8 bytes): only the first 4 are of
                // interest to us — no tag used here needs the rest.
                default => $this->unpackLong(substr($chunk, 0, 4)),
            };
        }

        return $values;
    }

    /**
     * Decodes a 16-bit integer according to the file's endianness.
     */
    private function unpackShort(string $bytes): int
    {
        return unpack($this->bigEndian ? 'n' : 'v', $bytes)[1];
    }

    /**
     * Decodes a 32-bit integer according to the file's endianness.
     *
     * @throws CorruptedFileException if the bytes supplied are not 4 bytes long
     */
    private function unpackLong(string $bytes): int
    {
        return unpack($this->bigEndian ? 'N' : 'V', $bytes)[1];
    }

    /**
     * @throws CorruptedFileException if the offset is invalid or the count absurd
     */
    private function readEntryCount(int $offset): int
    {
        if ($offset < self::HEADER_LENGTH) {
            throw new CorruptedFileException(sprintf(
                'Invalid IFD offset: %d overlaps the header.',
                $offset,
            ));
        }

        $count = $this->unpackShort($this->readBytes($offset, 2));

        if ($count > self::MAX_ENTRIES_PER_IFD) {
            throw new CorruptedFileException(sprintf(
                'Absurd IFD entry count: %d.',
                $count,
            ));
        }

        // The file must contain every announced entry, plus the next link.
        $required = $offset + 2 + $count * self::ENTRY_LENGTH + 4;

        if ($required > $this->fileSize) {
            throw new CorruptedFileException(sprintf(
                'Truncated IFD: %d entries announced at offset %d exceed the file size.',
                $count,
                $offset,
            ));
        }

        return $count;
    }

    /**
     * @throws CorruptedFileException if the IFD is truncated
     */
    private function readNextIfdOffset(int $offset): int
    {
        $count = $this->readEntryCount($offset);

        return $this->unpackLong(
            $this->readBytes($offset + 2 + $count * self::ENTRY_LENGTH, 4),
        );
    }
}

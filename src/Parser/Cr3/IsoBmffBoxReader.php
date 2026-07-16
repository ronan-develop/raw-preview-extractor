<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Parser\Cr3;

use RonanLenouvel\RawPreviewExtractor\Exception\CorruptedFileException;

/**
 * Low-level reading of an ISO-BMFF container (MP4/HEIF family, CR3 included).
 *
 * This reader knows the **structure** — a tree of `[size][type][content]` boxes —
 * and nothing of the semantics: it has no idea what a preview is.
 *
 * **ISO-BMFF is always big-endian**, whatever the camera: unlike TIFF, there is
 * no byte order to detect.
 *
 * The file is treated as **untrusted** input: every size is validated against
 * the file's real size, the cursor's progress is checked at each iteration, and
 * the recursion is bounded.
 */
final class IsoBmffBoxReader
{
    /** Size of a box header: 4 bytes of size + 4 of type. */
    private const HEADER_LENGTH = 8;

    /** Extended header, when the size is carried on 64 bits. */
    private const EXTENDED_HEADER_LENGTH = 16;

    /** Length of the UUID that follows the type of a `uuid` box. */
    private const UUID_LENGTH = 16;

    /** Maximum nesting depth: a hostile file could saturate the stack. */
    private const MAX_DEPTH = 8;

    /**
     * Boxes whose content is itself a tree of boxes.
     *
     * Any other box is a leaf: `mdat` contains raw data that we would mistake
     * for boxes if we descended into it.
     */
    private const CONTAINER_TYPES = ['moov', 'trak', 'mdia', 'minf', 'stbl', 'uuid'];

    /** @var resource */
    private $handle;

    private readonly int $fileSize;

    /**
     * @param string $path path of the file to read
     *
     * @throws CorruptedFileException if the file is unreadable
     */
    public function __construct(private readonly string $path)
    {
        if (!is_file($path)) {
            throw new CorruptedFileException(sprintf('Unreadable file: %s', $path));
        }

        $this->handle = fopen($path, 'rb');
        $this->fileSize = (int) filesize($path);
    }

    public function __destruct()
    {
        fclose($this->handle);
    }

    /**
     * Inventories the top-level boxes.
     *
     * @return list<Box>
     *
     * @throws CorruptedFileException if a size is invalid or out of bounds
     */
    public function readBoxes(): array
    {
        // A file too short to carry even a header is not an empty container: it
        // is truncated. Inside a box on the other hand, a remainder of less than
        // 8 bytes is normal padding.
        if ($this->fileSize < self::HEADER_LENGTH) {
            throw new CorruptedFileException(sprintf(
                'Truncated file: %d bytes, less than a box header.',
                $this->fileSize,
            ));
        }

        return $this->readBoxesIn(0, $this->fileSize);
    }

    /**
     * Looks for the first box of the given type, descending into the containers.
     *
     * @param string $type 4-character type
     *
     * @throws CorruptedFileException if the structure is invalid
     */
    public function find(string $type): ?Box
    {
        return $this->findIn($this->readBoxes(), $type, 0);
    }

    /**
     * Looks for a `uuid` box carrying the given UUID.
     *
     * Several distinct `uuid` boxes coexist in a CR3: the type is not enough to
     * identify them, the 16 bytes that follow it must be read.
     *
     * @param string $uuid the 16 raw bytes of the UUID being looked for
     *
     * @throws CorruptedFileException if the structure is invalid
     */
    public function findUuid(string $uuid): ?Box
    {
        return $this->findUuidIn($this->readBoxes(), $uuid, 0);
    }

    /**
     * Inventories the child boxes of a container.
     *
     * @return list<Box>
     *
     * @throws CorruptedFileException if the structure is invalid
     */
    public function childBoxes(Box $box): array
    {
        return $this->childrenOf($box);
    }

    /**
     * Reads the content of a box.
     *
     * @throws CorruptedFileException if the range falls outside the file
     */
    public function readPayload(Box $box): string
    {
        return $this->readBytes($box->payloadOffset, $box->payloadLength);
    }

    /**
     * Reads `$length` raw bytes starting from `$offset`.
     *
     * @throws CorruptedFileException if the range falls outside the file
     */
    public function readBytes(int $offset, int $length): string
    {
        if ($length < 1 || $offset < 0 || $offset + $length > $this->fileSize) {
            throw new CorruptedFileException(sprintf(
                'Read out of bounds: %d bytes at offset %d (file size: %d).',
                $length,
                $offset,
                $this->fileSize,
            ));
        }

        fseek($this->handle, $offset);

        return (string) fread($this->handle, $length);
    }

    /**
     * Inventories the boxes contained in a given range.
     *
     * @return list<Box>
     *
     * @throws CorruptedFileException
     */
    private function readBoxesIn(int $start, int $end): array
    {
        $boxes = [];
        $offset = $start;

        while ($offset + self::HEADER_LENGTH <= $end) {
            $box = $this->readBoxAt($offset, $end);
            $boxes[] = $box;

            // Progress is structurally guaranteed: readBoxAt() refuses any size
            // smaller than the header, so the cursor advances by at least
            // 8 bytes on each pass.
            $offset = $box->payloadOffset + $box->payloadLength;
        }

        return $boxes;
    }

    /**
     * Decodes a box header, handling the three special cases of `size`.
     *
     * @throws CorruptedFileException
     */
    private function readBoxAt(int $offset, int $end): Box
    {
        $header = $this->readBytes($offset, self::HEADER_LENGTH);
        $size = unpack('N', substr($header, 0, 4))[1];
        $type = substr($header, 4, 4);

        // size == 1: the real size is on 64 bits, right after the type.
        if (1 === $size) {
            $size = unpack('J', $this->readBytes($offset + self::HEADER_LENGTH, 8))[1];

            if ($size < self::EXTENDED_HEADER_LENGTH) {
                throw new CorruptedFileException(sprintf(
                    'Invalid 64-bit box size: %d at offset %d.',
                    $size,
                    $offset,
                ));
            }

            return $this->box($type, $offset, self::EXTENDED_HEADER_LENGTH, $size, $end);
        }

        // size == 0: the box extends to the end of the file.
        if (0 === $size) {
            return $this->box($type, $offset, self::HEADER_LENGTH, $end - $offset, $end);
        }

        if ($size < self::HEADER_LENGTH) {
            throw new CorruptedFileException(sprintf(
                'Invalid box size: %d at offset %d (minimum 8).',
                $size,
                $offset,
            ));
        }

        return $this->box($type, $offset, self::HEADER_LENGTH, $size, $end);
    }

    /**
     * @throws CorruptedFileException if the box overflows the allowed range
     */
    private function box(string $type, int $offset, int $headerLength, int $size, int $end): Box
    {
        if ($offset + $size > $end) {
            throw new CorruptedFileException(sprintf(
                'Box "%s" out of bounds: %d bytes announced at offset %d.',
                $type,
                $size,
                $offset,
            ));
        }

        return new Box($type, $offset, $offset + $headerLength, $size - $headerLength);
    }

    /**
     * Recursively looks for a `uuid` box carrying the given UUID.
     *
     * In a real CR3, the Canon UUID lives under `moov` and not at the root: a
     * search limited to the first level would never find it.
     *
     * @param list<Box> $boxes
     *
     * @throws CorruptedFileException
     */
    private function findUuidIn(array $boxes, string $uuid, int $depth): ?Box
    {
        if ($depth > self::MAX_DEPTH) {
            return null;
        }

        foreach ($boxes as $box) {
            if ('uuid' === $box->type
                && $box->payloadLength >= self::UUID_LENGTH
                && $uuid === $this->readBytes($box->payloadOffset, self::UUID_LENGTH)
            ) {
                return $box;
            }

            if (!in_array($box->type, self::CONTAINER_TYPES, true)) {
                continue;
            }

            $found = $this->findUuidIn($this->childrenOf($box), $uuid, $depth + 1);

            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param list<Box> $boxes
     *
     * @throws CorruptedFileException
     */
    private function findIn(array $boxes, string $type, int $depth): ?Box
    {
        if ($depth > self::MAX_DEPTH) {
            return null;
        }

        foreach ($boxes as $box) {
            if ($box->type === $type) {
                return $box;
            }

            if (!in_array($box->type, self::CONTAINER_TYPES, true)) {
                continue;
            }

            $found = $this->findIn($this->childrenOf($box), $type, $depth + 1);

            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Child boxes of a container.
     *
     * @return list<Box>
     *
     * @throws CorruptedFileException
     */
    private function childrenOf(Box $box): array
    {
        // A uuid box carries 16 bytes of UUID before its content.
        $start = 'uuid' === $box->type
            ? $box->payloadOffset + self::UUID_LENGTH
            : $box->payloadOffset;

        $end = $box->payloadOffset + $box->payloadLength;

        if ($start >= $end) {
            return [];
        }

        try {
            return $this->readBoxesIn($start, $end);
        } catch (CorruptedFileException) {
            // Descending into a container is speculative: not every `uuid` box
            // contains boxes. The one carrying PRVW in a CR3 starts with a
            // proprietary header, whose bytes read as a header give an absurd
            // size.
            //
            // A box without readable children has no children — this is not a
            // corruption of the file. The non-speculative reads (readBoxes,
            // readPayload, readBytes) stay strict.
            return [];
        }
    }
}

<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Parser\Cr3;

/**
 * An ISO-BMFF box, located in the file.
 *
 * The box carries its position and its size, never its content: an `mdat`
 * weighs several tens of megabytes, we do not load it just to inventory it.
 * It is {@see IsoBmffBoxReader::readPayload()} that reads the bytes on demand.
 */
final readonly class Box
{
    /**
     * @param string $type          4-ASCII-character type (`ftyp`, `moov`, `uuid`…)
     * @param int    $offset        position of the box in the file, header included
     * @param int    $payloadOffset position of the content, after the header
     * @param int    $payloadLength size of the content, in bytes
     */
    public function __construct(
        public string $type,
        public int $offset,
        public int $payloadOffset,
        public int $payloadLength,
    ) {
    }
}

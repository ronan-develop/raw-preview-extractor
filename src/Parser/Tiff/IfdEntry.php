<?php

declare(strict_types=1);

namespace RonanLenouvel\RawPreviewExtractor\Parser\Tiff;

/**
 * An IFD entry, values already resolved.
 *
 * A pure value object: it knows neither file, nor handle, nor endianness. It is
 * the {@see TiffReader} that resolves the values while reading — including
 * those stored outside the entry — and builds complete `IfdEntry` objects.
 *
 * This entry is therefore freely transportable and comparable, which an object
 * holding on to a file cursor would not be.
 */
final readonly class IfdEntry
{
    /**
     * @param int       $tag    tag identifier (see {@see TiffTag})
     * @param int       $type   TIFF data type code (1 to 12)
     * @param int       $count  number of values, as announced by the entry
     * @param list<int> $values resolved numeric values; empty if the type is
     *                          ASCII or unknown
     * @param string    $ascii  textual value, trailing NUL removed; empty
     *                          string if the type is not ASCII
     */
    public function __construct(
        public int $tag,
        public int $type,
        public int $count,
        public array $values = [],
        public string $ascii = '',
    ) {
    }

    /**
     * First numeric value of the entry, or null if there is none.
     *
     * A shortcut for the common case: most tags used here (offsets, sizes,
     * dimensions) carry a single value.
     */
    public function value(): ?int
    {
        return $this->values[0] ?? null;
    }
}

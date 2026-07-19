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

    /**
     * First RATIONAL value as a float, or null.
     *
     * A RATIONAL is a numerator/denominator pair; {@see TiffReader} stores both
     * halves side by side in {@see $values}. Returns null when the entry is not
     * a resolved rational or the denominator is zero (a lie, not a value).
     */
    public function rational(): ?float
    {
        [$numerator, $denominator] = [$this->values[0] ?? null, $this->values[1] ?? null];

        if (null === $numerator || null === $denominator || 0 === $denominator) {
            return null;
        }

        return $numerator / $denominator;
    }

    /**
     * First RATIONAL value as its raw numerator/denominator pair, or null.
     *
     * Lets a caller keep the fraction intact — a 1/250 s shutter speed reads
     * better as "1/250" than as "0.004".
     *
     * @return array{int, int}|null
     */
    public function rationalPair(): ?array
    {
        [$numerator, $denominator] = [$this->values[0] ?? null, $this->values[1] ?? null];

        if (null === $numerator || null === $denominator) {
            return null;
        }

        return [$numerator, $denominator];
    }
}

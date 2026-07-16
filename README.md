# Raw Preview Extractor

Extract the JPEG preview embedded in camera RAW files — **pure PHP, no external binaries**.

[![CI](https://github.com/ronan-develop/raw-preview-extractor/actions/workflows/ci.yml/badge.svg)](https://github.com/ronan-develop/raw-preview-extractor/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

> **Status: feature-complete, not yet released.** All five formats extract; the API below
> is implemented and covered by tests. Waiting on validation against real camera files
> before tagging `1.0.0`.

## Why

Displaying a thumbnail for a RAW file normally means decoding it, which requires `libraw`
or Imagick — unavailable on shared hosting without root access.

Almost every modern camera already embeds a full JPEG preview inside its RAW files. This
library **locates and extracts that JPEG** by reading the container structure. No
demosaicing, no sensor data, no dependencies: just byte reading.

## Supported formats

| Format      | Container | Detected by            |
|-------------|-----------|------------------------|
| CR2 (Canon) | TIFF 6.0  | `CR` signature         |
| CR3 (Canon) | ISO-BMFF  | `ftyp` + `crx` brand   |
| NEF (Nikon) | TIFF 6.0  | `Make` tag             |
| ARW (Sony)  | TIFF 6.0  | `Make` tag             |
| DNG (Adobe) | TIFF 6.0  | `DNGVersion` tag       |

Detection reads the file's **binary signature**, never its extension: a `.cr2` renamed to
`.jpg` is still detected as a CR2, and a `.cr2` that isn't one is rejected.

RAF (Fuji) and ORF (Olympus) are planned for v2.

## Requirements

PHP >= 8.2. That's it — the `require` section depends on nothing else.

The optional Symfony bundle supports **6.4 (LTS), 7.x and 8.x**. Composer picks whichever
matches your application; the library itself never depends on Symfony.

## Installation

```bash
composer require ronanlenouvel/raw-preview-extractor
```

## Usage

```php
use RonanLenouvel\RawPreviewExtractor\Exception\RawPreviewExtractorException;
use RonanLenouvel\RawPreviewExtractor\RawPreviewExtractor;

$extractor = RawPreviewExtractor::createDefault();

try {
    $preview = $extractor->extract('/path/to/photo.cr2');

    file_put_contents('/path/to/thumbnail.jpg', $preview->jpegData);

    echo $preview->width, 'x', $preview->height;
    echo $preview->sourceFormat->name;
} catch (RawPreviewExtractorException) {
    // Every exception thrown by this package implements this interface,
    // so a single catch is enough to degrade gracefully.
}
```

Check support before extracting:

```php
if ($extractor->supports($path)) {
    // …
}
```

### Exceptions

All of them implement `RawPreviewExtractorException`:

| Exception                    | Meaning                                  |
|------------------------------|------------------------------------------|
| `UnsupportedFormatException` | not a supported RAW file                 |
| `PreviewNotFoundException`   | valid file, but no embedded JPEG preview |
| `CorruptedFileException`     | unreadable or structurally invalid file  |

## Symfony integration (optional)

The package ships a thin, optional bundle. The core library itself knows nothing about
Symfony and works in any PHP project.

```php
// config/bundles.php
return [
    // …
    RonanLenouvel\RawPreviewExtractor\Bridge\Symfony\RawPreviewExtractorBundle::class => ['all' => true],
];
```

`RawPreviewExtractorInterface` then becomes autowirable.

## Tested cameras

**None yet** — this is the honest answer, and the reason `1.0.0` is not tagged.

Every format is covered end to end in CI against **synthetic files**: byte sequences built
in memory, carrying a real JPEG that is read back with `getimagesizefromstring()` as an
independent check. That proves the parsing is correct. It does not prove that a Canon 5D
Mark IV lays out its IFDs the way this library expects.

RAW structure varies between camera generations, and CR3 has no public specification.
Validation against real files is in progress; verified models will be listed here.

If your camera is not listed, the library may well work — it just has not been verified.

## Contributing

```bash
composer install
vendor/bin/phpunit                            # full suite
vendor/bin/phpunit --testsuite unit           # fast: forged bytes, no files
vendor/bin/phpunit --testsuite integration    # public API end to end
```

The suite runs on a clean machine with **no RAW file whatsoever**: tests build byte
sequences in memory. No camera files are committed to this repository — a RAW carries EXIF
data (serial number, sometimes GPS), and a committed binary stays in git history forever.

Real files used for manual validation live in `tests/Fixtures/local/`, which is git-ignored.

## License

MIT — see [LICENSE](LICENSE).

`exiftool` and `libraw` were used as behavioural references only. No code was copied from
either project.

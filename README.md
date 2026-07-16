# Raw Preview Extractor

Extract the JPEG preview embedded in camera RAW files — **pure PHP, no external binaries**.

[![CI](https://github.com/ronan-develop/raw-preview-extractor/actions/workflows/ci.yml/badge.svg)](https://github.com/ronan-develop/raw-preview-extractor/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

> **Status: work in progress.** The public API below is the target design; nothing is
> released yet. Do not use in production until `1.0.0` is tagged.

## Why

Displaying a thumbnail for a RAW file normally means decoding it, which requires `libraw`
or Imagick — unavailable on shared hosting without root access.

Almost every modern camera already embeds a full JPEG preview inside its RAW files. This
library **locates and extracts that JPEG** by reading the container structure. No
demosaicing, no sensor data, no dependencies: just byte reading.

## Supported formats

| Format | Container | Status |
| --- | --- | --- |
| CR2 (Canon) | TIFF 6.0 | planned |
| CR3 (Canon) | ISO-BMFF | planned |
| NEF (Nikon) | TIFF 6.0 | planned |
| ARW (Sony) | TIFF 6.0 | planned |
| DNG (Adobe) | TIFF 6.0 | planned |

RAF (Fuji) and ORF (Olympus) are planned for v2.

## Requirements

PHP >= 8.2. That's it — the `require` section depends on nothing else.

## Installation

```bash
composer require ronanlenouvel/raw-preview-extractor
```

## Usage

```php
use RonanLenouvel\RawPreviewExtractor\Exception\RawPreviewExtractorException;

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

| Exception | Meaning |
| --- | --- |
| `UnsupportedFormatException` | not a supported RAW file |
| `PreviewNotFoundException` | valid file, but no embedded JPEG preview |
| `CorruptedFileException` | unreadable or structurally invalid file |

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

Extraction is validated against synthetic fixtures in CI, and manually against real files
from the cameras listed here. RAW structure varies between camera generations — if yours
is not listed, it may still work, but it has not been verified.

_(To be filled as validation progresses.)_

## Contributing

```bash
composer install
vendor/bin/phpunit                     # full suite
vendor/bin/phpunit --testsuite unit    # fast, no fixtures
```

The test suite runs without any RAW file: tests build byte sequences in memory. No camera
files are committed to this repository.

## License

MIT — see [LICENSE](LICENSE).

`exiftool` and `libraw` were used as behavioural references only. No code was copied from
either project.

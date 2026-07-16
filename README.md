# Raw Preview Extractor

Extract the JPEG preview embedded in camera RAW files — **pure PHP, no external binaries**.

[![CI](https://github.com/ronan-develop/raw-preview-extractor/actions/workflows/ci.yml/badge.svg)](https://github.com/ronan-develop/raw-preview-extractor/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

> **Status: feature-complete and validated against real camera files** (see
> [Tested cameras](#tested-cameras)). Not yet tagged `1.0.0` — the public API is settled,
> but a released version is a semver commitment, and more cameras are worth testing first.

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

Processing a directory — the whole library in one loop:

```php
$extractor = RawPreviewExtractor::createDefault();

foreach (glob('/photos/*') as $path) {
    try {
        $preview = $extractor->extract($path);
        file_put_contents("/thumbs/" . basename($path) . '.jpg', $preview->jpegData);
    } catch (RawPreviewExtractorException) {
        continue;  // not a RAW, no preview, or corrupt — skip it
    }
}
```

Extraction is O(1) in file size: a 30 MB RAW takes the same ~2 ms as a 7 MB one, because
the file is never loaded — only its container structure is read, then the JPEG block is
seeked to directly.

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

Verified against real files from [raw.pixls.us](https://raw.pixls.us/) (CC0):

All five formats are verified against real camera files:

| Camera                 | Year | Format | File  | Preview extracted   |
|------------------------|------|--------|-------|---------------------|
| Canon EOS 5D           | 2005 | CR2    | 13 MB | 2496×1664 — 1656 KB |
| Canon EOS 5D Mark II   | 2008 | CR2    | 27 MB | 5616×3744 — 1980 KB |
| Canon 5D Mark II sRAW1 | 2008 | CR2    | 15 MB | 5616×3744 — 1973 KB |
| Canon EOS 5D Mark IV   | 2016 | CR2    | 62 MB | 6720×4480 — 2047 KB |
| Canon EOS R            | 2018 | CR3    | 30 MB | 1620×1080 — 228 KB  |
| Canon EOS RP           | 2019 | CR3    | 7 MB  | 1620×1080 — 328 KB  |
| Nikon D750             | 2014 | NEF    | 25 MB | 6016×4016 — 952 KB  |
| Sony α7 (ILCE-7)       | 2013 | ARW    | 24 MB | 1616×1080 — 460 KB  |
| Apple iPhone 12 Pro    | 2020 | DNG    | 29 MB | 4032×3024 — 5239 KB |

Four generations of the same Canon 5D line — 2005 to 2016, 12 to 30 megapixels — are
covered deliberately: RAW layout drifts between generations, and that drift is where
format parsers break.

### Wider audit

Beyond the table above, the library is audited against the whole
[raw.pixls.us](https://raw.pixls.us/) catalogue — 400+ camera models:

```bash
php bin/audit-cameras.php Canon 25    # 25 Canon models, spread across the range
php bin/audit-cameras.php all 500     # everything
```

It downloads each file, extracts, verifies with `imagecreatefromstring()`, then deletes it.
Latest Canon run — **every EOS body passed**:

```text
EOS-1D X Mark II  EOS 5D Mark IV  EOS 30D    EOS 100D   EOS 500D   EOS 1000D
EOS R5            EOS R100        EOS RP     EOS M6     EOS Kiss M EOS Rebel T5
PowerShot G12     PowerShot G1 X Mark II     PowerShot SX1 IS      PowerShot V1
```

The remaining failures are compacts whose files genuinely **contain no JPEG preview** —
CHDK-generated DNGs holding an uncompressed 128×96 thumbnail, or legacy `.CRW` files
(pre-CR2, out of scope). `PreviewNotFoundException` is the correct answer there, and the
audit counts it as an expected outcome rather than a defect.

Every preview above is checked with `imagecreatefromstring()` — not just decoded headers,
but actually opened. Extraction takes **1–9 ms** on files up to 62 MB: the file is never
loaded into memory, only the container structure is read before seeking to the JPEG block.

RAW layout varies between camera generations, and CR3 has no public specification. If your
camera is not listed, the library may well work — it just has not been verified. You can
check in one command: see [Trying it on your own RAW files](#trying-it-on-your-own-raw-files).

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

### Trying it on your own RAW files

Synthetic tests prove the parsing is correct; they cannot prove your camera lays out its
IFDs the way this library expects. To check for yourself:

```bash
mkdir -p tests/Fixtures/local        # git-ignored
cp ~/photos/IMG_0042.CR2 tests/Fixtures/local/

php bin/extract-local.php
```

```text
FICHIER                      FORMAT       DIMENSIONS     TAILLE   DURÉE
──────────────────────────────────────────────────────────────────────────
✅ nikon-d750.nef            NEF           6016x4016     952 Ko     2 ms
✅ canon-eos-r.cr3           CR3           1620x1080     228 Ko     1 ms
```

Extracted previews are written to `tests/Fixtures/local/output/` — **open them**. A JPEG
that decodes is not necessarily the right JPEG: this library once returned a valid 160×120
thumbnail where a 1620×1080 preview was expected, and every test was green.

Neither the RAW files nor the extracted previews are committed. The script itself lives in
`bin/`, excluded from the published archive by `.gitattributes`.

Free CC0 sample files for most cameras: [raw.pixls.us](https://raw.pixls.us/).

## License

MIT — see [LICENSE](LICENSE).

`exiftool` and `libraw` were used as behavioural references only. No code was copied from
either project.

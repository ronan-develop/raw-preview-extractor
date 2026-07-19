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

### Orientation

A preview is stored exactly as the sensor captured it: a shot taken in portrait comes out
**lying on its side**. The camera records the rotation rather than applying it.

This library does not rotate the image — that would need GD, the very dependency it exists
to avoid. It tells you what the file says:

```php
$preview->orientation;                  // Orientation::Rotate90
$preview->orientation->degrees();       // 90
$preview->orientation->isUpright();     // false
$preview->orientation->isMirrored();    // false — 4 of the 8 EXIF values are mirrors
$preview->orientation->swapsDimensions();  // true — width and height swap on a quarter turn
```

In a browser, fixing it costs nothing:

```php
printf('<img src="thumb.jpg" style="transform: rotate(%ddeg)">',
    $preview->orientation->degrees());
```

Server-side, if you do have GD — note it rotates counter-clockwise:

```php
$image = imagecreatefromstring($preview->jpegData);

if (!$preview->orientation->isUpright()) {
    $image = imagerotate($image, -$preview->orientation->degrees(), 0);
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

### Metadata

Alongside the preview, the extractor reads the **shooting settings** from the RAW's EXIF —
what a photographer wants next to the image: when it was taken, and how.

```php
$preview = $extractor->extract('/path/to/photo.cr2');

$meta = $preview->metadata;              // RawMetadata|null

$meta?->dateTimeOriginal;                // "2024:06:15 12:30:45" (raw EXIF form)
$meta?->fNumber;                         // 2.8   (aperture, float)
$meta?->exposureTime;                    // "1/250" (shutter, fraction kept intact)
$meta?->iso;                             // 400
$meta?->focalLength;                     // 50.0  (millimetres)
$meta?->lensModel;                       // "RF50mm F1.8 STM"
$meta?->cameraMake;                      // "Canon"
$meta?->cameraModel;                     // "Canon EOS R5"
```

Every field is nullable, and so is `$preview->metadata` itself: a tag may be absent, an old
body says less than a recent one, and a partial read is never a failure. `RawMetadata::isEmpty()`
tells a caller nothing usable was found, in one call:

```php
if ($meta !== null && !$meta->isEmpty()) {
    // display the shooting settings
}
```

Values are exposed exactly as the file encodes them — no rounding, no locale: the shutter
speed keeps its fraction (`"1/250"` reads better than `0.004`), the aperture stays a plain
number. Metadata is read from the same container walk as the preview, so it costs nothing
extra.

> Coverage follows the TIFF-based formats (CR2, NEF, ARW, DNG), which carry EXIF in a
> standard IFD. For CR3 (ISO-BMFF) the preview is returned but `metadata` is currently `null`.

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

Beyond the table above, the library is audited against the
[raw.pixls.us](https://raw.pixls.us/) catalogue — 400+ camera models, CC0 files:

```bash
php bin/audit-cameras.php Canon 25    # 25 Canon models, spread across the range
php bin/audit-cameras.php all 500     # everything
```

Each file is downloaded, extracted, verified with `imagecreatefromstring()`, then deleted.
Latest run — **72 models across the four brands in scope**:

| Brand | Passed | Notes                            |
|-------|--------|----------------------------------|
| Sony  | 23/23  | 100 % — A290 (2010) through FX30 |
| Nikon | 20/22  | 90.9 %                           |
| Apple | 5/6    | 83.3 %                           |
| Canon | 16/21  | 76.2 % — every EOS body passed   |

**Every failure was verified by hand, and every one is correct**: those files contain no
JPEG preview at all (zero `FFD8` bytes in the entire file). A Nikon D1H from 2001 carries an
uncompressed 160×120 RGB thumbnail; CHDK-generated compact DNGs do the same; legacy `.CRW`
files predate CR2 and are out of scope. `PreviewNotFoundException` is the right answer, so
the real success rate on files that *have* a preview is 100 %.

The full list is at the [bottom of this page](#appendix-models-verified-against-real-files).

Every preview above is checked with `imagecreatefromstring()` — not just decoded headers,
but actually opened. Extraction takes **1–9 ms** on files up to 62 MB: the file is never
loaded into memory, only the container structure is read before seeking to the JPEG block.

RAW layout varies between camera generations, and CR3 has no public specification. If your
camera is not listed, the library may well work — it just has not been verified. You can
check in one command: see [Trying it on your own RAW files](#trying-it-on-your-own-raw-files).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for conventions, the TDD workflow, and the binary
parsing rules. In short:

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

---

## Appendix: models verified against real files

Every model below had a real file downloaded, its preview extracted, and the result opened
with `imagecreatefromstring()`. Sources: [raw.pixls.us](https://raw.pixls.us/) (CC0).

**Canon** — CR2 & CR3

```text
EOS 5D            EOS 5D Mark II    EOS 5D Mark II sRAW1   EOS 5D Mark IV
EOS-1D X Mark II  EOS 30D           EOS 100D               EOS 500D
EOS 1000D         EOS Rebel T5      EOS M6                 EOS Kiss M
EOS R             EOS RP            EOS R5                 EOS R100
PowerShot G12     PowerShot G1 X Mark II                   PowerShot SX1 IS
PowerShot V1
```

**Nikon** — NEF

```text
D60      D90      D300     D610     D800     D850     D2Xs     D3300
D4       D5100    D5600    D6       D7500    E8400    Z 7      Z 30
1 AW1    1 J3     1 S2     Coolpix A
```

**Sony** — ARW

```text
DSLR-A290    DSLR-A380    DSLR-A550    DSLR-A850    SLT-A35      SLT-A58
NEX-5N       NEX-7        ILCE-5000    ILCE-6100    ILCE-6600    ILCE-7S
ILCE-7M4     ILCE-7RM3A   ILCE-7CM2    ILCA-99M2    ILME-FX30    UMC-R10C
DSC-RX0      DSC-RX100M4  DSC-RX100M7  DSC-RX10M4   DSC-RX1RM2
```

**Apple** — DNG (including ProRAW)

```text
iPhone 6s Plus   iPhone 7 Plus   iPhone SE   iPhone XS   iPhone 12 Pro
```

### Known to have no preview

These models throw `PreviewNotFoundException` — **and that is the correct answer**. Their
files contain no JPEG whatsoever, which is verifiable in one command:

```bash
grep -c $'\xff\xd8\xff' some-file.dng    # 0 — not a single JPEG marker in the file
```

| Model                                           | What the file actually holds       |
|-------------------------------------------------|------------------------------------|
| Nikon D1H (2001)                                | uncompressed 160×120 RGB thumbnail |
| Nikon COOLSCAN V ED                             | film scanner output                |
| Apple iPhone 8                                  | 64 sensor tiles, no JPEG           |
| Canon PowerShot SX100 IS, SX510 HS, ELPH 130 IS | uncompressed 128×96 thumbnail      |
| Canon PowerShot G7 X, IXY 220F                  | `.CRW` — pre-CR2, out of scope     |

Two reasons, neither of them a parser bug. **The oldest bodies predate the convention**: a
D1H from 2001 stores a raw RGB thumbnail because embedding a JPEG preview was not yet
standard practice. **The compacts never had a RAW mode**: their DNGs come from
[CHDK](https://chdk.fandom.com/), an alternative firmware that writes sensor data with a
minimal thumbnail.

There is nothing to extract in those files, so throwing is right — returning something
would mean fabricating it.

**Success rate on files that actually contain a preview: 100 %.**

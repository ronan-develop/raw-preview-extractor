# Raw Preview Extractor — Documentation

Extract the JPEG preview embedded in camera RAW files, in pure PHP.

The [README](../README.md) covers installation and basic usage. This document explains how
the library works, why it works that way, and what it will not do.

## Contents

- [The problem it solves](#the-problem-it-solves)
- [API reference](#api-reference)
- [How extraction works](#how-extraction-works)
- [What the formats actually look like](#what-the-formats-actually-look-like)
- [Error handling](#error-handling)
- [Symfony integration](#symfony-integration)
- [Design decisions](#design-decisions)
- [Limits](#limits)

## The problem it solves

Displaying a thumbnail for a RAW file normally means decoding it: reading the sensor's
Bayer matrix, demosaicing, applying a colour profile. That needs `libraw` or Imagick —
neither of which can be installed on shared hosting without root access.

But almost every camera since ~2005 already embeds a **full JPEG preview** inside its RAW
files: it is what the camera shows on its own screen. It is right there, fully encoded,
waiting to be copied out.

Extracting it needs no decoding at all — only reading the container structure and copying
a byte range. That is what this library does, with `fopen`, `fread` and `unpack`, and
nothing else.

**Consequence:** extraction is O(1) in file size. A 62 MB CR2 takes the same few
milliseconds as a 7 MB CR3, because the file is never loaded into memory.

## API reference

### `RawPreviewExtractorInterface`

The only type you need.

```php
interface RawPreviewExtractorInterface
{
    public function extract(string $path): ExtractedPreview;
    public function supports(string $path): bool;
}
```

#### `extract(string $path): ExtractedPreview`

Returns the largest usable JPEG preview found in the file.

```php
use RonanLenouvel\RawPreviewExtractor\RawPreviewExtractor;

$extractor = RawPreviewExtractor::createDefault();
$preview = $extractor->extract('/photos/IMG_0042.CR2');

file_put_contents('/cache/thumb.jpg', $preview->jpegData);
```

Throws `UnsupportedFormatException`, `PreviewNotFoundException` or
`CorruptedFileException` — all implementing `RawPreviewExtractorException`.

#### `supports(string $path): bool`

Whether this file can be handled. **Never throws** — a missing or unreadable file simply
returns `false`, so it is safe to call as a guard:

```php
if ($extractor->supports($path)) {
    // …
}
```

The answer comes from the file's **binary signature**, never its extension: a CR2 renamed
to `.jpg` returns `true`, and a `.cr2` that is not one returns `false`.

Note that `supports()` reflects what the extractor can *really* do: if a format is
recognised but no parser is wired for it, the answer is `false`.

### `ExtractedPreview`

```php
final readonly class ExtractedPreview
{
    public function __construct(
        public string $jpegData,      // raw JPEG bytes, ready to write
        public int $width,
        public int $height,
        public Format $sourceFormat,
        public Orientation $orientation = Orientation::Normal,
        public ?RawMetadata $metadata = null,   // shooting settings, or null
    ) {}
}
```

`$width` and `$height` come from the **JPEG's own SOF segment**, not from container tags —
those often describe the full-resolution RAW image instead of the preview. A CR2 whose IFD
claims 4000×3000 over a 48×36 preview will report 48×36.

`$jpegData` is validated: its `FFD8` magic is checked, and its SOF marker is confirmed
decodable before it is returned.

`$metadata` carries the shooting settings read from EXIF (see below); it is `null` when the
format carries none this package can reach.

### `RawMetadata`

```php
final readonly class RawMetadata
{
    public function __construct(
        public ?string $dateTimeOriginal = null,  // "YYYY:MM:DD HH:MM:SS"
        public ?float $fNumber = null,             // aperture, e.g. 2.8
        public ?string $exposureTime = null,       // shutter, e.g. "1/250"
        public ?int $iso = null,
        public ?float $focalLength = null,         // millimetres
        public ?string $lensModel = null,
        public ?string $cameraMake = null,
        public ?string $cameraModel = null,
    ) {}

    public function isEmpty(): bool;               // true when every field is null
}
```

Every field is nullable: a tag may be absent, and a partial read is not a failure. Values
are exposed as the file encodes them — the shutter speed keeps its fraction, the aperture
stays a plain number. Read from the same container walk as the preview, so it costs nothing
extra. TIFF-based formats (CR2, NEF, ARW, DNG) are covered; CR3 returns the preview with
`metadata` currently `null`.

### `Orientation`

The camera records how it was held; it does not rotate the preview. A portrait shot
therefore comes out lying on its side — verified on an iPhone 12 Pro, which writes
`Orientation = 6`.

Rotating it here would need GD. Instead the enum carries what a caller actually needs:

| Method | Answers |
|--------|---------|
| `degrees()` | 0, 90, 180 or 270 — what `imagerotate()` and CSS `rotate()` take |
| `isUpright()` | the common case, in one check |
| `isMirrored()` | four of the eight EXIF values are mirrors, not rotations |
| `swapsDimensions()` | a quarter turn swaps width and height |

Where it is read from:

- **TIFF formats** — tag `0x0112` of the IFD0. It applies to the whole shot, previews
  included: the camera records its position once, not per image.
- **CR3** — no TIFF of its own, so it comes from the `CMT1` box, which *is* a complete
  TIFF. `TiffReader::fromRange()` reads it in place, without a temporary file.

An absent tag, or a value outside the 1-8 range, yields `Normal` rather than failing an
otherwise successful extraction.

### `Format`

```php
enum Format: string
{
    case CR2 = 'cr2';   // Canon, TIFF container
    case CR3 = 'cr3';   // Canon, ISO-BMFF container
    case NEF = 'nef';   // Nikon, TIFF
    case ARW = 'arw';   // Sony, TIFF
    case DNG = 'dng';   // Adobe, TIFF — includes Apple ProRAW
}
```

### Construction

`RawPreviewExtractor::createDefault()` wires the standard parsers — one line, for projects
without a DI container.

Under Symfony, the bundle wires the same services and that is what applies. For anything
else, the constructor takes its collaborators explicitly:

```php
new RawPreviewExtractor(
    new FormatDetector(),
    [Format::CR2->value => new TiffPreviewParser(), /* … */],
);
```

## How extraction works

```text
extract($path)
      │
      ▼
RawPreviewExtractor ──── FormatDetectorInterface ──→ Format | null
  (facade, parses                                        │
   nothing itself)                                       ▼
      │                                    map Format → PreviewParserInterface
      │                                             (injected, no switch)
      ├──────────────→ TiffPreviewParser  → TiffReader        (CR2, NEF, ARW, DNG)
      └──────────────→ Cr3PreviewParser   → IsoBmffBoxReader  (CR3)
                                    │
                                    ▼
                            ExtractedPreview
```

Two layers, deliberately separate:

**Low-level readers** (`TiffReader`, `IsoBmffBoxReader`) know the container format and
nothing about previews. `TiffReader` can walk an IFD chain; it has no idea what a preview
is. This is what makes them testable without any RAW file.

**Parsers** (`TiffPreviewParser`, `Cr3PreviewParser`) know preview semantics and delegate
every byte read to the layer below.

### The strategy: look everywhere, keep the largest *usable* one

A RAW carries several previews — a 160×120 thumbnail, a medium one, sometimes a
full-resolution one. Rather than hard-coding each manufacturer's layout (which drifts
between generations), the TIFF parser walks **every** IFD, including sub-IFDs via
`SubIFDs` and the EXIF IFD, collects every JPEG candidate, and keeps the largest that is
actually decodable.

That last qualifier matters — see below.

## What the formats actually look like

Everything here was observed on real files, not read in a specification. It is documented
because it explains why the code does what it does.

### NEF (Nikon D750, 25 MB)

| IFD | Content |
|-----|---------|
| IFD0 | 160×120 thumbnail, `Compression = 1` — **not compressed, not a JPEG** |
| SubIFD0 | preview JPEG, 974 KB ← the one we keep |
| SubIFD1 | **sensor data**, `Compression = 34713` (Nikon compressed), 24 MB |
| SubIFD2 | preview JPEG, 745 KB |

The main IFD chain contains **one entry**. A parser that ignores `SubIFDs` finds nothing.

### CR2 (Canon 5D Mark IV, 62 MB)

| IFD | Compression | Size | Nature |
|-----|-------------|------|--------|
| **IFD0** | 6 | 2 MB | the real preview (SOF0 baseline) |
| IFD3 | 6 | 30 MB | **sensor data, lossless JPEG (SOF3)** |
| IFD4 | 6 | 28 MB | **sensor data, lossless JPEG (SOF3)** |

Canon stores sensor data as **lossless JPEG** and marks it `Compression = 6` — honestly,
since it *is* JPEG. But no browser and no GD can decode it.

`Compression` therefore cannot distinguish a preview from sensor data. The **SOF marker**
can: across every camera tested, previews use SOF0; only CR2 sensor data uses SOF3. The
parser accepts SOF0, SOF1 and SOF2 (progressive) and skips the rest.

Beware when diagnosing this: `getimagesizefromstring()` **accepts** a lossless JPEG — it
only reads the header. Only `imagecreatefromstring()` reveals the failure.

### CR3 (Canon EOS R, 30 MB)

```text
ftyp                                        brand 'crx '
moov
└── uuid 85c0b687-820f-11e0-…               Canon metadata
    ├── CMT1 … CMT4                         EXIF (TIFF IFDs, incidentally)
    └── THMB                                thumbnail, ~15 KB
uuid eaf42b5e-1c98-4b88-…                   AT THE ROOT, not under moov
└── [8 proprietary bytes]
    └── PRVW                                the preview, ~230 KB
mdat                                        sensor data
```

`PRVW` and `THMB` live under **different UUIDs at different levels**. Searching both under
the Canon UUID finds only the thumbnail — a bug this library shipped with until real files
proved otherwise.

The `uuid` box holding `PRVW` inserts **8 proprietary bytes** between the UUID and its
first child box. Their size is documented nowhere, so the parser locates the box by type
rather than by a hard-coded offset.

CR3 has **no public specification**. Everything above comes from reverse-engineering real
files, and may be incomplete for cameras not yet tested.

## Error handling

Every exception implements `RawPreviewExtractorException`, so one `catch` is enough:

```php
foreach (glob('/photos/*') as $path) {
    try {
        $preview = $extractor->extract($path);
        file_put_contents("/thumbs/" . basename($path) . '.jpg', $preview->jpegData);
    } catch (RawPreviewExtractorException) {
        continue;  // not a RAW, no preview, or corrupt
    }
}
```

| Exception | When |
|-----------|------|
| `UnsupportedFormatException` | signature not recognised, or no parser wired for that format |
| `PreviewNotFoundException` | valid file, no usable preview |
| `CorruptedFileException` | unreadable or structurally invalid |

### The distinction that matters

**A truncated file is corrupt. A tag that lies about its content is not.**

RAW files routinely declare `Compression = 6` over data that is not a decodable JPEG.
That candidate is skipped and the next one tried — it is not fatal, and the file is
usually perfectly readable. `CorruptedFileException` is reserved for structural
invalidity: an offset outside the file, a truncated IFD, a value larger than the file
containing it.

Getting this backwards makes the library reject files it should read.

## Symfony integration

The bundle is optional and does nothing but wire services. The library is identical
without it.

```php
// config/bundles.php
return [
    RonanLenouvel\RawPreviewExtractor\Bridge\Symfony\RawPreviewExtractorBundle::class => ['all' => true],
];
```

`RawPreviewExtractorInterface` then becomes autowirable:

```php
public function __construct(
    private readonly RawPreviewExtractorInterface $extractor,
) {}
```

Only the interface is public. The parsers and the detector are private services — they are
implementation details, and exposing them would freeze our freedom to refactor.

Supported: Symfony 6.4 (LTS), 7.x and 8.x. Composer picks whichever matches your app.

## Design decisions

**Zero runtime dependencies.** `require` contains `"php": ">=8.2"` and nothing else. This
is the whole point: the library exists for environments where you cannot install
anything. GD is used in tests only — to build JPEGs and verify output — never to extract.

**`type: library`, not `symfony-bundle`.** The core knows nothing about Symfony and works
in Laravel or plain PHP. The bundle is a convenience, declared in `suggest`.

**No per-model code.** There is no `if (5D Mark II)` anywhere and there should never be.
Every quirk found across 64 camera models was handled by making a rule *more general* —
checking the SOF marker rather than `Compression`, bounding by `filesize()` rather than a
constant. Each fix made the code shorter.

**Format resolution through an injected map.** The facade holds no `switch`. Adding RAF or
ORF means adding an enum case, a parser, and a map entry — no existing code changes. If a
new format forces a change to `RawPreviewExtractor`, the design has failed.

**The file is untrusted input.** Every offset is validated against `filesize()` before
use; `unpack()` is never called on unverified lengths; IFD chains and box trees have loop
guards, because a corrupt or hostile file can point a structure at itself.

## Limits

**Formats.** CR2, CR3, NEF, ARW, DNG. RAF (Fuji), ORF (Olympus) and CRW (pre-CR2 Canon)
are not supported.

**Files with no preview.** Some RAWs genuinely contain no JPEG: a Nikon D1H from 2001
stores a raw RGB thumbnail because embedding a JPEG was not yet standard practice;
CHDK-generated DNGs from compacts do the same. `PreviewNotFoundException` is the correct
answer — there is nothing to extract.

**Preview size is the camera's choice.** A Nikon D750 embeds a 6016×4016 preview; a Canon
EOS R embeds 1620×1080. This library returns what is there, at full size. If you need a
thumbnail, resize it — that is GD's job, not ours.

**No decoding, ever.** If a RAW has no embedded preview, this library cannot make one.
That would require demosaicing the sensor, which is precisely what it exists to avoid.

## See also

- [README](../README.md) — installation, quick start, tested cameras
- [CONTRIBUTING](../CONTRIBUTING.md) — conventions, TDD workflow, parsing rules

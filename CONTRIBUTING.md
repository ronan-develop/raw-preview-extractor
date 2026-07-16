# Contributing

Thanks for considering a contribution. This document describes how this project actually
works — the conventions below are enforced by git hooks and CI, not by good will.

## The one rule that shapes everything

**Zero runtime dependencies.** The `require` section of `composer.json` contains exactly
one entry: `"php": ">=8.2"`.

This is not minimalism for its own sake. The library exists because decoding a RAW needs
`libraw` or Imagick, which are unavailable on shared hosting without root — and that is the
target. Extracting an already-embedded JPEG needs nothing but byte reading.

If a change seems to require a runtime dependency, it is out of scope. Symfony, PHPUnit and
GD stay in `require-dev`.

Two corollaries:

- **GD is never used to extract.** It builds JPEGs in tests and verifies output with
  `imagecreatefromstring()` — never in `src/`.
- **Nothing under `src/` imports `Symfony\`** except `src/Bridge/Symfony/`, which is DI
  wiring only. Verify with:
  
  ```bash
  grep -rl 'Symfony\\' src/ --include='*.php' | grep -v '^src/Bridge/'   # must be empty
  ```

## Setup

```bash
git clone https://github.com/ronan-develop/raw-preview-extractor.git
cd raw-preview-extractor
composer install

vendor/bin/phpunit                            # full suite
vendor/bin/phpunit --testsuite unit           # fast: forged bytes, no files
vendor/bin/phpunit --testsuite integration    # public API end to end
```

The suite runs on a clean machine **with no RAW file whatsoever**. If a test needs one, the
test is wrong.

Optional but recommended — the commit hooks:

```bash
git config core.hooksPath .githooks
```

They reject commits whose message breaks convention, and any staged RAW or binary over 1 MB.

## Test-driven, strictly

**No line of `src/` is written before a failing test** — including boilerplate.

1. Write the test. Run it. **See it fail** — an assumed red is not a red.
2. Write the minimum to pass.
3. Refactor green.

Test and implementation go in the same commit. A commit never leaves the suite red.

### Tests use forged bytes, not fixtures

No camera file is committed to this repository. A RAW carries EXIF (serial number,
sometimes GPS), and a committed binary stays in git history forever.

Tests build byte sequences in memory — a TIFF header is 8 bytes, an ISO-BMFF box is 8 bytes:

```php
$header = "II" . pack('v', 42) . pack('V', 8);   // little-endian TIFF, IFD at offset 8
```

This tests *better* than real files: it exercises the looping IFD, the out-of-bounds offset,
the `size == 0` box — cases no real camera produces.

### But forged bytes prove less than you think

**Reproduce the real structure, not a simplified one.** Every bug found in this project so
far was invisible to a green test suite:

| Bug                                 | Why tests missed it                                                     |
|-------------------------------------|-------------------------------------------------------------------------|
| `findUuid()` searched only the root | Tests put the UUID box at the root. Real CR3s nest it under `moov`.     |
| Facade failed on a real CR2         | Every facade test used mocks; none assembled the real parts.            |
| CR2 returned 28 MB of sensor data   | Canon marks lossless JPEG as `Compression = 6`; the test files did not. |
| G12 raised on a valid file          | No test had a candidate that *lied* about its content.                  |

A test written from a misunderstanding reproduces the misunderstanding. Before calling a
change done, exercise the public API on a real file (see below).

## Coverage: ≥ 95 %, enforced

CI fails below the threshold and lists the uncovered lines. Before adding a test to satisfy
it, **ask whether the line deserves to exist**:

- **Unreachable** → delete it. Most uncovered lines in this project were dead guards:
  `fopen()` checked after `is_file()`, `strlen()` after an already-bounded `fread()`,
  `unpack()` guarded when length was guaranteed. A guard that cannot fire is not
  defence-in-depth — it is noise that fakes robustness.
- **Reachable, guards a real scenario** (hostile file: looping IFD, absurd `count`,
  out-of-bounds offset) → write the test.
- **Hard to reach because of the design** → fix the design. The threshold once revealed
  that `IfdEntry` needed a `TiffReader` to read its own values — a cursor disguised as a
  value object.

Coverage went from 83 % to 99 % here mostly by *deleting* code. Never add an artificial test
to move the number.

## Binary parsing rules

The file is untrusted input. These are not suggestions:

- **Never `unpack()`** without checking the byte length first — `fread` near EOF silently
  returns less than asked.
- Every offset read from the file is validated against `filesize()` before use.
- **Bound by `filesize()`, never by an arbitrary constant.** A 64 KB cap rejected a valid
  Canon 5D whose `MakerNote` is 75 KB. A value cannot exceed the file containing it — that
  is the only bound that means anything.
- Endianness is carried explicitly: `v`/`V` (little) or `n`/`N` (big). **Never `S`/`L`** —
  they follow the CPU and make parsing machine-dependent.
- Loop guards on IFD chains and box trees: a corrupt or hostile file can point a structure
  at itself.
- Validate the `FFD8` magic before returning any JPEG.

### Corrupt is not the same as missing

| Exception | When |
| --- | --- |
| `CorruptedFileException` | structurally invalid: out-of-bounds offset, truncated IFD, value larger than the file |
| `PreviewNotFoundException` | valid file, no usable preview — including a tag that lies about its content |
| `UnsupportedFormatException` | not a supported RAW |

A truncated file is *corrupt*. A tag claiming `Compression = 6` over raw sensor data is
*common in RAWs* — that candidate is skipped, not fatal. Getting this backwards makes the
library reject files it should read.

All exceptions implement `RawPreviewExtractorException`, so callers degrade with one
`catch`. That contract is tested.

## Adding a format

The design intends this to require **no change to existing code**:

1. Add a case to the `Format` enum.
2. Add a `PreviewParserInterface` implementation.
3. Wire it in `RawPreviewExtractor::createDefault()` and in the bundle's `services.php`.

The facade resolves through an injected `Format → parser` map — no `switch`, no `match` on
extensions. **If adding a format forces you to touch `RawPreviewExtractor`, the design has
failed** — say so rather than working around it.

There is no per-model code anywhere, and there should never be. Every camera quirk found so
far was fixed by making a rule *more general*, never by adding a special case.

## Validating against real files

```bash
mkdir -p tests/Fixtures/local        # git-ignored
cp ~/photos/IMG_0042.CR2 tests/Fixtures/local/

php bin/extract-local.php            # writes previews to tests/Fixtures/local/output/
```

**Open the output.** A JPEG that decodes is not necessarily the right JPEG — this library
once returned a valid 160×120 thumbnail where a 1620×1080 preview was expected, with every
test green.

Note that `getimagesizefromstring()` is not enough: it reads the header only and happily
accepts a lossless JPEG that no decoder can open. Use `imagecreatefromstring()`.

To audit against the public catalogue (400+ models, CC0 files):

```bash
php bin/audit-cameras.php Canon 25
php bin/audit-cameras.php all 500
```

Each file is downloaded, tested, then deleted. Failures are the useful output — each one
points at a structure the parser does not yet handle.

## Commits

```text
<emoji> <type>(<scope>): <subject>
```

| Emoji | Type | Emoji | Type |
| --- | --- | --- | --- |
| ✨ | feat | ✅ | test |
| 🔧 | fix | 🏗️ | build |
| 📖 | docs | 🏭 | ci |
| ♻️ | refactor | 🛠️ | chore |
| ⚡ | perf | 🔒 | security |

Rules the `commit-msg` hook enforces:

- emoji always present;
- **explicit scope**: a class or module name (`TiffReader`, `Cr3PreviewParser`, `composer`),
  never `feat(file)` or `fix(api)`;
- atomic commits — one responsibility each.

Explain **why** in the body, not what. The diff shows what.

## Pull requests

Branch from an issue: `<type>/#<number>-<slug>`, e.g. `fix/#27-candidate-without-magic`.
Include `Closes #<number>` in the body.

All six CI checks must pass — PHP 8.2/Symfony 6.4 at `--prefer-lowest` through PHP
8.4/Symfony 8, plus the coverage threshold.

## Semver

The public surface is `RawPreviewExtractorInterface`, `ExtractedPreview`, the `Format` enum
cases, the exception hierarchy, and the bundle's service alias.

`ExtractedPreview` is `final readonly` with promoted public properties — **everything about
it is public surface**, with no slack. Adding an enum case is minor; renaming a property or
changing which exception fires in a given case is major.

## License

MIT. `exiftool` and `libraw` were used as behavioural references only — their output was
compared, never their source read or translated.

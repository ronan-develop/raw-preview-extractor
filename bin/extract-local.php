<?php

declare(strict_types=1);

/**
 * Extrait les previews des RAW de tests/Fixtures/local/ vers .../local/output/.
 *
 * Outil de validation locale : il sert à **voir** ce que la librairie produit
 * sur de vrais fichiers, ce qu'aucun test automatisé ne montre. Ni le script ni
 * ses entrées/sorties ne sont versionnés (cf. .gitignore).
 *
 * Usage : php bin/extract-local.php
 */

require __DIR__ . '/../vendor/autoload.php';

use RonanLenouvel\RawPreviewExtractor\Exception\RawPreviewExtractorException;
use RonanLenouvel\RawPreviewExtractor\RawPreviewExtractor;

$inputDir = __DIR__ . '/../tests/Fixtures/local';
$outputDir = $inputDir . '/output';

if (!is_dir($inputDir)) {
    fwrite(STDERR, "❌ {$inputDir} n'existe pas.\n");
    exit(1);
}

@mkdir($outputDir, 0o755, true);

$files = array_filter(
    glob($inputDir . '/*') ?: [],
    static fn (string $p): bool => is_file($p)
        && !str_contains($p, '/output/')
        && !str_ends_with(strtolower($p), '.jpg'),
);

if ([] === $files) {
    echo "Aucun RAW dans {$inputDir}.\n";
    echo "Dépose-y tes fichiers (ils sont git-ignorés), puis relance.\n";
    exit(0);
}

$extractor = RawPreviewExtractor::createDefault();
$ok = 0;
$failed = 0;

printf("%-28s %-12s %-11s %9s %8s\n", 'FICHIER', 'FORMAT', 'DIMENSIONS', 'TAILLE', 'DURÉE');
echo str_repeat('─', 74), "\n";

foreach ($files as $path) {
    $name = basename($path);
    $start = microtime(true);

    try {
        $preview = $extractor->extract($path);
        $elapsed = (microtime(true) - $start) * 1000;

        $target = $outputDir . '/' . pathinfo($name, PATHINFO_FILENAME) . '.jpg';
        file_put_contents($target, $preview->jpegData);

        printf(
            "✅ %-25s %-12s %5dx%-5d %6.0f Ko %5.0f ms\n",
            $name,
            $preview->sourceFormat->name,
            $preview->width,
            $preview->height,
            strlen($preview->jpegData) / 1024,
            $elapsed,
        );

        // Validation croisée : GD relit-il ce que nous avons extrait ?
        if (false === @getimagesizefromstring($preview->jpegData)) {
            echo "   ⚠️  GD ne parvient pas à relire ce JPEG\n";
        }

        ++$ok;
    } catch (RawPreviewExtractorException $e) {
        printf("❌ %-25s %s\n", $name, (new ReflectionClass($e))->getShortName());
        printf("   %s\n", $e->getMessage());
        ++$failed;
    }
}

echo str_repeat('─', 74), "\n";
printf("%d extraite(s), %d échec(s) — vignettes dans %s\n", $ok, $failed, $outputDir);

exit($failed > 0 ? 1 : 0);

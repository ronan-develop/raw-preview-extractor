<?php

declare(strict_types=1);

/**
 * Fait échouer la CI si la couverture de lignes passe sous le seuil.
 *
 * PHPUnit affiche la couverture mais n'échoue jamais dessus : sans ce contrôle,
 * l'exigence « >= 95 % » du plan resterait décorative.
 *
 * Usage : php bin/coverage-check.php <clover.xml> <seuil>
 */

$cloverPath = $argv[1] ?? 'coverage.xml';
$threshold = (float) ($argv[2] ?? 95.0);

if (!is_file($cloverPath)) {
    fwrite(STDERR, "❌ Rapport clover introuvable : {$cloverPath}\n");
    exit(1);
}

$xml = @simplexml_load_file($cloverPath);

if (false === $xml) {
    fwrite(STDERR, "❌ Rapport clover illisible : {$cloverPath}\n");
    exit(1);
}

$metrics = $xml->project->metrics ?? null;

if (null === $metrics) {
    fwrite(STDERR, "❌ Aucune métrique dans le rapport — la suite a-t-elle tourné ?\n");
    exit(1);
}

$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];

// Zéro instruction = filtre de source vide ou aucun test : jamais un succès.
if (0 === $statements) {
    fwrite(STDERR, "❌ Aucune instruction mesurée — src/ est-il vide ?\n");
    exit(1);
}

$percent = $covered / $statements * 100;

printf("Couverture de lignes : %.2f %% (%d/%d) — seuil %.2f %%\n",
    $percent, $covered, $statements, $threshold);

if ($percent + 0.005 < $threshold) {
    printf("❌ Sous le seuil de %.2f %%.\n", $threshold);
    exit(1);
}

echo "✅ Seuil respecté.\n";
exit(0);

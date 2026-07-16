<?php

declare(strict_types=1);

/**
 * Audite le package contre le catalogue public de raw.pixls.us.
 *
 * Aucun code n'est spécifique à un modèle : cet outil sert à **découvrir** les
 * structures que le parseur ne sait pas encore lire, pas à en énumérer les cas
 * particuliers. Un échec est une invitation à généraliser une règle, jamais à
 * ajouter un `if`.
 *
 * Rien n'est conservé : chaque fichier est téléchargé, testé, puis supprimé.
 * Seul le rapport reste — les échecs, surtout, qui désignent une structure que
 * le parseur ne sait pas encore lire.
 *
 * Usage :
 *   php bin/audit-cameras.php                    # 3 modèles par marque
 *   php bin/audit-cameras.php Canon 20           # 20 modèles Canon
 *   php bin/audit-cameras.php all 500            # tout le catalogue
 */

require __DIR__ . '/../vendor/autoload.php';

use RonanLenouvel\RawPreviewExtractor\Exception\RawPreviewExtractorException;
use RonanLenouvel\RawPreviewExtractor\RawPreviewExtractor;

const BASE = 'https://raw.pixls.us/data/';

/** Extensions des formats du périmètre. */
const EXTENSIONS = ['cr2', 'cr3', 'nef', 'arw', 'dng'];

/**
 * Un fichier tronqué produit de **faux échecs** : le parseur refuse à juste
 * titre un offset qui sort des bornes, et l'audit le compte comme un bug.
 *
 * On télécharge donc les fichiers entiers. C'est plus lent, mais un auditeur
 * qui ment sur ses échecs ne sert à rien — chaque faux positif coûte plus cher
 * en enquête que la bande passante économisée.
 */
const PARTIAL_BYTES = 0;

$brandArg = $argv[1] ?? 'default';
$limit = (int) ($argv[2] ?? 3);

$brands = match ($brandArg) {
    'default' => ['Canon', 'Nikon', 'Sony', 'Apple', 'FUJIFILM', 'Panasonic'],
    'all' => listDirectories(BASE),
    default => [$brandArg],
};

$extractor = RawPreviewExtractor::createDefault();
$tmp = sys_get_temp_dir() . '/rpe-audit';
@mkdir($tmp, 0o755, true);

$results = [];
$stats = ['ok' => 0, 'failed' => 0, 'skipped' => 0];

foreach ($brands as $brand) {
    $models = listDirectories(BASE . rawurlencode($brand) . '/');

    if ([] === $models) {
        continue;
    }

    // Échantillon réparti sur toute la gamme plutôt que les N premiers
    // alphabétiquement : on veut couvrir des générations différentes.
    $models = spread($models, $limit);

    fprintf(STDERR, "\n── %s (%d modèles) ──\n", $brand, count($models));

    foreach ($models as $model) {
        $file = firstRawFile(BASE . rawurlencode($brand) . '/' . rawurlencode($model) . '/');

        if (null === $file) {
            ++$stats['skipped'];

            continue;
        }

        $url = BASE . rawurlencode($brand) . '/' . rawurlencode($model) . '/' . rawurlencode($file);
        $path = $tmp . '/' . preg_replace('/[^\w.-]/', '_', $file);

        if (!download($url, $path)) {
            ++$stats['skipped'];

            continue;
        }

        $results[] = test($extractor, $brand, $model, $path, $stats);
        @unlink($path);
    }
}

report($results, $stats);

@rmdir($tmp);

exit($stats['failed'] > 0 ? 1 : 0);

/**
 * Teste un fichier et rend une ligne de rapport.
 *
 * @param array{ok: int, failed: int, skipped: int} $stats
 *
 * @return array{brand: string, model: string, status: string, detail: string}
 */
function test(RawPreviewExtractor $extractor, string $brand, string $model, string $path, array &$stats): array
{
    try {
        $preview = $extractor->extract($path);

        // Le contrôle qui compte : un en-tête valide ne prouve rien, seule
        // l'ouverture réelle prouve que la preview est exploitable.
        if (false === @imagecreatefromstring($preview->jpegData)) {
            ++$stats['failed'];
            fwrite(STDERR, "  ❌ {$model} — JPEG non décodable\n");

            return ['brand' => $brand, 'model' => $model, 'status' => 'undecodable',
                'detail' => sprintf('%dx%d', $preview->width, $preview->height)];
        }

        ++$stats['ok'];
        $detail = sprintf('%dx%d, %d Ko', $preview->width, $preview->height, strlen($preview->jpegData) / 1024);
        fwrite(STDERR, "  ✅ {$model} — {$detail}\n");

        return ['brand' => $brand, 'model' => $model, 'status' => 'ok', 'detail' => $detail];
    } catch (RawPreviewExtractorException $e) {
        ++$stats['failed'];
        $type = (new ReflectionClass($e))->getShortName();
        fwrite(STDERR, "  ❌ {$model} — {$type}: {$e->getMessage()}\n");

        return ['brand' => $brand, 'model' => $model, 'status' => $type, 'detail' => $e->getMessage()];
    }
}

/**
 * @param list<array{brand: string, model: string, status: string, detail: string}> $results
 * @param array{ok: int, failed: int, skipped: int}                                 $stats
 */
function report(array $results, array $stats): void
{
    $total = $stats['ok'] + $stats['failed'];

    echo "\n", str_repeat('═', 72), "\n";
    printf("%d testés — %d réussis (%.1f %%), %d échecs, %d ignorés\n",
        $total, $stats['ok'], $total > 0 ? $stats['ok'] / $total * 100 : 0,
        $stats['failed'], $stats['skipped']);
    echo str_repeat('═', 72), "\n";

    $failures = array_filter($results, static fn (array $r): bool => 'ok' !== $r['status']);

    if ([] === $failures) {
        echo "\nAucun échec.\n";

        return;
    }

    // Les échecs sont la seule information utile : ils désignent une structure
    // que le parseur ne sait pas encore lire.
    echo "\nÉCHECS — chacun est une règle à généraliser :\n\n";

    foreach ($failures as $f) {
        printf("  %-10s %-28s %s\n", $f['brand'], $f['model'], $f['status']);
        printf("  %-10s %-28s %s\n", '', '', substr($f['detail'], 0, 60));
    }
}

/**
 * Sous-répertoires d'un index Apache.
 *
 * @return list<string>
 */
function listDirectories(string $url): array
{
    $html = fetch($url);

    if (null === $html) {
        return [];
    }

    preg_match_all('/href="([^"?\/][^"]*)\/"/', $html, $m);

    return array_values(array_map(rawurldecode(...), $m[1]));
}

/**
 * Premier fichier RAW d'un dossier modèle.
 */
function firstRawFile(string $url): ?string
{
    $html = fetch($url);

    if (null === $html) {
        return null;
    }

    preg_match_all('/href="([^"?\/][^"]*)"/', $html, $m);

    foreach ($m[1] as $name) {
        $ext = strtolower(pathinfo(rawurldecode($name), PATHINFO_EXTENSION));

        if (in_array($ext, EXTENSIONS, true)) {
            return rawurldecode($name);
        }
    }

    return null;
}

function fetch(string $url): ?string
{
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);

    return false === $body ? null : $body;
}

/**
 * Télécharge les premiers octets seulement : une preview n'est jamais loin du
 * début, et le reste du fichier est le capteur.
 */
function download(string $url, string $path): bool
{
    $headers = PARTIAL_BYTES > 0 ? 'Range: bytes=0-' . (PARTIAL_BYTES - 1) : '';

    $ctx = stream_context_create(['http' => [
        'timeout' => 180,
        'header' => $headers,
        'ignore_errors' => true,
    ]]);

    $body = @file_get_contents($url, false, $ctx);

    if (false === $body || strlen($body) < 1024) {
        return false;
    }

    file_put_contents($path, $body);

    return true;
}

/**
 * Répartit un échantillon sur toute la liste plutôt que d'en prendre la tête :
 * les N premiers modèles alphabétiques sont souvent la même génération.
 *
 * @param list<string> $items
 *
 * @return list<string>
 */
function spread(array $items, int $limit): array
{
    $count = count($items);

    if ($count <= $limit) {
        return $items;
    }

    $step = $count / $limit;
    $picked = [];

    for ($i = 0; $i < $limit; ++$i) {
        $picked[] = $items[(int) floor($i * $step)];
    }

    return $picked;
}

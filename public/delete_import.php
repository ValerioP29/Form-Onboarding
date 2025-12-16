<?php
// public/delete_import.php
require_once __DIR__ . '/../app/helpers.php';

header('Content-Type: application/json');

function af_json_err(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawInput = file_get_contents('php://input');
$asJson   = false;
if ($rawInput && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $data = json_decode($rawInput, true);
    if (is_array($data)) {
        $asJson = true;
    } else {
        $data = [];
    }
} else {
    $data = $_POST;
}

$submissionId = $data['submission_id'] ?? null;
$kind         = $data['kind'] ?? null;

if (!$submissionId || !preg_match('/^[a-zA-Z0-9_:-]{10,}$/', (string)$submissionId)) {
    af_json_err('submission_id mancante o non valido');
}

$allowedKinds = ['products', 'promos', 'products_images'];
if (!$kind || !in_array($kind, $allowedKinds, true)) {
    af_json_err('kind non valido');
}

$cfg      = af_load_config();
$baseDir  = rtrim($cfg['submissions_dir'], '/') . '/' . $submissionId;
$realBase = realpath($baseDir);

if (!$realBase || !is_dir($realBase)) {
    af_json_err('submission non trovata', 404);
}

$submissionPath = $realBase . '/submission.json';
if (!is_file($submissionPath)) {
    af_json_err('submission.json non trovato', 404);
}

$step3Dir = $realBase . '/step3_prodotti';
$step3Real = realpath($step3Dir);
if (!$step3Real || !is_dir($step3Real)) {
    af_json_err('cartella import non trovata', 404);
}

$filesPath = $step3Dir . '/files.json';
$reportPath = $step3Dir . '/import_report.json';
$csvCheckPath = $step3Dir . '/csv_check.json';

$filesJson = is_file($filesPath) ? json_decode(file_get_contents($filesPath), true) : [];
if (!is_array($filesJson)) $filesJson = [];

$submissionJson = json_decode(file_get_contents($submissionPath), true);
if (!is_array($submissionJson)) $submissionJson = [];

function af_safe_unlink(?string $path, string $base): void {
    if (!$path || !file_exists($path)) return;
    $realFile = realpath($path);
    $realBase = realpath($base);
    if ($realFile && $realBase && strpos($realFile, $realBase) === 0) {
        @unlink($realFile);
    }
}

function af_remove_file_entry(array $entry, string $base): void {
    if (isset($entry['file'])) {
        af_safe_unlink($entry['file'], $base);
        af_safe_unlink($entry['file'] . '.preview.json', $base);
    }
}

$changed = false;

if ($kind === 'products') {
    if (!empty($filesJson['products_csv'])) {
        af_remove_file_entry($filesJson['products_csv'], $realBase);
        $changed = true;
    }
    if (!empty($filesJson['products_export'])) {
        af_remove_file_entry($filesJson['products_export'], $realBase);
        $changed = true;
    }

    $filesJson['products_csv'] = null;
    $filesJson['products_export'] = null;

    if (is_file($csvCheckPath)) {
        af_safe_unlink($csvCheckPath, $realBase);
    }

    $previous = $submissionJson['summary']['csv_products'] ?? null;
    $submissionJson['summary']['csv_products'] = false;
    if ($previous !== false) $changed = true;
}

if ($kind === 'promos') {
    if (!empty($filesJson['promos_csv'])) {
        af_remove_file_entry($filesJson['promos_csv'], $realBase);
        $changed = true;
    }
    $filesJson['promos_csv'] = null;
    $previous = $submissionJson['summary']['csv_promos'] ?? null;
    $submissionJson['summary']['csv_promos'] = false;
    if ($previous !== false) $changed = true;
}

if ($kind === 'products_images') {
    if (!empty($filesJson['products_images_zip'])) {
        af_remove_file_entry($filesJson['products_images_zip'], $realBase);
        $changed = true;
    }
    $filesJson['products_images_zip'] = null;
    $previous = $submissionJson['summary']['products_images_zip'] ?? null;
    $submissionJson['summary']['products_images_zip'] = false;
    if ($previous !== false) $changed = true;
}

file_put_contents(
    $filesPath,
    json_encode($filesJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

file_put_contents(
    $submissionPath,
    json_encode($submissionJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

$reportJson = is_file($reportPath) ? json_decode(file_get_contents($reportPath), true) : [];
if (!is_array($reportJson)) $reportJson = [];

if ($kind === 'products') {
    $reportJson['products'] = null;
}
if ($kind === 'promos') {
    $reportJson['promos'] = null;
}
if ($changed) {
    $reportJson['removed_at'] = date('c');
}

file_put_contents(
    $reportPath,
    json_encode($reportJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo json_encode([
    'ok' => true,
    'removed' => true,
    'kind' => $kind,
    'updated' => $changed,
    'message' => 'Import rimosso'
]);
exit;

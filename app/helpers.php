<?php

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

// Carica la config
function af_load_config(): array {
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
    }
    return $cfg;
}

// Costruisce base_url
function af_base_url(string $path = ''): string {
    $c = af_load_config();
    $b = rtrim($c['base_url'], '/');
    $p = ltrim($path, '/');
    return $b . ($p ? '/' . $p : '');
}

// Crea tutte le cartelle necessarie (anche tmp per upload async)
function af_ensure_dirs(): void {
    $c = af_load_config();
    $dirs = [
        $c['storage_dir'],
        $c['uploads_dir'],
        $c['submissions_dir'],
        $c['uploads_tmp_dir'] ?? (__DIR__.'/../storage/tmp')
    ];
    foreach ($dirs as $d) {
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
    }
}

// Sanitizza testo
function af_sanitize_text(?string $v, int $max = 2000): string {
    $v = trim((string)$v);
    $v = strip_tags($v);
    if (mb_strlen($v) > $max) {
        $v = mb_substr($v, 0, $max);
    }
    return $v;
}

// Genera ID casuale
function af_random_id(int $len = 10): string {
    return substr(bin2hex(random_bytes(16)), 0, $len);
}

// CSRF check
function af_validate_csrf(string $token): bool {
    session_start();
    $c = af_load_config();
    return isset($_SESSION[$c['csrf_session_key']]) &&
           hash_equals($_SESSION[$c['csrf_session_key']], $token);
}

function af_generate_csrf(): string {
    session_start();
    $c = af_load_config();
    $t = bin2hex(random_bytes(32));
    $_SESSION[$c['csrf_session_key']] = $t;
    return $t;
}

// Salvataggio file (solo per upload tradizionali)
function af_save_uploaded_file(array $file, array $allowed, ?int $maxBytes = null): array {
    $maxBytes = $maxBytes ?: af_load_config()['max_upload_bytes'];

    if (!isset($file['error']) || is_array($file['error'])) {
        return ['ok'=>false,'err'=>'Upload non valido'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok'=>false,'err'=>'Errore upload: '.$file['error']];
    }
    if ($file['size'] > $maxBytes) {
        return ['ok'=>false,'err'=>'File troppo grande'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    if (!in_array($mime, $allowed, true)) {
        return ['ok'=>false,'err'=>'Tipo file non consentito: '.$mime];
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'bin';
    $safeName = af_sanitize_filename(basename($file['name']));

    $id = date('Ymd_His') . '_' . af_random_id(6) . '_' . $safeName;
    $destDir = af_load_config()['uploads_dir'];
    af_ensure_dirs();
    $dest = rtrim($destDir,'/') . '/' . $id;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok'=>false,'err'=>'Impossibile salvare il file'];
    }

    return [
        'ok'   => true,
        'path' => $dest,
        'name' => $safeName,
        'mime' => $mime,
        'ext'  => $ext,
        'size' => $file['size']
    ];
}

// Sanitizza filename per upload async
function af_sanitize_filename(string $name): string {
    $name = str_replace(['%', '/', '\\', "\0"], '_', $name);
    $name = preg_replace('/[^\pL\pN\.\-\_\(\) ]+/u', '_', $name);
    $name = trim($name);
    return $name !== '' ? $name : 'file';
}

function af_parse_csv_sample(string $path, int $limit = 3): array {
    $sample = [];
    $count = 0;

    if (($h = fopen($path, 'r')) !== false) {
        while (($row = fgetcsv($h, 0, ",")) !== false) {
            $count++;
            if (count($sample) < $limit) {
                $sample[] = $row;
            }
            if ($count >= 10000) break; 
        }
        fclose($h);
    }

    return [
        'sample'        => $sample,
        'rows_estimate' => $count
    ];
}

function af_parse_xlsx_sample(string $filePath, int $maxRows = 3): array {
    if (!is_file($filePath)) {
        return ['sample' => [], 'rows_estimate' => 0, 'error' => 'file_not_found'];
    }

    try {
        // Read-only, meno memoria
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $reader->setReadDataOnly(true);

        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        $highestRow = (int)$sheet->getHighestDataRow();
        $highestCol = $sheet->getHighestDataColumn();

        // Legge intestazioni dalla prima riga
        $headerRow = $sheet->rangeToArray("A1:{$highestCol}1", null, true, true, true);
        $headers = array_values($headerRow[1] ?? []);

        $sample = [];
        $endRow = min($highestRow, 1 + $maxRows);

        for ($r = 2; $r <= $endRow; $r++) {
            $row = $sheet->rangeToArray("A{$r}:{$highestCol}{$r}", null, true, true, true);
            $vals = array_values($row[$r] ?? []);
            $sample[] = array_combine(
                array_map(fn($h, $i) => ($h !== null && trim((string)$h) !== '') ? trim((string)$h) : "col_$i", $headers, array_keys($headers)),
                $vals
            );
        }

        // Libera memoria
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return [
            'sample' => $sample,
            'rows_estimate' => max(0, $highestRow - 1), // escluse intestazioni
        ];
    } catch (\Throwable $e) {
        return ['sample' => [], 'rows_estimate' => 0, 'error' => $e->getMessage()];
    }
}


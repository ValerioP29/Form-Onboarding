<?php /* public/submit.php */
require_once __DIR__ . '/../app/helpers.php';

function af_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $cfg = af_load_config();
    $dbPath = ($cfg['storage_dir'] ?? (__DIR__.'/../storage')) . '/onboarding.sqlite';
    @mkdir(dirname($dbPath), 0775, true);

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL');

    // Tabelle base (idempotenti)
    $pdo->exec("CREATE TABLE IF NOT EXISTS pharmacies (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ext_id TEXT UNIQUE,
        name TEXT,
        vat_cf TEXT,
        metadata_json TEXT,
        created_at TEXT
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        pharmacy_id INTEGER NOT NULL,
        sku TEXT,
        name TEXT,
        price REAL,
        description TEXT,
        category TEXT,
        image_path TEXT,
        updated_at TEXT,
        UNIQUE(pharmacy_id, sku)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS promotions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        pharmacy_id INTEGER NOT NULL,
        sku TEXT,
        title TEXT,
        body TEXT,
        starts_at TEXT,
        ends_at TEXT,
        updated_at TEXT,
        UNIQUE(pharmacy_id, sku, title)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS import_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        submission_id TEXT,
        pharmacy_id INTEGER,
        created_at TEXT,
        kind TEXT,
        summary_json TEXT
    )");

    return $pdo;
}

function af_ensure_pharmacy(array $step1_fields, string $submissionId): array {
    $pdo = af_db();
    $extId = $step1_fields['vat_cf'] ?: $submissionId;
    $stmt = $pdo->prepare("SELECT id FROM pharmacies WHERE ext_id = :ext");
    $stmt->execute([':ext' => $extId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return ['id' => (int)$row['id'], 'ext_id' => $extId];
    }

    $insert = $pdo->prepare("INSERT INTO pharmacies (ext_id, name, vat_cf, metadata_json, created_at)
        VALUES (:ext, :name, :vat, :meta, :created)");
    $insert->execute([
        ':ext'     => $extId,
        ':name'    => $step1_fields['pharmacy_name'] ?? null,
        ':vat'     => $step1_fields['vat_cf'] ?? null,
        ':meta'    => json_encode($step1_fields, JSON_UNESCAPED_UNICODE),
        ':created' => date('c'),
    ]);
    return ['id' => (int)$pdo->lastInsertId(), 'ext_id' => $extId];
}

function af_detect_csv_delimiter(string $path): string {
    $sample = file_get_contents($path, false, null, 0, 1024) ?: '';
    return (substr_count($sample, ';') > substr_count($sample, ',')) ? ';' : ',';
}

function af_parse_csv_rows(string $path): array {
    $rows = [];
    if (!is_file($path)) return $rows;
    $delim = af_detect_csv_delimiter($path);
    if (($h = fopen($path, 'r')) !== false) {
        $header = null;
        while (($data = fgetcsv($h, 0, $delim)) !== false) {
            $data = array_map(fn($v) => trim((string)$v), $data);
            if ($header === null) {
                $header = array_map('strtolower', $data);
                continue;
            }
            if (count(array_filter($data, fn($v) => $v !== '')) === 0) continue;
            $rows[] = array_combine($header, array_pad($data, count($header), null));
        }
        fclose($h);
    }
    return $rows;
}

function af_parse_xlsx_rows(string $path): array {
    if (!is_file($path)) return [];
    try {
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = (int)$sheet->getHighestDataRow();
        $highestCol = $sheet->getHighestDataColumn();
        $headerRow = $sheet->rangeToArray("A1:{$highestCol}1", null, true, true, true);
        $header = array_map('strtolower', array_values($headerRow[1] ?? []));
        $rows = [];
        for ($r = 2; $r <= $highestRow; $r++) {
            $row = $sheet->rangeToArray("A{$r}:{$highestCol}{$r}", null, true, true, true);
            $data = array_values($row[$r] ?? []);
            if (count(array_filter($data, fn($v) => trim((string)$v) !== '')) === 0) continue;
            $rows[] = array_combine($header, array_map(fn($v) => trim((string)$v), $data));
        }
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        return $rows;
    } catch (\Throwable $e) {
        return [];
    }
}

function af_normalize_row(array $row, array $aliases): array {
    $out = [];
    foreach ($aliases as $target => $keys) {
        foreach ($keys as $k) {
            if (array_key_exists($k, $row) && trim((string)$row[$k]) !== '') {
                $out[$target] = trim((string)$row[$k]);
                break;
            }
        }
        if (!isset($out[$target])) $out[$target] = null;
    }
    return $out;
}

function af_import_products(array $rows, int $pharmacyId, ?array $imagesIndex, string $submissionId): array {
    $aliases = [
        'name'        => ['name','nome','product','prodotto','title','titolo'],
        'sku'         => ['sku','codice','code','id','ean'],
        'price'       => ['price','prezzo','amount','valore'],
        'description' => ['description','descrizione','desc','note'],
        'category'    => ['category','categoria','categoria1'],
        'image'       => ['image','img','photo','immagine','img_url'],
    ];
    $pdo = af_db();
    $ok = 0; $fail = 0; $errors = [];
    $stmt = $pdo->prepare("INSERT INTO products (pharmacy_id, sku, name, price, description, category, image_path, updated_at)
        VALUES (:pid, :sku, :name, :price, :desc, :cat, :img, :upd)
        ON CONFLICT(pharmacy_id, sku) DO UPDATE SET
            name=excluded.name,
            price=excluded.price,
            description=excluded.description,
            category=excluded.category,
            image_path=excluded.image_path,
            updated_at=excluded.updated_at");

    foreach ($rows as $idx => $row) {
        $norm = af_normalize_row($row, $aliases);
        if (empty($norm['name']) || empty($norm['sku'])) {
            $fail++; $errors[] = ['row'=>$idx+2,'reason'=>'name_sku_missing']; continue;
        }
        $img = $norm['image'];
        if (!$img && $imagesIndex && isset($imagesIndex[strtolower($norm['sku'])])) {
            $img = $imagesIndex[strtolower($norm['sku'])];
        }
        try {
            $stmt->execute([
                ':pid' => $pharmacyId,
                ':sku' => $norm['sku'],
                ':name'=> $norm['name'],
                ':price'=> is_numeric($norm['price'] ?? null) ? (float)$norm['price'] : null,
                ':desc'=> $norm['description'],
                ':cat' => $norm['category'],
                ':img' => $img,
                ':upd' => date('c'),
            ]);
            $ok++;
        } catch (\Throwable $e) {
            $fail++; $errors[] = ['row'=>$idx+2,'reason'=>'db_error','detail'=>$e->getMessage()];
        }
    }
    af_db()->prepare("INSERT INTO import_log (submission_id, pharmacy_id, created_at, kind, summary_json)
        VALUES (:sid, :pid, :created, 'products', :summary)")->execute([
        ':sid'=>$submissionId,
        ':pid'=>$pharmacyId,
        ':created'=>date('c'),
        ':summary'=>json_encode(['ok'=>$ok,'fail'=>$fail,'errors'=>$errors], JSON_UNESCAPED_UNICODE),
    ]);
    return ['ok'=>$ok,'fail'=>$fail,'errors'=>$errors];
}

function af_import_promos(array $rows, int $pharmacyId, string $submissionId): array {
    $aliases = [
        'title' => ['title','titolo','promo','offerta','nome'],
        'body'  => ['body','descrizione','description','testo'],
        'sku'   => ['sku','codice','product_sku','prodotto'],
        'starts'=> ['start','inizio','start_date','dal'],
        'ends'  => ['end','fine','end_date','al'],
    ];
    $pdo = af_db();
    $ok = 0; $fail = 0; $errors = [];
    $stmt = $pdo->prepare("INSERT INTO promotions (pharmacy_id, sku, title, body, starts_at, ends_at, updated_at)
        VALUES (:pid, :sku, :title, :body, :s, :e, :upd)
        ON CONFLICT(pharmacy_id, sku, title) DO UPDATE SET
            body=excluded.body,
            starts_at=excluded.starts_at,
            ends_at=excluded.ends_at,
            updated_at=excluded.updated_at");

    foreach ($rows as $idx => $row) {
        $norm = af_normalize_row($row, $aliases);
        if (empty($norm['title'])) { $fail++; $errors[]=['row'=>$idx+2,'reason'=>'title_missing']; continue; }
        try {
            $stmt->execute([
                ':pid'=>$pharmacyId,
                ':sku'=>$norm['sku'],
                ':title'=>$norm['title'],
                ':body'=>$norm['body'],
                ':s'=>$norm['starts'],
                ':e'=>$norm['ends'],
                ':upd'=>date('c'),
            ]);
            $ok++;
        } catch (\Throwable $e) {
            $fail++; $errors[] = ['row'=>$idx+2,'reason'=>'db_error','detail'=>$e->getMessage()];
        }
    }
    af_db()->prepare("INSERT INTO import_log (submission_id, pharmacy_id, created_at, kind, summary_json)
        VALUES (:sid, :pid, :created, 'promos', :summary)")->execute([
        ':sid'=>$submissionId,
        ':pid'=>$pharmacyId,
        ':created'=>date('c'),
        ':summary'=>json_encode(['ok'=>$ok,'fail'=>$fail,'errors'=>$errors], JSON_UNESCAPED_UNICODE),
    ]);
    return ['ok'=>$ok,'fail'=>$fail,'errors'=>$errors];
}

function af_build_image_index(?array $imagesZip): ?array {
    if (!$imagesZip) return null;
    $zipPath = $imagesZip['file'] ?? null;
    if (!$zipPath || !is_file($zipPath)) return null;
    if (!class_exists(ZipArchive::class)) return null;
    $extractDir = dirname($zipPath) . '/images_extracted';
    @mkdir($extractDir, 0775, true);
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) return null;
    $zip->extractTo($extractDir);
    $zip->close();
    $index = [];
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($extractDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($rii as $file) {
        if ($file->isDir()) continue;
        $fname = strtolower($file->getBasename());
        $sku = strtolower(pathinfo($fname, PATHINFO_FILENAME));
        $index[$sku] = $file->getPathname();
    }
    return $index;
}

$cfg = af_load_config();
af_ensure_dirs();

$errors = [];
$data   = [];
$fields = $_POST;

$submissionId = date('Ymd_His') . '_' . af_random_id(6);
$baseDir = rtrim($cfg['submissions_dir'],'/').'/'.$submissionId;
@mkdir($baseDir, 0775, true);

$uploadManifestJson = $_POST['upload_manifest'] ?? '[]';
$uploadItems = json_decode($uploadManifestJson, true);
if (!is_array($uploadItems)) $uploadItems = [];

$uploadsTmp = rtrim($cfg['uploads_tmp_dir'] ?? (__DIR__.'/../storage/tmp'), '/');
$sessionId = $_POST['session_id'] ?? null;
$sessionDir = $sessionId ? $uploadsTmp.'/'.$sessionId : null;

function mk($path) {
    @mkdir($path, 0775, true);
}

function move_from_tmp($tmpPath, $destDir) {
    @mkdir($destDir, 0775, true);
    if (!file_exists($tmpPath)) return null;

    $base = basename($tmpPath);
    $dest = rtrim($destDir,'/').'/'.$base;

    if (@rename($tmpPath, $dest)) return $dest;
    if (@copy($tmpPath, $dest)) {
        @unlink($tmpPath);
        return $dest;
    }
    return null;
}

$step1 = $baseDir.'/step1_farmacia';
mk($step1);

$step1_fields = [
    'pharmacy_name' => $fields['pharmacy_name'] ?? null,
    'address'       => $fields['address']       ?? null,
    'vat_cf'        => $fields['vat_cf']        ?? null,
    'referent'      => $fields['referent']      ?? null,
    'email'         => $fields['email']         ?? null,
    'phone'         => $fields['phone']         ?? null,
    'whatsapp'      => $fields['whatsapp']      ?? null,
    'description'   => $fields['description']   ?? null,
    'departments'   => $fields['departments']   ?? null,
];
file_put_contents($step1.'/fields.json', json_encode($step1_fields, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

$step2 = "$baseDir/step2_orari_immagini";
mk($step2);

// Orari
$hours = $fields['hours'] ?? [];
file_put_contents(
    "$step2/hours.json",
    json_encode($hours, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)
);

$files_step2 = ['logo'=>null, 'gallery'=>[]];

// Sposta logo e gallery dal manifest
foreach ($uploadItems as $it) {
    $bucket = $it['bucket'] ?? '';
    $path   = $it['path'] ?? '';

    if (!$path || !file_exists($path)) continue;

    if ($bucket === 'logo') {
        $m = move_from_tmp($path, $step2);
        if ($m) {
            $files_step2['logo'] = [
                'file' => $m,
                'mime' => mime_content_type($m),
                'size' => filesize($m)
            ];
        }
    }

    if ($bucket === 'photo_gallery') {
        $m = move_from_tmp($path, $step2.'/gallery');
        if ($m) {
            $files_step2['gallery'][] = [
                'file' => $m,
                'mime' => mime_content_type($m),
                'size' => filesize($m)
            ];
        }
    }
}

file_put_contents(
    "$step2/files.json",
    json_encode($files_step2, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)
);

$step3 = $baseDir.'/step3_prodotti';
mk($step3);

$csv_prodotti = null;
$csv_export   = null;
$csv_promos   = null;
$images_zip   = null; 

foreach ($uploadItems as $it) {
    $bucket = $it['bucket'] ?? '';
    $path   = $it['path'] ?? '';
    if (!$path || !file_exists($path)) continue;

    if ($bucket === 'products_csv') {
        $m = move_from_tmp($path, $step3);
        if ($m) {
            $csv_prodotti = ['file'=>$m, 'name'=>basename($m)];
            if (file_exists($path.'.preview.json')) {
                @rename($path.'.preview.json', $m.'.preview.json');
            }
        }
    }

    if ($bucket === 'products_export') {
        $m = move_from_tmp($path, $step3);
        if ($m) {
            $csv_export = ['file'=>$m, 'name'=>basename($m)];
            if (file_exists($path.'.preview.json')) {
                @rename($path.'.preview.json', $m.'.preview.json');
            }
        }
    }

    if ($bucket === 'promos_csv') {
        $m = move_from_tmp($path, $step3);
        if ($m) $csv_promos = ['file'=>$m, 'name'=>basename($m)];
    }

    if ($bucket === 'products_images_zip') {
        $m = move_from_tmp($path, $step3.'/images_zip');
        if ($m) {
            $images_zip = [
                'file'=>$m,
                'name'=>basename($m),
                'size'=>filesize($m)
            ];
        }
    }
}

file_put_contents(
    $step3.'/files.json',
    json_encode([
        'products_csv'        => $csv_prodotti,
        'products_export'     => $csv_export,
        'promos_csv'          => $csv_promos,
        'products_images_zip' => $images_zip,
    ], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)
);

if ($csv_prodotti && !file_exists($csv_prodotti['file'].'.preview.json')) {
    $rows = af_parse_csv_sample($csv_prodotti['file'], 3);
    file_put_contents($step3.'/csv_check.json', json_encode([
        'rows'   => $rows['rows_estimate'],
        'sample' => $rows['sample'],
    ], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
} elseif ($csv_prodotti && file_exists($csv_prodotti['file'].'.preview.json')) {
    @copy($csv_prodotti['file'].'.preview.json', $step3.'/csv_check.json');
}

$step4 = $baseDir.'/step4_servizi';
mk($step4);

$servizi = $fields['services'] ?? [];
foreach ($servizi as &$srv) {
    $srv['images'] = [];
}
unset($srv);

// raccolgo immagini per servizi (metodo nuovo: con service_id)
foreach ($uploadItems as $it) {
    if (($it['bucket'] ?? '') !== 'service_img') continue;
    $path = $it['path'] ?? '';
    $serviceId = $it['service_id'] ?? null;

    if (!$path || !file_exists($path)) continue;

    $m = move_from_tmp($path, $step4.'/img');
    if (!$m) continue;

    // se abbiamo service_id, associamo direttamente
    if ($serviceId !== null && isset($servizi[$serviceId])) {
        $servizi[$serviceId]['images'][] = [
            'file' => $m,
            'mime' => mime_content_type($m),
            'size' => filesize($m),
        ];
        continue;
    }

    // fallback vecchio metodo -> per indice
    // se manca service_id, troviamo il primo servizio senza immagini
    foreach ($servizi as &$srv) {
        if (empty($srv['images'])) {
            $srv['images'][] = [
                'file' => $m,
                'mime' => mime_content_type($m),
                'size' => filesize($m),
            ];
            break;
        }
    }
    unset($srv);
}

file_put_contents(
    $step4.'/fields.json',
    json_encode($servizi, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)
);

$step5 = $baseDir.'/step5_eventi';
mk($step5);

$eventi = $fields['events'] ?? [];

$events_images = []; 
foreach ($uploadItems as $it) {
    if (($it['bucket'] ?? '') !== 'event_img') continue;
    $m = move_from_tmp($it['path'], $step5.'/img');
    if ($m) {
        $events_images[] = [
            'file' => $m,
            'mime' => mime_content_type($m),
            'size' => filesize($m)
        ];
    }
}

foreach ($eventi as $i => &$ev) {
    if (isset($events_images[$i])) {
        $ev['images'] = [$events_images[$i]];
    } else {
        $ev['images'] = [];
    }
}
unset($ev);

file_put_contents(
    $step5.'/fields.json',
    json_encode($eventi, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)
);



$step6 = $baseDir.'/step6_social';
mk($step6);

$social_fields = [
    'website'   => $fields['site']       ?? null,
    'facebook'  => $fields['facebook']   ?? null,
    'instagram' => $fields['instagram']  ?? null,
    'other'     => $fields['othersocial'] ?? null,
];

file_put_contents(
    $step6.'/fields.json',
    json_encode($social_fields, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)
);

$counts = [
    'services' => is_array($servizi ?? null) ? count(array_filter($servizi, fn($s) => !empty($s['name']))) : 0,
    'events'   => is_array($eventi ?? null)  ? count(array_filter($eventi, fn($e) => !empty($e['title']) && !empty($e['date']))) : 0,
];

$hasLogo   = !empty($files_step2['logo']);
$hasPhotos = $hasLogo || (!empty($files_step2['gallery']));

$manifest = [
    'id'         => $submissionId,
    'created_at' => date('c'),
    'steps' => [
        'step1_farmacia'       => ['path' => 'step1_farmacia/fields.json'],
        'step2_orari_immagini' => ['paths' => [
            'hours' => 'step2_orari_immagini/hours.json',
            'files' => 'step2_orari_immagini/files.json'
        ]],
        'step3_prodotti'       => ['path' => 'step3_prodotti/files.json'],
        'step4_servizi'        => ['path' => 'step4_servizi/fields.json'],
        'step5_eventi'         => ['path' => 'step5_eventi/fields.json'],
        'step6_social'         => ['path' => 'step6_social/fields.json'],
    ],
    'summary' => [
        'pharmacy_complete' => !empty($step1_fields['pharmacy_name']) && !empty($step1_fields['address']) && !empty($step1_fields['email']),
        'hours_present'     => !empty(array_filter($hours ?? [], fn($d) => !empty($d['open_am']) || !empty($d['open_pm']))),
        'has_logo'          => $hasLogo,
        'has_photos'        => $hasPhotos,
        'csv_products'      => !empty($csv_prodotti),
        'csv_promos'        => !empty($csv_promos),
        'services_count'    => $counts['services'],
        'events_count'      => $counts['events'],
    ],
];

file_put_contents(
    $baseDir.'/submission.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

// ---------------- IMPORT DATI ----------------
$pharmacy = af_ensure_pharmacy($step1_fields, $submissionId);
$imagesIndex = af_build_image_index($images_zip);

$productsRows = [];
if ($csv_prodotti) {
    $ext = strtolower(pathinfo($csv_prodotti['name'], PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        $productsRows = af_parse_xlsx_rows($csv_prodotti['file']);
    } else {
        $productsRows = af_parse_csv_rows($csv_prodotti['file']);
    }
} elseif ($csv_export) {
    $ext = strtolower(pathinfo($csv_export['name'], PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        $productsRows = af_parse_xlsx_rows($csv_export['file']);
    } else {
        $productsRows = af_parse_csv_rows($csv_export['file']);
    }
}

$promosRows = [];
if ($csv_promos) {
    $ext = strtolower(pathinfo($csv_promos['name'], PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        $promosRows = af_parse_xlsx_rows($csv_promos['file']);
    } else {
        $promosRows = af_parse_csv_rows($csv_promos['file']);
    }
}

$productsReport = $productsRows ? af_import_products($productsRows, $pharmacy['id'], $imagesIndex, $submissionId) : ['ok'=>0,'fail'=>0,'errors'=>[]];
$promosReport   = $promosRows   ? af_import_promos($promosRows, $pharmacy['id'], $submissionId) : ['ok'=>0,'fail'=>0,'errors'=>[]];

$report = [
    'pharmacy_id' => $pharmacy['id'],
    'submission_id' => $submissionId,
    'products' => $productsReport,
    'promos'   => $promosReport,
    'generated_at' => date('c'),
];

file_put_contents($step3.'/import_report.json', json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

if ($sessionDir && is_dir($sessionDir)) {
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sessionDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($rii as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($sessionDir);
}

header('Location: '.af_base_url('success.php?id='.$submissionId));
exit;

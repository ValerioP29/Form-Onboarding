<?php /* public/submit.php */
require_once __DIR__ . '/../app/helpers.php';

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

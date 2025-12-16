<?php
// public/upload.php
require_once __DIR__ . '/../app/helpers.php';

af_ensure_dirs();
$cfg = af_load_config();
header('Content-Type: application/json');

function json_err($msg, $code=400) {
    http_response_code($code);
    echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------- SESSION VALIDATION ----------------
$sessionId = $_POST['session_id'] ?? null;
if (!$sessionId || !preg_match('/^[a-zA-Z0-9_\-]{10,}$/', $sessionId)) {
    json_err('session_id mancante o non valido');
}

// ---------------- BUCKET VALIDATION ----------------
$bucket = $_POST['bucket'] ?? null;
$allowedBuckets = [
    'logo',
    'pharmacist_avatar',
    'photo_gallery',
    'service_img',
    'event_img',
    'products_csv',
    'products_export',
    'promos_csv',
    'products_images_zip'
];
if (!in_array($bucket, $allowedBuckets, true)) {
    json_err('bucket non valido');
}

// ---------------- DIRECTORIES ----------------
$uploadsTmp = rtrim($cfg['uploads_tmp_dir'] ?? (__DIR__.'/../storage/tmp'), '/');
@mkdir($uploadsTmp, 0775, true);

$sessionDir = $uploadsTmp . '/' . $sessionId;
@mkdir($sessionDir, 0775, true);

$fileId = $_POST['file_id'] ?? null;
if (!$fileId || !preg_match('/^[a-zA-Z0-9_\-]{8,}$/', $fileId)) {
    json_err('file_id mancante o non valido');
}

$totalChunks   = max(1, (int)($_POST['total_chunks'] ?? 1));
$chunkIndex    = max(0, (int)($_POST['chunk_index'] ?? 0));
$originalName  = $_POST['original_name'] ?? 'file.bin';
$mime          = $_POST['mime'] ?? 'application/octet-stream';

$serviceId = $_POST['service_id'] ?? null;
$eventId   = $_POST['event_id']   ?? null;

$validImageMimes = [
    'image/jpeg', 'image/jpg', 'image/png', 'image/webp',
    'image/gif', 'image/svg+xml', 'image/pjpeg', 'image/x-png'
];

$isImageBucket = in_array($bucket, ['logo','pharmacist_avatar','photo_gallery','service_img','event_img']);
$isZip = ($bucket === 'products_images_zip');

// ---------------- CHUNK LOGIC ----------------
$bucketDir = $sessionDir . '/' . $bucket;
@mkdir($bucketDir, 0775, true);

$chunkDir = $bucketDir . '/' . $fileId . '.parts';
@mkdir($chunkDir, 0775, true);

if (
    !isset($_FILES['chunk']) ||
    $_FILES['chunk']['error'] !== UPLOAD_ERR_OK ||
    !is_uploaded_file($_FILES['chunk']['tmp_name'])
) {
    json_err('chunk non ricevuto o errore upload');
}

$chunkTmp  = $_FILES['chunk']['tmp_name'];
$chunkPath = $chunkDir . '/' . str_pad((string)$chunkIndex, 6, '0', STR_PAD_LEFT) . '.part';

if (!move_uploaded_file($chunkTmp, $chunkPath)) {
    json_err('impossibile scrivere chunk');
}

$parts = glob($chunkDir.'/*.part') ?: [];
if (count($parts) === $totalChunks) {
    // ******** ASSEMBLA FILE ********
    natsort($parts);

    $safeName  = af_sanitize_filename($originalName);
    $finalPath = $bucketDir . '/' . $fileId . '__' . $safeName;

    $out = fopen($finalPath, 'wb');
    if (!$out) json_err('Impossibile creare file finale sul server');

    foreach ($parts as $p) {
        $in = fopen($p, 'rb');
        if ($in) {
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
    }
    fclose($out);

    foreach ($parts as $p) @unlink($p);
    @rmdir($chunkDir);

    $realMime  = mime_content_type($finalPath);
    $ext       = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
    $finalSize = filesize($finalPath);

    $csvMimes = [
        'text/csv',
        'application/csv',
        'text/plain',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];
    $exportMimes = array_merge($csvMimes, [
        'application/pdf',
        'application/zip',
        'application/x-zip-compressed',
    ]);

    $maxUploadDefault = $cfg['max_upload_bytes'] ?? (20 * 1024 * 1024);

    if ($isImageBucket) {
        if (!in_array($realMime, $validImageMimes, true)) {
            error_log("[UPLOAD WARNING] MIME non standard per immagine: $realMime");
        }
    }

    if (in_array($bucket, ['products_csv','products_export','promos_csv'], true)) {
        $maxProducts = $cfg['max_products_upload_bytes'] ?? $maxUploadDefault;
        if ($finalSize > $maxProducts) {
            @unlink($finalPath);
            json_err('File troppo grande', 413);
        }
    }

    if ($bucket === 'products_images_zip') {
        $maxImagesZip = $cfg['max_images_zip_upload_bytes'] ?? $maxUploadDefault;
        if ($finalSize > $maxImagesZip) {
            @unlink($finalPath);
            json_err('File troppo grande', 413);
        }
    }

    if (in_array($bucket, ['products_csv','promos_csv'], true)) {
        if ($ext === 'xlsx') {
            // Il MIME può risultare application/zip, controlliamo la struttura reale
            if (!af_is_real_xlsx($finalPath)) {
                @unlink($finalPath);
                json_err('File .xlsx non valido o corrotto', 415);
            }
        } else {
            if (!in_array($realMime, $csvMimes, true)) {
                @unlink($finalPath);
                json_err('Tipo file non consentito', 415);
            }
        }
    }

    if ($bucket === 'products_export') {
        if ($realMime === 'application/octet-stream' && $ext !== 'txt') {
            @unlink($finalPath);
            json_err('Tipo file non consentito', 415);
        }

        if ($ext === 'xlsx') {
            // Il MIME può risultare application/zip, controlliamo la struttura reale
            if (!af_is_real_xlsx($finalPath)) {
                @unlink($finalPath);
                json_err('File .xlsx non valido o corrotto', 415);
            }
        } elseif ($ext === 'csv') {
            if (!in_array($realMime, $csvMimes, true)) {
                @unlink($finalPath);
                json_err('Tipo file non consentito', 415);
            }
        } elseif ($ext === 'txt') {
            if (!in_array($realMime, ['text/plain','application/octet-stream'], true)) {
                @unlink($finalPath);
                json_err('Tipo file non consentito', 415);
            }
        } elseif ($ext === 'zip') {
            if (!in_array($realMime, ['application/zip','application/x-zip-compressed'], true)) {
                @unlink($finalPath);
                json_err('Tipo file non consentito', 415);
            }
        } elseif ($ext === 'pdf') {
            if ($realMime !== 'application/pdf') {
                @unlink($finalPath);
                json_err('Tipo file non consentito', 415);
            }
        } else {
            @unlink($finalPath);
            json_err('Tipo file non consentito', 415);
        }
    }

    $meta = [
        'ok'        => true,
        'completed' => true,
        'file_id'   => $fileId,
        'name'      => $safeName,
        'path'      => $finalPath,
        'bucket'    => $bucket,
    ];

    if ($serviceId !== null) $meta['service_id'] = (int)$serviceId;
    if ($eventId   !== null) $meta['event_id']   = (int)$eventId;

    // CSV preview
    if (in_array($bucket, ['products_csv','products_export','promos_csv'], true)) {
        if ($ext === 'xlsx') {
            $rows = af_parse_xlsx_sample($finalPath, 3);
            $meta['xlsx_preview'] = [
                'sample' => $rows['sample'],
                'rows'   => $rows['rows_estimate'] ?? null,
                'error'  => $rows['error'] ?? null,
            ];
            file_put_contents(
                $finalPath.'.preview.json',
                json_encode($meta['xlsx_preview'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)
            );
        } elseif ($ext === 'csv') {
            $rows = af_parse_csv_sample($finalPath, 3);
            $meta['csv_preview'] = [
                'sample' => $rows['sample'],
                'rows'   => $rows['rows_estimate'] ?? null
            ];
            file_put_contents(
                $finalPath.'.preview.json',
                json_encode($meta['csv_preview'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)
            );
        }
    }

    // LOG DEBUG
    error_log("[UPLOAD] Completed: $bucket -> $finalPath (service=$serviceId, event=$eventId)");

    echo json_encode($meta);
    exit;
}

// parziale
echo json_encode([
    'ok'            => true,
    'completed'     => false,
    'file_id'       => $fileId,
    'received_chunk'=> $chunkIndex,
    'total_chunks'  => $totalChunks
]);

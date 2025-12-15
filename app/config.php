<?php
return [
    // BASE URL ONLINE
    'base_url' => '/',

    'storage_dir'        => __DIR__ . '/../storage',
    'uploads_dir'        => __DIR__ . '/../storage/uploads',
    'submissions_dir'    => __DIR__ . '/../storage/submissions',
    'uploads_tmp_dir'    => __DIR__ . '/../storage/tmp',

    'max_upload_bytes' => 20 * 1024 * 1024, // 20MB

    'allowed_image_mimes' => [
        'image/png','image/jpeg','image/webp','image/svg+xml'
    ],

    'allowed_doc_mimes' => [
        'application/pdf',
        'text/csv',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    ],

    'csrf_session_key' => 'csrf_af_token',
];

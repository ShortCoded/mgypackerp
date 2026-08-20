<?php

return [
    'disk' => env('ARCHIVE_DISK', 'local'),

    'uploads' => [
        'max_files' => 100,
        'max_file_size_kib' => 51200,
        'max_file_size_mib' => 50,
        'parallel_uploads' => 2,
    ],

    'duplicates' => [
        'prevent_same_name_in_folder' => true,
    ],

    'bulk_download' => [
        'max_files' => 100,
        'max_total_size_mib' => 500,
    ],

    'audit' => [
        'log_downloads' => true,
        'log_previews' => false,
    ],

    'logo' => [
        'max_file_size_kib' => 2048,
        'max_file_size_mib' => 2,
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'svg', 'bmp'],
        'allowed_mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml', 'image/bmp', 'image/x-ms-bmp'],
    ],

    'favicon' => [
        'max_file_size_kib' => 1024,
        'max_file_size_mib' => 1,
        'allowed_extensions' => ['ico', 'png', 'jpg', 'jpeg', 'webp'],
    ],

    'documents' => [
        'allowed_mime_types' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/svg+xml',
            'image/bmp',
            'image/x-ms-bmp',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
            'text/csv',
            'application/csv',
            'application/zip',
            'application/x-zip-compressed',
            'application/vnd.rar',
            'application/x-rar-compressed',
        ],

        'allowed_extensions' => [
            'pdf',
            'jpg',
            'jpeg',
            'png',
            'webp',
            'svg',
            'bmp',
            'doc',
            'docx',
            'xls',
            'xlsx',
            'ppt',
            'pptx',
            'txt',
            'csv',
            'zip',
            'rar',
        ],

        'blocked_extensions' => [
            'php',
            'phtml',
            'phar',
            'js',
            'html',
            'htm',
            'exe',
            'bat',
            'cmd',
            'sh',
            'ps1',
            'msi',
        ],
    ],

    'allowed_extensions' => [
        'pdf',
        'jpg',
        'jpeg',
        'png',
        'webp',
        'svg',
        'bmp',
        'doc',
        'docx',
        'xls',
        'xlsx',
        'ppt',
        'pptx',
        'txt',
        'csv',
        'zip',
        'rar',
    ],

    'blocked_extensions' => [
        'php',
        'phtml',
        'phar',
        'js',
        'html',
        'htm',
        'exe',
        'bat',
        'cmd',
        'sh',
        'ps1',
        'msi',
    ],
];

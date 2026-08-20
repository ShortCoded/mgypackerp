<?php

return [
    'max_file_size_kb' => env('EXCEL_IMPORT_MAX_FILE_SIZE_KB', 10_240),
    'max_rows' => env('EXCEL_IMPORT_MAX_ROWS', 1_000),
    'max_sheets' => env('EXCEL_IMPORT_MAX_SHEETS', 6),
    'max_uncompressed_bytes' => env('EXCEL_IMPORT_MAX_UNCOMPRESSED_BYTES', 50 * 1024 * 1024),
    'expires_after_hours' => env('EXCEL_IMPORT_EXPIRES_AFTER_HOURS', 24),
];

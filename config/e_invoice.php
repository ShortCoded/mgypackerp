<?php

return [
    'enabled' => (bool) env('EINVOICE_ENABLED', false),
    'provider' => env('EINVOICE_PROVIDER', 'mock'),
    'environment' => env('EINVOICE_ENVIRONMENT', 'sandbox'),
    'base_url' => env('EINVOICE_BASE_URL'),
    'client_id' => env('EINVOICE_CLIENT_ID'),
    'client_secret' => env('EINVOICE_CLIENT_SECRET'),
    'access_token' => env('EINVOICE_ACCESS_TOKEN'),
    'certificate_path' => env('EINVOICE_CERTIFICATE_PATH'),
    'certificate_password' => env('EINVOICE_CERTIFICATE_PASSWORD'),
    'issuer_taxpayer_id' => env('EINVOICE_ISSUER_TAXPAYER_ID'),
    'branch_code' => env('EINVOICE_BRANCH_CODE'),
    'signing_mode' => env('EINVOICE_SIGNING_MODE', 'provider'),
    'signing_key_path' => env('EINVOICE_SIGNING_KEY_PATH'),
    'payload_version' => env('EINVOICE_PAYLOAD_VERSION', '1.0'),
    'timeout_seconds' => (int) env('EINVOICE_TIMEOUT_SECONDS', 20),
    'mock_result' => env('EINVOICE_MOCK_RESULT', 'accepted'),
];

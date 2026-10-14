<?php

declare(strict_types=1);

header('Access-Control-Allow-Origin: http://127.0.0.1:8001');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$allowedNames = [
    'dashboard' => 'mgypack-dashboard-debt-summaries-light-20261014.jpg',
    'work-order' => 'mgypack-production-order-prod-00018-light-20261014.jpg',
];
$name = $_GET['name'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ! isset($allowedNames[$name])) {
    http_response_code(404);
    exit;
}

$body = file_get_contents('php://input');

if (! is_string($body) || strlen($body) < 4 || strlen($body) > 10_000_000 || ! str_starts_with($body, "\xFF\xD8\xFF")) {
    http_response_code(422);
    exit;
}

$directory = dirname(__DIR__, 2).'/output/review/mgypack-release-candidate-20261014/screenshots';

if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
    http_response_code(500);
    exit;
}

$bytesWritten = file_put_contents($directory.'/'.$allowedNames[$name], $body, LOCK_EX);

if ($bytesWritten !== strlen($body)) {
    http_response_code(500);
    exit;
}

header('Content-Type: application/json');
echo json_encode(['bytes' => $bytesWritten], JSON_THROW_ON_ERROR);

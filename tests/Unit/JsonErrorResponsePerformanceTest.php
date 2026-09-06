<?php

use App\Support\Http\JsonErrorResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(TestCase::class);

function jsonPerformanceRequest(): Request
{
    return Request::create('/data-table', 'GET', server: [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
    ]);
}

test('successful JSON payloads bypass a second decode pass', function (): void {
    $response = new class(['data' => array_fill(0, 300, str_repeat('x', 4096))]) extends JsonResponse
    {
        public bool $decoded = false;

        public function getData($assoc = false, $depth = 512): mixed
        {
            $this->decoded = true;

            throw new RuntimeException('Successful JSON responses must not be decoded again.');
        }
    };

    $normalized = app(JsonErrorResponse::class)->normalize(jsonPerformanceRequest(), $response);

    expect($normalized)->toBe($response)
        ->and($response->decoded)->toBeFalse();
});

test('array-backed failed operations are normalized without decoding their JSON', function (): void {
    $response = new class(['success' => false, 'message' => 'Record is in use', 'data' => ['blocked_records' => [['id' => 1]]]]) extends JsonResponse
    {
        public bool $decoded = false;

        public function getData($assoc = false, $depth = 512): mixed
        {
            $this->decoded = true;

            throw new RuntimeException('Array-backed errors must use their original payload.');
        }
    };

    $normalized = app(JsonErrorResponse::class)->normalize(jsonPerformanceRequest(), $response);

    expect($normalized->getStatusCode())->toBe(409)
        ->and($normalized->getOriginalContent())->toMatchArray([
            'success' => false,
            'message' => 'Record is in use',
            'error_code' => 'record_in_use',
        ])
        ->and($response->decoded)->toBeFalse();
});

test('raw JSON failed operations keep the successful-status normalization contract', function (): void {
    $response = JsonResponse::fromJsonString(json_encode([
        'success' => false,
        'message' => 'Operation failed',
    ], JSON_THROW_ON_ERROR));

    $normalized = app(JsonErrorResponse::class)->normalize(jsonPerformanceRequest(), $response);

    expect($normalized->getStatusCode())->toBe(422)
        ->and($normalized->getData(true))->toMatchArray([
            'success' => false,
            'message' => 'Operation failed',
            'error_code' => 'business_rule_violation',
        ]);
});

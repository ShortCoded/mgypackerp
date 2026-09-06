<?php

use App\DataTables\BoundedDataTableRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Yajra\DataTables\Utilities\Request as DataTableRequest;

beforeEach(function (): void {
    Route::middleware('web')->match(
        ['GET', 'POST'],
        '/__tests/data-table-page-length',
        function (Request $request): JsonResponse {
            /** @var DataTableRequest $dataTableRequest */
            $dataTableRequest = app('datatables.request');

            return response()->json([
                'draw' => $request->input('draw'),
                'pagination' => $dataTableRequest->isPaginationable(),
                'start' => $dataTableRequest->start(),
                'length' => $dataTableRequest->length(),
                'request_class' => $dataTableRequest::class,
            ]);
        }
    );
});

test('server side datatable requests cap every oversized numeric format accepted by Yajra', function (int|float|string $length): void {
    $this->postJson('/__tests/data-table-page-length', [
        'draw' => 1,
        'start' => 0,
        'length' => $length,
    ])
        ->assertOk()
        ->assertJsonPath('length', 100);
})->with([
    'all records sentinel' => -1,
    'first oversized page' => 101,
    'legacy frontend maximum' => 300,
    'scientific notation string' => '1e9',
    'decimal numeric string' => '1000000000.5',
    'whitespace numeric string' => ' 300 ',
    'floating point number' => 300.5,
    'large finite exponent string' => '1e18',
]);

test('datatable shape detection follows the same numeric coercion as Yajra', function (): void {
    $this->postJson('/__tests/data-table-page-length', [
        'draw' => '1e0',
        'start' => '0.0',
        'length' => '1e9',
    ])
        ->assertOk()
        ->assertJsonPath('length', 100);
});

test('server side datatable requests preserve valid page lengths', function (int $length): void {
    $this->postJson('/__tests/data-table-page-length', [
        'draw' => 1,
        'start' => 0,
        'length' => $length,
    ])
        ->assertOk()
        ->assertJsonPath('length', $length);
})->with([
    'minimum' => 1,
    'normal page' => 25,
    'maximum' => 100,
]);

test('malformed datatable paging inputs cannot bypass the server limit', function (array $payload, int $expectedLength, int $expectedStart): void {
    $this->postJson('/__tests/data-table-page-length', $payload)
        ->assertOk()
        ->assertJsonPath('pagination', true)
        ->assertJsonPath('length', $expectedLength)
        ->assertJsonPath('start', $expectedStart);
})->with([
    'missing draw' => [['start' => 0, 'length' => 300], 100, 0],
    'missing start' => [['draw' => 1, 'length' => 300], 100, 0],
    'invalid draw' => [['draw' => 'latest', 'start' => 0, 'length' => -1], 100, 0],
    'negative start' => [['draw' => 1, 'start' => -1, 'length' => 300], 100, 0],
    'non numeric start' => [['draw' => 1, 'start' => 'beginning', 'length' => -1], 100, 0],
    'non numeric length' => [['draw' => 1, 'start' => 0, 'length' => 'everything'], 10, 0],
    'unsupported negative length' => [['draw' => 1, 'start' => 0, 'length' => -2], 10, 0],
]);

test('datatable requests missing a length get a bounded default', function (): void {
    $this->postJson('/__tests/data-table-page-length', [
        'draw' => 1,
        'start' => 0,
    ])
        ->assertOk()
        ->assertJsonPath('length', 10);
});

test('the Yajra request binding is bounded without mutating the base request', function (): void {
    $this->postJson('/__tests/data-table-page-length', [])
        ->assertOk()
        ->assertJsonPath('pagination', true)
        ->assertJsonPath('start', 0)
        ->assertJsonPath('length', 10)
        ->assertJsonPath('request_class', BoundedDataTableRequest::class);
});

test('query string datatable requests are capped through the registered web middleware', function (): void {
    $this->getJson('/__tests/data-table-page-length?draw=1&start=0&length=300')
        ->assertOk()
        ->assertJsonPath('length', 100);
});

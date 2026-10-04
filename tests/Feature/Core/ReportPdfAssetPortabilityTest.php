<?php

use Illuminate\Support\Facades\Storage;
use Modules\Core\Services\Reports\ReportPdfService;

require_once dirname(__DIR__, 2).'/SalesCycleSupport.php';

test('a historical Windows PDF logo resolves to the same public storage asset after a local restore', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('company-logos/synthetic-portable.png', file_get_contents(public_path('assets/img/logos/nvidia.png')));
    $payload = ['title' => 'Synthetic portability acceptance', 'mode' => 'summary', 'rows' => [], 'headings' => [],
        'companyPrintIdentity' => ['legal_name' => 'Synthetic fixture identity',
            'logo_source' => 'D:\\old-server\\ERP\\storage\\app/public/company-logos/synthetic-portable.png']];
    $before = $payload['companyPrintIdentity'];
    $response = app(ReportPdfService::class)->stream('reports.products-data', $payload, 'synthetic-portability.pdf');
    expect(salesPdfImageCount($response->getContent()))->toBeGreaterThan(0)
        ->and($payload['companyPrintIdentity'])->toBe($before);
    foreach (['D:\\old\\ERP\\storage\\app/public/company-logos/missing.png',
        'D:\\old\\ERP\\storage\\app/public/../company-logos/synthetic-portable.png',
        'https://example.invalid/storage/app/public/company-logos/synthetic-portable.png'] as $source) {
        $payload['companyPrintIdentity']['logo_source'] = $source;
        $response = app(ReportPdfService::class)->stream('reports.products-data', $payload, 'synthetic-portability.pdf');
        expect(salesPdfImageCount($response->getContent()))->toBe(0);
    }
});

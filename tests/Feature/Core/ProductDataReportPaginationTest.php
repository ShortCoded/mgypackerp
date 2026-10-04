<?php

use Modules\Core\Services\Reports\ProductDataReport;
use Modules\Core\Services\Reports\ReportPdfService;
use Symfony\Component\Process\Process;

test('product PDF pagination retains each item with its complete details without orphan pages', function (string $locale, string $mode): void {
    app()->setLocale($locale);
    $headings = app(ProductDataReport::class)->headings(['result_mode' => $mode]);
    $rows = [];
    foreach (range(1, 25) as $index) {
        $row = array_fill(0, count($headings), 'Synthetic acceptance attribute with a readable value');
        $row[0] = sprintf('SYN%04d', $index);
        $row[1] = 'SYNTHETIC finished product with full manufacturing description';
        $row[$mode === 'summary' ? 3 : 11] = sprintf('PAIR%04d', $index);
        $rows[] = $row;
    }
    $response = app(ReportPdfService::class)->stream('reports.products-data', [
        'title' => 'SYNTHETIC product pagination acceptance',
        'companyPrintIdentity' => ['legal_name' => 'SYNTHETIC acceptance company'],
        'rows' => $rows,
        'headings' => $headings,
        'mode' => $mode,
        'filters' => [],
    ], 'synthetic-product-pagination.pdf');
    $path = tempnam(sys_get_temp_dir(), 'mgypack-product-pagination-');
    try {
        file_put_contents($path, $response->getContent());
        $text = (new Process(['pdftotext', '-layout', $path, '-']))->mustRun()->getOutput();
        $pages = array_values(array_filter(explode("\f", $text), fn (string $page): bool => trim($page) !== ''));
        expect(count($pages))->toBeGreaterThan(2);
        foreach ($pages as $page) {
            expect($page)->toMatch('/SYN\d{4}/')->toMatch('/PAIR\d{4}/');
        }
        foreach (range(1, 25) as $index) {
            $identifier = sprintf('SYN%04d', $index);
            $detail = sprintf('PAIR%04d', $index);
            $itemPages = array_values(array_filter($pages, fn (string $page): bool => str_contains($page, $identifier)));
            expect($itemPages)->toHaveCount(1);
            expect($itemPages[0])->toContain($detail);
            expect(substr_count($text, $identifier))->toBe(1)
                ->and(substr_count($text, $detail))->toBe(1);
        }
    } finally {
        unlink($path);
    }
})->with([
    ['ar', 'summary'], ['en', 'summary'], ['ar', 'detailed'], ['en', 'detailed'],
]);

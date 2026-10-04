<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\ExcelImportService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockLine;
use Modules\Inventory\Models\OpeningStockPricing;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Throwable;

class OpeningStockEstimateImportService
{
    private const Headers = ['opening_stock_line_public_id', 'product_code', 'quantity', 'unit_price'];

    public function __construct(
        private readonly ExcelImportService $excelImports,
        private readonly NumericFormatService $numbers,
        private readonly OpeningStockPricingService $pricing,
        private readonly OperatingContextService $operatingContext,
    ) {}

    public function template(Request $request, string $openingStockDocNum): string
    {
        $source = $this->source($request, $openingStockDocNum);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Opening stock prices');
        foreach (self::Headers as $index => $header) {
            $sheet->setCellValueExplicit([$index + 1, 1], $header, DataType::TYPE_STRING);
        }
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        $sheet->getColumnDimension('A')->setWidth(42);
        $sheet->getColumnDimension('B')->setWidth(26);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(18);

        foreach ($source->lines()->with('product')->where('quantity', '>', 0)->orderBy('id')->get() as $index => $line) {
            $row = $index + 2;
            $sheet->setCellValueExplicit([1, $row], (string) $line->public_id, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([2, $row], (string) $line->product?->doc_num, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([3, $row], (string) $line->quantity, DataType::TYPE_STRING);
            $sheet->getStyle('D'.$row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }

        $path = tempnam(storage_path('app'), 'opening-stock-estimate-');
        if ($path === false) {
            throw new DomainException(__('inventory.opening_stock_pricings.messages.estimate_import_invalid'));
        }

        try {
            (new XlsxWriter($spreadsheet))->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return $path;
    }

    public function import(Request $request, UploadedFile $workbook, array $data): OpeningStockPricing
    {
        $this->excelImports->assertSafeUpload($workbook);
        $source = $this->source($request, (string) $data['opening_stock_doc_num']);
        $this->assertHeader($source, $data);
        $lines = $this->readLines($workbook, $source);
        $checksum = hash_file('sha256', $workbook->getRealPath());
        if (! is_string($checksum)) {
            throw ValidationException::withMessages(['workbook' => __('inventory.opening_stock_pricings.messages.estimate_import_invalid')]);
        }

        $storedPath = null;
        try {
            return DB::transaction(function () use ($request, $workbook, $data, $lines, $checksum, &$storedPath): OpeningStockPricing {
                $result = $this->pricing->create([
                    'document_date' => $data['document_date'],
                    'opening_stock_doc_num' => $data['opening_stock_doc_num'],
                    'currency_doc_num' => $data['currency_doc_num'],
                    'exchange_rate' => $data['exchange_rate'],
                    'estimate_basis_note' => $data['estimate_basis_note'],
                    'pricing_basis' => OpeningStockPricing::BasisEstimate,
                    'lines' => $lines,
                ], $request);
                $record = $result['record'];
                $storedPath = Storage::disk('local')->putFileAs(
                    'opening-stock-pricing-sources',
                    $workbook,
                    Str::uuid().'.xlsx',
                );
                if (! is_string($storedPath)) {
                    throw new DomainException(__('inventory.opening_stock_pricings.messages.estimate_import_invalid'));
                }
                $fileName = preg_replace('/[^A-Za-z0-9._-]+/u', '-', $workbook->getClientOriginalName());
                $record->forceFill([
                    'source_file_path' => $storedPath,
                    'source_file_sha256' => $checksum,
                    'source_file_name' => substr((string) $fileName, 0, 180),
                ])->save();

                return $record->refresh();
            });
        } catch (Throwable $exception) {
            if (is_string($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }
    }

    private function source(Request $request, string $docNum): OpeningStock
    {
        $context = $this->operatingContext->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'], 404);
        $allowedBranches = $this->operatingContext->allowedBranchQueryForCurrentCompany($request)
            ->where('branches.status', 'active')
            ->whereIn('branches.type', [Branch::TypeWarehouse, Branch::TypeFactory])
            ->reorder()
            ->select('branches.id');

        return OpeningStock::query()
            ->where('doc_num', $docNum)
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->whereIn('branch_id', $allowedBranches)
            ->where('approved', true)
            ->where('status', OpeningStock::StatusApproved)
            ->firstOrFail();
    }

    private function assertHeader(OpeningStock $source, array $data): void
    {
        $period = FinancialPeriod::query()->find($source->financial_period_id);
        $currency = Currency::query()->active()->forCompany((int) $source->company_id)
            ->where('doc_num', $data['currency_doc_num'])->first();
        $date = $data['document_date'];
        if (! $period || $period->is_closed || ! $period->allows_opening_entries
            || $date < $period->from_date->toDateString() || $date > $period->to_date->toDateString()
            || ! $currency || ! preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D', (string) $data['exchange_rate'])
            || bccomp((string) $data['exchange_rate'], '0', 6) <= 0
            || ($currency->is_main && bccomp((string) $data['exchange_rate'], '1', 6) !== 0)) {
            throw ValidationException::withMessages(['workbook' => __('inventory.opening_stock_pricings.messages.estimate_import_invalid')]);
        }
    }

    /** @return list<array{opening_stock_line_public_id: string, unit_price: string}> */
    private function readLines(UploadedFile $workbook, OpeningStock $source): array
    {
        $reader = new XlsxReader;
        $reader->setReadDataOnly(false);
        try {
            $spreadsheet = $reader->load($workbook->getRealPath());
        } catch (Throwable) {
            throw ValidationException::withMessages(['workbook' => __('inventory.opening_stock_pricings.messages.estimate_import_invalid')]);
        }

        try {
            $sheet = $spreadsheet->getSheet(0);
            $lastRow = $sheet->getHighestDataRow();
            if ($spreadsheet->getSheetCount() !== 1 || $lastRow > (int) config('excel_imports.max_rows') + 1
                || $sheet->getHighestDataColumn() !== 'D') {
                throw ValidationException::withMessages(['workbook' => __('inventory.opening_stock_pricings.messages.estimate_import_invalid')]);
            }
            foreach (self::Headers as $index => $header) {
                if (trim((string) $sheet->getCell([$index + 1, 1])->getValue()) !== $header) {
                    throw ValidationException::withMessages(['workbook' => __('inventory.opening_stock_pricings.messages.estimate_import_invalid')]);
                }
            }

            $sourceLines = $source->lines()->with('product')->where('quantity', '>', 0)->get()->keyBy('public_id');
            $seen = [];
            $lines = [];
            for ($row = 2; $row <= $lastRow; $row++) {
                $cells = [];
                for ($column = 1; $column <= 4; $column++) {
                    $cell = $sheet->getCell([$column, $row]);
                    if ($cell->isFormula()) {
                        throw ValidationException::withMessages(['workbook' => __('inventory.opening_stock_pricings.messages.estimate_import_invalid')]);
                    }
                    $value = $cell->getValue();
                    if ($column === 4 && (is_int($value) || is_float($value))) {
                        try {
                            $value = $this->numbers->normalizeScientificNotation((string) $value);
                        } catch (\InvalidArgumentException) {
                            throw ValidationException::withMessages(['workbook' => __('inventory.opening_stock_pricings.messages.estimate_import_row_invalid', ['row' => $row])]);
                        }
                        if (strlen(ltrim(str_replace('.', '', (string) $value), '0')) > 15) {
                            throw ValidationException::withMessages(['workbook' => __('inventory.opening_stock_pricings.messages.estimate_import_row_invalid', ['row' => $row])]);
                        }
                    }
                    $cells[] = trim((string) $value);
                }
                if (implode('', $cells) === '') {
                    continue;
                }
                [$publicId, $productCode, $quantity, $price] = $cells;
                $line = $sourceLines->get($publicId);
                if (! $line instanceof OpeningStockLine || isset($seen[$publicId])
                    || $productCode !== (string) $line->product?->doc_num
                    || ! preg_match('/^\d{1,11}(?:\.\d{1,4})?$/D', $quantity)
                    || bccomp($quantity, (string) $line->quantity, 4) !== 0
                    || ($price !== '' && (! preg_match('/^\d{1,11}(?:\.\d{1,8})?$/D', $price)
                        || bccomp($price, '0', 8) <= 0))) {
                    throw ValidationException::withMessages(['workbook' => __('inventory.opening_stock_pricings.messages.estimate_import_row_invalid', ['row' => $row])]);
                }
                $seen[$publicId] = true;
                if ($price !== '') {
                    $lines[] = ['opening_stock_line_public_id' => $publicId, 'unit_price' => $price];
                }
            }
            if ($lines === []) {
                throw ValidationException::withMessages(['workbook' => __('inventory.opening_stock_pricings.messages.estimate_import_invalid')]);
            }

            return $lines;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }
}

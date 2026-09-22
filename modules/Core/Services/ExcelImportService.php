<?php

namespace Modules\Core\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Imports\Contracts\ExcelImportDefinition;
use Modules\Core\Imports\ProductExcelImportDefinition;
use Modules\Core\Models\ExcelImportBatch;
use Modules\Core\Models\ExcelImportRow;
use Modules\FixedAssets\Imports\FixedAssetExcelImportDefinition;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use RuntimeException;
use Throwable;
use ZipArchive;

class ExcelImportService
{
    public function __construct(
        private readonly ProductExcelImportDefinition $products,
        private readonly FixedAssetExcelImportDefinition $fixedAssets,
        private readonly OperatingCompanyContextService $companies,
        private readonly OperatingContextService $operatingContext,
    ) {}

    public function definition(string $module): ExcelImportDefinition
    {
        return match ($module) {
            ExcelImportBatch::ModuleProducts => $this->products,
            ExcelImportBatch::ModuleFixedAssets => $this->fixedAssets,
            default => throw new RuntimeException('Unsupported Excel import module.'),
        };
    }

    public function authorize(Request $request, string $module): void
    {
        $definition = $this->definition($module);
        $prefix = $definition->permissionPrefix();

        abort_unless(
            (bool) $request->user()?->can("{$prefix}.import")
            && (bool) $request->user()?->can("{$prefix}.create"),
            403,
        );
    }

    public function createTemplate(string $module, Request $request): string
    {
        $definition = $this->definition($module);
        $companyId = $this->companies->requireCompanyId($request);
        $path = tempnam(storage_path('app'), 'excel-template-');

        if ($path === false) {
            throw new RuntimeException('Unable to create the Excel template file.');
        }

        $spreadsheet = $this->templateSpreadsheet($definition, $companyId, $request);

        try {
            (new XlsxWriter($spreadsheet))->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return $path;
    }

    public function storeAndValidate(string $module, UploadedFile $file, Request $request): ExcelImportBatch
    {
        $definition = $this->definition($module);
        $this->assertSafeUpload($file);
        $companyId = $this->companies->requireCompanyId($request);
        $context = $this->contextSnapshot($request, $companyId);
        $batch = ExcelImportBatch::query()->create([
            'module' => $definition->module(),
            'template_version' => $definition->templateVersion(),
            'owner_user_id' => $request->user()?->getKey(),
            'company_id' => $companyId,
            'context_snapshot' => $context,
            'original_filename' => $this->safeOriginalFilename($file),
            'private_path' => '',
            'checksum' => '',
            'status' => ExcelImportBatch::StatusUploaded,
            'expires_at' => now()->addHours((int) config('excel_imports.expires_after_hours')),
        ]);

        $path = 'excel-imports/'.$batch->public_uuid.'/source.xlsx';
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));
        $fullPath = Storage::disk('local')->path($path);
        $batch->forceFill([
            'private_path' => $path,
            'checksum' => hash_file('sha256', $fullPath),
            'status' => ExcelImportBatch::StatusValidating,
        ])->save();

        try {
            $this->validateBatch($batch, $request, $definition);
        } catch (Throwable $exception) {
            report($exception);
            $this->recordWorkbookFailure($batch, __('excel_imports.validation.invalid_workbook'));
        }

        return $batch->refresh();
    }

    public function replace(ExcelImportBatch $batch, UploadedFile $file, Request $request): ExcelImportBatch
    {
        $this->assertBatchAccess($batch, $request);
        abort_if($batch->status === ExcelImportBatch::StatusImporting, 409);

        DB::transaction(function () use ($batch): void {
            $batch->forceFill(['status' => ExcelImportBatch::StatusCancelled])->save();
        });
        Storage::disk('local')->delete($batch->private_path);

        return $this->storeAndValidate($batch->module, $file, $request);
    }

    public function cancel(ExcelImportBatch $batch, Request $request): void
    {
        $this->assertBatchAccess($batch, $request);
        abort_if($batch->status === ExcelImportBatch::StatusImporting, 409);

        $batch->forceFill(['status' => ExcelImportBatch::StatusCancelled])->save();
        Storage::disk('local')->delete($batch->private_path);
    }

    /**
     * @return array{batch: ExcelImportBatch, rows: LengthAwarePaginator}
     */
    public function review(ExcelImportBatch $batch, Request $request): array
    {
        $this->assertBatchAccess($batch, $request);
        $definition = $this->definition($batch->module);
        $query = $batch->rows()->orderBy('sheet_key')->orderBy('excel_row');
        $filter = $request->string('filter')->trim()->toString();
        $search = $request->string('q')->trim()->toString();

        if ($filter === 'valid') {
            $query->where('status', ExcelImportRow::StatusValid);
        } elseif ($filter === 'errors') {
            $query->where('status', ExcelImportRow::StatusInvalid);
        } elseif ($filter === 'warnings') {
            $query->whereRaw('1 = 0');
        }

        if ($search !== '') {
            $query->whereRaw('LOWER(CAST(data AS TEXT)) LIKE ?', ['%'.mb_strtolower($search).'%']);
        }

        return [
            'batch' => $batch,
            'rows' => $query->paginate(50)->withQueryString(),
            'definition' => $definition,
        ];
    }

    public function errorWorkbook(ExcelImportBatch $batch, Request $request): string
    {
        $this->assertBatchAccess($batch, $request);
        abort_unless($batch->error_rows > 0, 404);

        $definition = $this->definition($batch->module);
        $path = tempnam(storage_path('app'), 'excel-errors-');

        if ($path === false) {
            throw new RuntimeException('Unable to create the Excel error workbook.');
        }

        $spreadsheet = $this->templateSpreadsheet($definition, (int) $batch->company_id, $request, $batch->context_snapshot);
        $fieldSets = [$definition->dataSheet() => $definition->fields()];
        if ($definition instanceof ProductExcelImportDefinition) {
            $fieldSets[$definition->componentSheet()] = $definition->componentFields();
        }

        foreach ($batch->rows()->orderBy('sheet_key')->orderBy('excel_row')->get() as $row) {
            $fields = $fieldSets[$row->sheet_key] ?? null;
            $sheet = $spreadsheet->getSheetByName($row->sheet_key);
            if ($fields === null || $sheet === null) {
                continue;
            }
            $column = 1;
            foreach (array_keys($fields) as $key) {
                $coordinate = Coordinate::stringFromColumnIndex($column).$row->excel_row;
                $this->writeSafeString($sheet, $coordinate, $row->data[$key] ?? null);
                $column++;
            }
            foreach ($row->issues ?? [] as $issue) {
                $key = $issue['column'] ?? null;
                $index = is_string($key) ? array_search($key, array_keys($fields), true) : false;
                if ($index !== false) {
                    $sheet->getStyle(Coordinate::stringFromColumnIndex($index + 1).$row->excel_row)
                        ->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()
                        ->setARGB('FFF8D7DA');
                }
            }
        }

        $errorsSheet = $spreadsheet->createSheet();
        $errorsSheet->setTitle('Validation Errors');
        $headers = [__('excel_imports.errors.sheet'), __('excel_imports.errors.row'), __('excel_imports.errors.column'), __('excel_imports.errors.value'), __('excel_imports.errors.message'), __('excel_imports.errors.code')];
        foreach ($headers as $index => $header) {
            $this->writeSafeString($errorsSheet, Coordinate::stringFromColumnIndex($index + 1).'1', $header);
        }
        $errorsSheet->getStyle('A1:F1')->getFont()->setBold(true);
        $errorRow = 2;
        foreach ($batch->rows()->where('status', ExcelImportRow::StatusInvalid)->orderBy('sheet_key')->orderBy('excel_row')->get() as $row) {
            $fields = $fieldSets[$row->sheet_key] ?? [];
            foreach ($row->issues ?? [] as $issue) {
                $key = (string) ($issue['column'] ?? '');
                $label = $fields[$key]['label'] ?? $key;
                $values = [$row->sheet_key, (string) $row->excel_row, $label, $row->data[$key] ?? null, $issue['message'] ?? '', $issue['code'] ?? ''];
                foreach ($values as $index => $value) {
                    $this->writeSafeString($errorsSheet, Coordinate::stringFromColumnIndex($index + 1).$errorRow, $value);
                }
                $errorRow++;
            }
        }
        foreach (range('A', 'F') as $column) {
            $errorsSheet->getColumnDimension($column)->setWidth(24);
        }
        $errorsSheet->freezePane('A2');

        try {
            (new XlsxWriter($spreadsheet))->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return $path;
    }

    /**
     * @return list<array{sheet_key: string, excel_row: int, import_key?: string|null, doc_num: string, name: string}>|null
     */
    public function commit(ExcelImportBatch $batch, Request $request): ?array
    {
        $this->assertBatchAccess($batch, $request);
        $definition = $this->definition($batch->module);
        $result = null;

        $startedImporting = false;

        try {
            DB::transaction(function () use ($batch, $request, $definition, &$result, &$startedImporting): void {
                /** @var ExcelImportBatch $lockedBatch */
                $lockedBatch = ExcelImportBatch::query()->lockForUpdate()->findOrFail($batch->getKey());
                $this->assertBatchAccess($lockedBatch, $request);
                if ($lockedBatch->status === ExcelImportBatch::StatusImported) {
                    $result = $lockedBatch->result_summary ?? [];

                    return;
                }
                abort_unless($lockedBatch->isReady(), 422);
                $lockedBatch->forceFill(['status' => ExcelImportBatch::StatusImporting])->save();
                $startedImporting = true;

                $this->validateBatch($lockedBatch, $request, $definition, lockRows: true);
                $lockedBatch->refresh();
                if (! $lockedBatch->isReady()) {
                    $result = null;

                    return;
                }

                $created = $definition->commit($lockedBatch, $request);
                $lockedBatch->forceFill([
                    'status' => ExcelImportBatch::StatusImported,
                    'created_result_count' => count($created),
                    'result_summary' => $created,
                ])->save();
                $result = $created;
            });
        } catch (Throwable $exception) {
            if ($startedImporting) {
                $batch->refresh()->forceFill(['status' => ExcelImportBatch::StatusFailed])->save();
            }
            throw $exception;
        }

        return $result;
    }

    private function validateBatch(ExcelImportBatch $batch, Request $request, ExcelImportDefinition $definition, bool $lockRows = false): void
    {
        if (! $this->batchContextMatches($batch, $request) || ! $this->checksumMatches($batch)) {
            $this->recordWorkbookFailure($batch, __('excel_imports.validation.context_or_file_changed'));

            return;
        }

        $parsed = $this->parseWorkbook(Storage::disk('local')->path($batch->private_path), $definition);
        $validatedRows = $definition->validateRows($parsed['rows'], $request);
        $parserIssues = $parsed['issues'];
        $byPosition = [];
        foreach ($validatedRows as &$row) {
            $key = $row['sheet_key'].':'.$row['excel_row'];
            $row['issues'] = [...$row['issues'], ...($parserIssues[$key] ?? [])];
            $byPosition[$key] = $row;
        }
        unset($row);

        foreach ($parsed['workbook_issues'] as $issue) {
            $validatedRows[] = [
                'sheet_key' => 'Workbook',
                'excel_row' => 0,
                'data' => [],
                'normalized_data' => null,
                'import_key' => null,
                'issues' => [$issue],
            ];
        }

        $existingRows = $batch->rows();
        if ($lockRows) {
            $existingRows->lockForUpdate();
        }
        $existing = $existingRows->get()->keyBy(fn (ExcelImportRow $row): string => $row->sheet_key.':'.$row->excel_row);

        foreach ($validatedRows as $row) {
            $issues = $row['issues'];
            $values = [
                'status' => $issues === [] ? ExcelImportRow::StatusValid : ExcelImportRow::StatusInvalid,
                'import_key' => $row['import_key'],
                'data' => $row['data'],
                'normalized_data' => $issues === [] ? $row['normalized_data'] : null,
                'issues' => $issues === [] ? null : $issues,
            ];
            $position = $row['sheet_key'].':'.$row['excel_row'];
            $existingRow = $existing->get($position);
            if ($existingRow instanceof ExcelImportRow) {
                $existingRow->forceFill($values)->save();
            } else {
                $batch->rows()->create([
                    'sheet_key' => $row['sheet_key'],
                    'excel_row' => $row['excel_row'],
                    ...$values,
                ]);
            }
        }

        $validRows = $batch->rows()->where('status', ExcelImportRow::StatusValid)->count();
        $errorRows = $batch->rows()->where('status', ExcelImportRow::StatusInvalid)->count();
        $batch->forceFill([
            'total_rows' => $batch->rows()->count(),
            'valid_rows' => $validRows,
            'error_rows' => $errorRows,
            'warning_count' => 0,
            'status' => $errorRows === 0 ? ExcelImportBatch::StatusReady : ExcelImportBatch::StatusInvalid,
        ])->save();
    }

    /**
     * @return array{rows: array<string, list<array{excel_row: int, data: array<string, string|null>}>>, issues: array<string, list<array<string, mixed>>>, workbook_issues: list<array<string, mixed>>}
     */
    private function parseWorkbook(string $path, ExcelImportDefinition $definition): array
    {
        $reader = new XlsxReader;
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($path);
        $issues = [];
        $workbookIssues = [];

        try {
            $sheetNames = $spreadsheet->getSheetNames();
            if (count($sheetNames) > (int) config('excel_imports.max_sheets') || $sheetNames !== $definition->expectedSheets()) {
                $workbookIssues[] = $this->issue(null, 'workbook.sheets.invalid', __('excel_imports.validation.expected_sheets'));
            }

            $meta = $spreadsheet->getSheetByName('Meta');
            if ($meta === null
                || trim((string) $meta->getCell('B1')->getValue()) !== $definition->module()
                || trim((string) $meta->getCell('B2')->getValue()) !== $definition->templateVersion()
            ) {
                $workbookIssues[] = $this->issue(null, 'workbook.template.invalid', __('excel_imports.validation.wrong_template'));
            }

            $rows = [];
            $sheetFields = [$definition->dataSheet() => $definition->fields()];
            if ($definition instanceof ProductExcelImportDefinition) {
                $sheetFields[$definition->componentSheet()] = $definition->componentFields();
            }

            foreach ($sheetFields as $sheetName => $fields) {
                $sheet = $spreadsheet->getSheetByName($sheetName);
                if ($sheet === null) {
                    continue;
                }
                $expectedKeys = array_keys($fields);
                $technicalHeaders = [];
                foreach ($expectedKeys as $column => $key) {
                    $technicalHeaders[] = trim((string) $sheet->getCellByColumnAndRow($column + 1, 1)->getValue());
                }
                if ($technicalHeaders !== $expectedKeys) {
                    $workbookIssues[] = $this->issue(null, "{$sheetName}.headers.invalid", __('excel_imports.validation.technical_headers'));

                    continue;
                }

                $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
                if ($highestColumn > count($expectedKeys)) {
                    for ($rowNumber = 1; $rowNumber <= $sheet->getHighestDataRow(); $rowNumber++) {
                        for ($column = count($expectedKeys) + 1; $column <= $highestColumn; $column++) {
                            if (trim((string) $sheet->getCellByColumnAndRow($column, $rowNumber)->getValue()) !== '') {
                                $workbookIssues[] = $this->issue(null, "{$sheetName}.range.unsupported", __('excel_imports.validation.unsupported_data_range'));
                                break 2;
                            }
                        }
                    }
                }

                for ($rowNumber = 3; $rowNumber <= $sheet->getHighestDataRow(); $rowNumber++) {
                    $data = [];
                    $hasValue = false;
                    foreach ($expectedKeys as $column => $key) {
                        $cell = $sheet->getCellByColumnAndRow($column + 1, $rowNumber);
                        $value = $cell->getValue();
                        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                            $issues["{$sheetName}:{$rowNumber}"][] = $this->issue($key, 'cell.formula', __('excel_imports.validation.formulas_not_allowed'));
                        }
                        $normalized = $this->cellValue($cell, $key);
                        if ($normalized !== null && $normalized !== '') {
                            $hasValue = true;
                        }
                        if ($normalized !== null && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $normalized) === 1) {
                            $issues["{$sheetName}:{$rowNumber}"][] = $this->issue($key, 'cell.control_character', __('excel_imports.validation.control_characters'));
                        }
                        if ($normalized !== null && preg_match('/<\s*\/?\s*(script|style|iframe|object|embed)\b/i', $normalized) === 1) {
                            $issues["{$sheetName}:{$rowNumber}"][] = $this->issue($key, 'cell.html', __('excel_imports.validation.html_not_allowed'));
                        }
                        $data[$key] = $normalized;
                    }
                    if ($hasValue) {
                        $rows[$sheetName][] = ['excel_row' => $rowNumber, 'data' => $data];
                    }
                }
            }

            $rowCount = array_sum(array_map('count', $rows));
            if ($rowCount > (int) config('excel_imports.max_rows')) {
                $workbookIssues[] = $this->issue(null, 'workbook.rows.too_many', __('excel_imports.validation.max_rows'));
            }

            return ['rows' => $rows, 'issues' => $issues, 'workbook_issues' => $workbookIssues];
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function assertSafeUpload(UploadedFile $file): void
    {
        $path = $file->getRealPath();
        $mime = $file->getMimeType();
        if (! in_array($mime, ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/x-zip-compressed'], true)) {
            throw ValidationException::withMessages(['workbook' => __('excel_imports.validation.invalid_workbook')]);
        }
        $signature = is_string($path) ? file_get_contents($path, false, null, 0, 4) : false;
        if ($signature !== "PK\x03\x04") {
            throw ValidationException::withMessages(['workbook' => __('excel_imports.validation.invalid_workbook')]);
        }
        if (! class_exists(ZipArchive::class)) {
            throw ValidationException::withMessages(['workbook' => __('excel_imports.validation.zip_unavailable')]);
        }

        $zip = new ZipArchive;
        if (! is_string($path) || $zip->open($path) !== true) {
            throw ValidationException::withMessages(['workbook' => __('excel_imports.validation.invalid_workbook')]);
        }

        try {
            if ($zip->numFiles > (int) config('excel_imports.max_sheets') * 100) {
                throw ValidationException::withMessages(['workbook' => __('excel_imports.validation.invalid_workbook')]);
            }
            $uncompressedBytes = 0;
            $hasWorkbook = false;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = (string) ($stat['name'] ?? '');
                if (str_contains($name, '../') || str_starts_with($name, '/')) {
                    throw ValidationException::withMessages(['workbook' => __('excel_imports.validation.invalid_workbook')]);
                }
                if (preg_match('/\.(?:exe|php|phtml|phar|sh|bat|cmd|js)$/i', $name) === 1) {
                    throw ValidationException::withMessages(['workbook' => __('excel_imports.validation.unsupported_workbook_content')]);
                }
                if (isset($stat['encryption_method']) && (int) $stat['encryption_method'] !== 0) {
                    throw ValidationException::withMessages(['workbook' => __('excel_imports.validation.unsupported_workbook_content')]);
                }
                $uncompressedBytes += (int) ($stat['size'] ?? 0);
                if ($uncompressedBytes > (int) config('excel_imports.max_uncompressed_bytes')) {
                    throw ValidationException::withMessages(['workbook' => __('excel_imports.validation.workbook_too_large')]);
                }
                if (str_starts_with($name, 'xl/externalLinks/') || str_contains($name, 'vbaProject.bin')) {
                    throw ValidationException::withMessages(['workbook' => __('excel_imports.validation.unsupported_workbook_content')]);
                }
                $hasWorkbook = $hasWorkbook || $name === 'xl/workbook.xml';
            }
            $contentTypes = (string) $zip->getFromName('[Content_Types].xml');
            if (! $hasWorkbook || str_contains(strtolower($contentTypes), 'macroenabled')) {
                throw ValidationException::withMessages(['workbook' => __('excel_imports.validation.unsupported_workbook_content')]);
            }
        } finally {
            $zip->close();
        }
    }

    private function assertBatchAccess(ExcelImportBatch $batch, Request $request): void
    {
        $this->authorize($request, $batch->module);
        abort_unless((int) $batch->owner_user_id === (int) $request->user()?->getKey(), 404);
        if ($batch->expires_at !== null && $batch->expires_at->isPast()) {
            if (! in_array($batch->status, [ExcelImportBatch::StatusImported, ExcelImportBatch::StatusCancelled], true)) {
                $batch->forceFill(['status' => ExcelImportBatch::StatusExpired])->save();
            }

            abort(410);
        }
    }

    private function recordWorkbookFailure(ExcelImportBatch $batch, string $message): void
    {
        $batch->rows()->delete();
        $batch->rows()->create([
            'sheet_key' => 'Workbook',
            'excel_row' => 0,
            'status' => ExcelImportRow::StatusInvalid,
            'data' => [],
            'issues' => [$this->issue(null, 'workbook.invalid', $message)],
        ]);
        $batch->forceFill([
            'status' => ExcelImportBatch::StatusInvalid,
            'total_rows' => 1,
            'valid_rows' => 0,
            'error_rows' => 1,
            'warning_count' => 0,
        ])->save();
    }

    private function checksumMatches(ExcelImportBatch $batch): bool
    {
        if ($batch->private_path === '' || ! Storage::disk('local')->exists($batch->private_path)) {
            return false;
        }

        return hash_equals($batch->checksum, hash_file('sha256', Storage::disk('local')->path($batch->private_path)) ?: '');
    }

    private function batchContextMatches(ExcelImportBatch $batch, Request $request): bool
    {
        $current = $this->contextSnapshot($request, $this->companies->requireCompanyId($request));

        foreach (['company_id', 'branch_id', 'financial_period_id'] as $key) {
            if (($batch->context_snapshot[$key] ?? null) !== ($current[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, int|string|null>
     */
    private function contextSnapshot(Request $request, int $companyId): array
    {
        $context = $this->operatingContext->snapshot($request);

        return [
            'company_id' => $companyId,
            'company_doc_num' => $context['company_doc_num'] ?? null,
            'branch_id' => $context['branch_id'] ?? null,
            'branch_doc_num' => $context['branch_doc_num'] ?? null,
            'financial_period_id' => $context['financial_period_id'] ?? null,
            'financial_period_doc_num' => $context['financial_period_doc_num'] ?? null,
        ];
    }

    private function templateSpreadsheet(ExcelImportDefinition $definition, int $companyId, Request $request, ?array $context = null): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $locale = app()->getLocale();
        $spreadsheet->getProperties()->setTitle(__('excel_imports.template.title', ['module' => __('excel_imports.modules.'.$definition->module())]));
        $instructions = $spreadsheet->getActiveSheet();
        $instructions->setTitle('Instructions');
        $instructions->setRightToLeft($locale === 'ar');
        $this->writeSafeString($instructions, 'A1', __('excel_imports.template.instructions_title'));
        $instructions->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        foreach ($definition->instructions() as $index => $instruction) {
            $this->writeSafeString($instructions, 'A'.($index + 3), ($index + 1).'. '.$instruction);
        }
        $instructions->getColumnDimension('A')->setWidth(120);

        $fieldsBySheet = [$definition->dataSheet() => $definition->fields()];
        if ($definition instanceof ProductExcelImportDefinition) {
            $fieldsBySheet[$definition->componentSheet()] = $definition->componentFields();
        }

        foreach ($fieldsBySheet as $sheetName => $fields) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($sheetName);
            $sheet->setRightToLeft($locale === 'ar');
            $this->configureDataSheet($sheet, $fields);
        }

        $lookups = $spreadsheet->createSheet();
        $lookups->setTitle('Lookups');
        $lookups->setRightToLeft($locale === 'ar');
        $this->configureLookupSheet($spreadsheet, $lookups, $definition->lookups($companyId));

        $meta = $spreadsheet->createSheet();
        $meta->setTitle('Meta');
        $meta->setRightToLeft(false);
        $realContext = $context ?? $this->contextSnapshot($request, $companyId);
        $metadata = [
            ['module_key', $definition->module()],
            ['template_version', $definition->templateVersion()],
            ['generated_at', now()->toIso8601String()],
            ['locale', $locale],
            ['context_fingerprint', hash('sha256', json_encode($realContext, JSON_THROW_ON_ERROR))],
            ['expected_data_sheets', implode(',', array_keys($fieldsBySheet))],
        ];
        foreach ($metadata as $row => [$key, $value]) {
            $this->writeSafeString($meta, 'A'.($row + 1), $key);
            $this->writeSafeString($meta, 'B'.($row + 1), $value);
        }
        $lookups->getProtection()->setSheet(true);
        $meta->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @param  array<string, array{label: string, required: bool, width?: int, comment?: string, lookup?: string}>  $fields
     */
    private function configureDataSheet($sheet, array $fields): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex(count($fields));
        foreach ($fields as $index => $field) {
            $column = Coordinate::stringFromColumnIndex(array_search($index, array_keys($fields), true) + 1);
            $this->writeSafeString($sheet, $column.'1', $index);
            $this->writeSafeString($sheet, $column.'2', $field['label'].($field['required'] ? ' *' : ''));
            $sheet->getColumnDimension($column)->setWidth($field['width'] ?? 20);
            $sheet->getComment($column.'2')->setAuthor(config('app.name'));
            $sheet->getComment($column.'2')->setText(new RichText);
            $sheet->getComment($column.'2')->getText()->createText((string) ($field['comment'] ?? ''));
            if (in_array($index, ['asset_date', 'purchase_date', 'acquisition_date', 'operation_date', 'previous_depreciation_until_date'], true)) {
                $sheet->getStyle($column.'3:'.$column.((int) config('excel_imports.max_rows') + 2))
                    ->getNumberFormat()
                    ->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD2);
            } elseif (in_array($index, ['reorder_point', 'equivalent_value', 'quantity', 'percentage', 'purchase_value', 'exchange_rate', 'salvage_value', 'previous_depreciation', 'annual_depreciation_rate', 'expected_usage_units', 'useful_life'], true)) {
                $sheet->getStyle($column.'3:'.$column.((int) config('excel_imports.max_rows') + 2))
                    ->getNumberFormat()
                    ->setFormatCode('0.########');
            }
            if (isset($field['lookup'])) {
                $validation = $sheet->getDataValidation($column.'3');
                $validation->setType(DataValidation::TYPE_LIST);
                $validation->setErrorStyle(DataValidation::STYLE_STOP);
                $validation->setAllowBlank(! $field['required']);
                $validation->setShowDropDown(true);
                $validation->setShowErrorMessage(true);
                $validation->setFormula1('='.$field['lookup']);
                $sheet->setDataValidation($column.'3:'.$column.((int) config('excel_imports.max_rows') + 2), $validation);
            }
        }
        $sheet->getRowDimension(1)->setVisible(false);
        $sheet->getStyle("A2:{$lastColumn}2")->getFont()->setBold(true);
        $sheet->getStyle("A2:{$lastColumn}2")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE9ECEF');
        $sheet->getStyle("A2:{$lastColumn}2")->getAlignment()->setWrapText(true);
        $sheet->getStyle('A3:'.$lastColumn.((int) config('excel_imports.max_rows') + 2))->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
        $sheet->freezePane('A3');
        $sheet->setAutoFilter("A2:{$lastColumn}".((int) config('excel_imports.max_rows') + 2));
        $sheet->getProtection()->setSheet(true);
        $sheet->getProtection()->setSelectLockedCells(false);
        $sheet->getProtection()->setSelectUnlockedCells(true);
    }

    /**
     * @param  array<string, array{title: string, rows: list<array{reference: string, label: string}>}>  $lookups
     */
    private function configureLookupSheet(Spreadsheet $spreadsheet, $sheet, array $lookups): void
    {
        $column = 1;
        foreach ($lookups as $rangeName => $lookup) {
            $referenceColumn = Coordinate::stringFromColumnIndex($column);
            $labelColumn = Coordinate::stringFromColumnIndex($column + 1);
            $this->writeSafeString($sheet, $referenceColumn.'1', $lookup['title']);
            $this->writeSafeString($sheet, $labelColumn.'1', __('excel_imports.lookups.label'));
            foreach ($lookup['rows'] as $row => $value) {
                $this->writeSafeString($sheet, $referenceColumn.($row + 2), $value['reference']);
                $this->writeSafeString($sheet, $labelColumn.($row + 2), $value['label']);
            }
            $lastRow = max(2, count($lookup['rows']) + 1);
            $spreadsheet->addNamedRange(new NamedRange($rangeName, $sheet, '$'.$referenceColumn.'$2:$'.$referenceColumn.'$'.$lastRow));
            $sheet->getColumnDimension($referenceColumn)->setWidth(24);
            $sheet->getColumnDimension($labelColumn)->setWidth(36);
            $column += 3;
        }
    }

    private function cellValue($cell, string $key): ?string
    {
        $value = $cell->getValue();
        if ($value === null) {
            return null;
        }
        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
            return (string) $value;
        }
        if (in_array($key, ['asset_date', 'purchase_date', 'acquisition_date', 'operation_date', 'previous_depreciation_until_date'], true)
            && is_numeric($value)
            && Date::isDateTime($cell)) {
            return Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        return trim(str_replace(["\r\n", "\r"], "\n", (string) $cell->getFormattedValue()));
    }

    private function writeSafeString($sheet, string $coordinate, mixed $value): void
    {
        $value = $value === null ? '' : (string) $value;
        if (preg_match('/^[=+\-@]/', $value) === 1) {
            $value = "'{$value}";
        }
        $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
    }

    /**
     * @return array{column: string|null, code: string, message: string, severity: string}
     */
    private function issue(?string $column, string $code, string $message): array
    {
        return [
            'column' => $column,
            'code' => $code,
            'message' => $message,
            'severity' => 'error',
        ];
    }

    private function safeOriginalFilename(UploadedFile $file): string
    {
        $name = Str::of($file->getClientOriginalName())
            ->replaceMatches('/[^A-Za-z0-9._-]+/u', '-')
            ->trim('-')
            ->limit(180, '')
            ->toString();

        return $name === '' ? 'workbook.xlsx' : $name;
    }
}

<?php

namespace App\Console\Commands;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\Company;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\ProductDocumentNumberSettingsService;
use RuntimeException;
use SplFileObject;

class InventorySeedItemsCommand extends Command
{
    protected $signature = 'inventory:seed-items
        {--company-id= : Required company ID to seed inventory items for}
        {--dry-run : Preview changes and write reports without inserting or deleting}
        {--rollback : Rollback records inserted by this seed key}';

    protected $description = 'Seed inventory items from the Arabic TSV source file with unit resolution, classification, reports, and safe rollback.';

    private const SEED_KEY = 'inventory_items_initial_import_from_tsv';

    private const TRACKING_TABLE = 'seeded_reference_records';

    private const SOURCE_RELATIVE_PATH = 'database/seeders/data/inventory_items_seed.tsv';

    private const REPORT_DIRECTORY = 'seed-reports/inventory-items-import';

    /**
     * @var list<string>
     */
    private const SERVICE_KEYWORDS = [
        'مصنعية',
        'تنجيد',
        'نقل',
        'تركيب',
        'تصنيع',
        'تقطيع',
        'سي ان سي',
        'cnc',
        'مقاولة',
        'اعمالة',
        'عماله',
        'اعمال حدادة',
        'لحام',
        'تغيير',
        'رش دلفة',
        'رش',
    ];

    /**
     * @var list<string>
     */
    private const SERVICE_PHRASES = [
        'اعمال دهان',
        'خدمة دهان',
        'مصنعية دهان',
    ];

    /**
     * @var list<string>
     */
    private const RAW_MATERIAL_KEYWORDS = [
        'خشب',
        'قشرة',
        'قشره',
        'ام دي اف',
        'ام دى اف',
        'mdf',
        'كونتر',
        'ابلاكاش',
        'ابلكاش',
        'فنلندي',
        'لوح',
        'الواح',
        'دوكو',
        'تنر',
        'فيلر',
        'بروثان',
        'سيلر',
        'مط زيرو',
        'غراء',
        'سبرتو',
        'معجون',
        'زنك',
        'سبداج',
        'سنفرة',
        'صنفرة',
        'مسمار',
        'دبوس',
        'مفصلة',
        'مقبض',
        'دراع',
        'باكم',
        'مجرة',
        'مجري',
        'صولب',
        'عجل',
        'بنط',
        'بنطة',
        'لزق',
        'سيلكون',
        'سيليكون',
        'فوم',
        'قماش',
        'جلد',
        'قطن',
        'اكلاريك',
        'اكلارك',
        'اكريليك',
        'زجاج',
        'مراية',
        'رخام',
        'رتان',
        'رجل',
        'كعب',
        'وردة',
        'صمولة',
        'ماسورة',
        'فلانشة',
        'كالون',
        'وش مفتاح',
        'قضيب',
        'ستريتش',
        'ورق',
        'كرتون',
        'كيس',
        'شكارة',
        'حصي',
        'حصى',
        'قش',
        'سلك',
        'نحاس',
        'نفط',
        'منشف',
        'الوان',
        'كابسي',
        'swr',
        'ايرلاك',
        'بولوبوند',
        'ليد',
    ];

    /**
     * @var list<array{table: string, column: string}>
     */
    private ?array $productForeignReferences = null;

    public function handle(DocumentNumberService $documentNumberService): int
    {
        if (! $this->ensureTrackingTableExists()) {
            return self::FAILURE;
        }

        $companyId = $this->resolveCompanyId();

        if ($companyId === null) {
            return self::FAILURE;
        }

        if ($this->option('rollback')) {
            return $this->handleRollback($companyId);
        }

        $sourcePath = base_path(self::SOURCE_RELATIVE_PATH);

        if (! is_file($sourcePath)) {
            $this->error(sprintf('Source TSV file was not found at [%s].', self::SOURCE_RELATIVE_PATH));

            return self::FAILURE;
        }

        $parseResult = $this->parseSourceFile($sourcePath);
        $analysis = $this->analyzeRows($companyId, $parseResult['rows']);

        if (! $this->option('dry-run')) {
            try {
                DB::transaction(function () use (&$analysis, $companyId, $documentNumberService): void {
                    foreach ($analysis['rows'] as &$row) {
                        if ($row['action'] !== 'pending_insert') {
                            continue;
                        }

                        $product = $this->insertProduct($companyId, $row, $documentNumberService);
                        $this->trackInsertedProduct($product);

                        $row['action'] = 'inserted';
                        $row['reason'] = 'Inserted from TSV seed file.';
                        $row['doc_num'] = (string) $product->doc_num;
                    }
                });
            } catch (\Throwable $e) {
                $this->error(sprintf('Import failed: %s', $e->getMessage()));

                return self::FAILURE;
            }
        }

        $reportPaths = [
            $this->writeImportRowsReport($analysis['rows']),
            $this->writeClassificationReviewReport($analysis['rows']),
        ];

        $this->printImportSummary($companyId, $parseResult, $analysis, $reportPaths);

        return self::SUCCESS;
    }

    private function handleRollback(int $companyId): int
    {
        $productTable = (new Product)->getTable();
        $trackingRecords = DB::table(self::TRACKING_TABLE)
            ->where('seed_key', self::SEED_KEY)
            ->where('table_name', $productTable)
            ->orderBy('id')
            ->get();

        if ($trackingRecords->isEmpty()) {
            $this->warn(sprintf('No records found for seed key [%s]. Nothing to rollback.', self::SEED_KEY));

            return self::SUCCESS;
        }

        $rows = [];
        $deleted = 0;
        $skipped = 0;
        $missing = 0;
        $dryRun = (bool) $this->option('dry-run');

        foreach ($trackingRecords as $track) {
            $product = Product::withTrashed()->find($track->record_id);

            if (! $product instanceof Product) {
                $missing++;
                $rows[] = $this->rollbackReportRow($track, null, 'missing', 'Tracked product no longer exists.');

                if (! $dryRun) {
                    DB::table(self::TRACKING_TABLE)->where('id', $track->id)->delete();
                }

                continue;
            }

            if ((int) $product->company_id !== $companyId) {
                $skipped++;
                $rows[] = $this->rollbackReportRow($track, $product, 'skipped_company_mismatch', 'Tracked product belongs to another company.');

                continue;
            }

            if ($product->trashed()) {
                $missing++;
                $rows[] = $this->rollbackReportRow($track, $product, 'already_trashed', 'Product is already soft-deleted; tracking can be cleaned.');

                if (! $dryRun) {
                    DB::table(self::TRACKING_TABLE)->where('id', $track->id)->delete();
                }

                continue;
            }

            $references = $this->productReferences($product);

            if ($references !== []) {
                $skipped++;
                $rows[] = $this->rollbackReportRow($track, $product, 'skipped_referenced', 'Referenced by: '.implode('; ', $references));

                continue;
            }

            $deleted++;
            $rows[] = $this->rollbackReportRow($track, $product, $dryRun ? 'would_delete' : 'deleted', $dryRun ? 'Dry run only.' : 'Soft-deleted by rollback.');

            if (! $dryRun) {
                DB::transaction(function () use ($product, $track): void {
                    $product->forceFill(['deleted_by' => auth()->id()])->saveQuietly();
                    $product->delete();

                    DB::table(self::TRACKING_TABLE)->where('id', $track->id)->delete();
                });
            }
        }

        $reportPath = $this->writeRollbackReport($rows);

        $this->printRollbackSummary($companyId, $deleted, $skipped, $missing, $reportPath, $dryRun);

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     total_source_rows: int,
     *     valid_rows: int,
     *     invalid_rows: int,
     *     duplicate_source_rows: int,
     *     rows: list<array<string, mixed>>
     * }
     */
    private function parseSourceFile(string $sourcePath): array
    {
        $file = new SplFileObject($sourcePath);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $file->setCsvControl("\t");

        $seen = [];
        $rows = [];
        $totalSourceRows = 0;
        $validRows = 0;
        $invalidRows = 0;
        $duplicateSourceRows = 0;

        foreach ($file as $index => $columns) {
            if ($columns === [null] || $columns === false) {
                continue;
            }

            $lineNumber = $index + 1;
            $unitName = $this->cleanUnitName((string) ($columns[0] ?? ''));
            $itemName = $this->cleanDisplayText((string) ($columns[1] ?? ''));

            if ($this->isHeaderRow($unitName, $itemName)) {
                continue;
            }

            if ($unitName === '' && $itemName === '') {
                continue;
            }

            $totalSourceRows++;

            if ($unitName === '' || $itemName === '') {
                $invalidRows++;
                $rows[] = $this->baseImportReportRow($lineNumber, $unitName, $itemName, [
                    'action' => 'invalid_source_row',
                    'reason' => 'Both unit and item name are required.',
                ]);

                continue;
            }

            $validRows++;

            $unitKey = $this->searchKey($unitName);
            $itemKey = $this->searchKey($itemName);
            $rowKey = $itemKey.'|'.$unitKey;
            $classification = $this->classifyItem($itemName);

            if (isset($seen[$rowKey])) {
                $duplicateSourceRows++;
                $rows[] = $this->baseImportReportRow($lineNumber, $unitName, $itemName, [
                    'classification' => $classification['classification'],
                    'classification_label' => $this->classificationLabel($classification['classification']),
                    'classification_reason' => $classification['reason'],
                    'classification_review' => $classification['review'] ? 'yes' : 'no',
                    'action' => 'source_duplicate',
                    'reason' => sprintf('Duplicate of source line %d.', $seen[$rowKey]),
                    'source_duplicate_of_line' => (string) $seen[$rowKey],
                ]);

                continue;
            }

            $seen[$rowKey] = $lineNumber;
            $rows[] = [
                ...$this->baseImportReportRow($lineNumber, $unitName, $itemName),
                'unit_key' => $unitKey,
                'item_key' => $itemKey,
                'classification' => $classification['classification'],
                'classification_label' => $this->classificationLabel($classification['classification']),
                'classification_reason' => $classification['reason'],
                'classification_review' => $classification['review'] ? 'yes' : 'no',
                'action' => 'pending',
                'reason' => '',
            ];
        }

        return [
            'total_source_rows' => $totalSourceRows,
            'valid_rows' => $validRows,
            'invalid_rows' => $invalidRows,
            'duplicate_source_rows' => $duplicateSourceRows,
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     classification_counts: array{service: int, raw_material: int, other: int},
     *     insertable_count: int,
     *     missing_units: int,
     *     skipped_existing: int,
     *     unit_conflicts: int,
     *     trashed_conflicts: int
     * }
     */
    private function analyzeRows(int $companyId, array $rows): array
    {
        $unitsByName = $this->unitsByNormalizedName($companyId);
        $productsByName = $this->productsByNormalizedName($companyId);
        $classificationCounts = [
            Product::ClassificationService => 0,
            Product::ClassificationRawMaterial => 0,
            Product::ClassificationOther => 0,
        ];

        $missingUnits = 0;
        $skippedExisting = 0;
        $unitConflicts = 0;
        $trashedConflicts = 0;
        $insertableCount = 0;

        foreach ($rows as &$row) {
            if (($row['action'] ?? null) !== 'pending') {
                continue;
            }

            $classificationCounts[(string) $row['classification']]++;

            /** @var Collection<int, ItemUnit> $unitRecords */
            $unitRecords = $unitsByName->get((string) $row['unit_key'], collect());
            $activeUnit = $unitRecords->first(fn (ItemUnit $unit): bool => ! $unit->trashed());

            if (! $activeUnit instanceof ItemUnit) {
                $missingUnits++;
                $row['action'] = $unitRecords->isEmpty() ? 'missing_unit' : 'unit_trashed';
                $row['reason'] = $unitRecords->isEmpty()
                    ? 'Unit was not found for this company.'
                    : 'Only a soft-deleted unit with this name exists for this company.';

                continue;
            }

            $row['unit_id'] = $activeUnit->getKey();
            $row['unit_doc_num'] = (string) $activeUnit->doc_num;

            /** @var Collection<int, Product> $matchingProducts */
            $matchingProducts = $productsByName->get((string) $row['item_key'], collect());
            $activeProducts = $matchingProducts->filter(fn (Product $product): bool => ! $product->trashed());

            if ($activeProducts->isNotEmpty()) {
                $sameUnit = $activeProducts->first(fn (Product $product): bool => (int) $product->item_unit_id === (int) $activeUnit->getKey());
                $differentUnit = $activeProducts->first(fn (Product $product): bool => (int) $product->item_unit_id !== (int) $activeUnit->getKey());

                if ($sameUnit instanceof Product) {
                    $skippedExisting++;
                    $row['action'] = 'skipped_existing';
                    $row['reason'] = 'Active product with the same normalized name and unit already exists.';
                    $row['existing_doc_num'] = (string) $sameUnit->doc_num;
                    $row['existing_unit'] = (string) ($sameUnit->unit?->name ?? '');

                    continue;
                }

                if ($differentUnit instanceof Product) {
                    $unitConflicts++;
                    $row['action'] = 'unit_conflict';
                    $row['reason'] = 'Active product with the same normalized name exists with a different unit.';
                    $row['existing_doc_num'] = (string) $differentUnit->doc_num;
                    $row['existing_unit'] = (string) ($differentUnit->unit?->name ?? '');

                    continue;
                }
            }

            if ($matchingProducts->isNotEmpty()) {
                $trashed = $matchingProducts->first(fn (Product $product): bool => $product->trashed());

                if ($trashed instanceof Product) {
                    $trashedConflicts++;
                    $row['action'] = 'trashed_conflict';
                    $row['reason'] = 'Soft-deleted product with the same normalized name already exists; not restored automatically.';
                    $row['existing_doc_num'] = (string) $trashed->doc_num;
                    $row['existing_unit'] = (string) ($trashed->unit?->name ?? '');

                    continue;
                }
            }

            $insertableCount++;
            $row['action'] = 'pending_insert';
            $row['reason'] = $this->option('dry-run') ? 'Would insert on a real run.' : 'Ready to insert.';
        }

        return [
            'rows' => $rows,
            'classification_counts' => [
                'service' => $classificationCounts[Product::ClassificationService],
                'raw_material' => $classificationCounts[Product::ClassificationRawMaterial],
                'other' => $classificationCounts[Product::ClassificationOther],
            ],
            'insertable_count' => $insertableCount,
            'missing_units' => $missingUnits,
            'skipped_existing' => $skippedExisting,
            'unit_conflicts' => $unitConflicts,
            'trashed_conflicts' => $trashedConflicts,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insertProduct(int $companyId, array $row, DocumentNumberService $documentNumberService): Product
    {
        $classification = (string) $row['classification'];
        $documentKey = $this->documentNumberKeyForClassification($classification);
        $documentNumber = $documentNumberService->nextForCompany(
            $documentKey,
            Product::class,
            $companyId,
            $this->documentNumberScope($documentKey),
        );

        return Product::query()->create([
            'company_id' => $companyId,
            'name' => (string) $row['item_name'],
            'item_unit_id' => (int) $row['unit_id'],
            'item_classification' => $classification,
            'cost_as_inventory' => $classification === Product::ClassificationRawMaterial,
            'is_displayable' => true,
            'status' => 'active',
            'created_by' => auth()->id(),
            ...$documentNumber,
        ]);
    }

    private function trackInsertedProduct(Product $product): void
    {
        DB::table(self::TRACKING_TABLE)->insert([
            'seed_key' => self::SEED_KEY,
            'table_name' => $product->getTable(),
            'record_id' => $product->getKey(),
            'record_name' => $product->name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return Collection<string, Collection<int, ItemUnit>>
     */
    private function unitsByNormalizedName(int $companyId): Collection
    {
        return ItemUnit::withTrashed()
            ->where('company_id', $companyId)
            ->get(['id', 'company_id', 'name', 'doc_num', 'deleted_at'])
            ->groupBy(fn (ItemUnit $unit): string => $this->searchKey($this->cleanUnitName((string) $unit->name)));
    }

    /**
     * @return Collection<string, Collection<int, Product>>
     */
    private function productsByNormalizedName(int $companyId): Collection
    {
        return Product::withTrashed()
            ->with(['unit' => fn ($query) => $query->withTrashed()])
            ->forCompany($companyId)
            ->get(['id', 'company_id', 'name', 'doc_num', 'item_unit_id', 'deleted_at'])
            ->groupBy(fn (Product $product): string => $this->searchKey((string) $product->name));
    }

    private function resolveCompanyId(): ?int
    {
        $option = trim((string) ($this->option('company-id') ?? ''));

        if ($option === '') {
            $this->error('The --company-id option is required. Artisan has no operating-company session context.');

            return null;
        }

        if (! ctype_digit($option) || (int) $option < 1) {
            $this->error('The --company-id option must be a positive integer.');

            return null;
        }

        $company = Company::query()->find((int) $option);

        if (! $company instanceof Company) {
            $this->error(sprintf('Company with ID [%s] was not found.', $option));

            return null;
        }

        return (int) $company->getKey();
    }

    private function ensureTrackingTableExists(): bool
    {
        if (! Schema::hasTable(self::TRACKING_TABLE)) {
            $this->error(sprintf(
                'Tracking table [%s] does not exist. Run `php artisan migrate` first.',
                self::TRACKING_TABLE,
            ));

            return false;
        }

        return true;
    }

    /**
     * @return array{classification: string, reason: string, review: bool}
     */
    private function classifyItem(string $itemName): array
    {
        $key = $this->searchKey($itemName);
        $rawKeyword = $this->firstMatchingKeyword($key, self::RAW_MATERIAL_KEYWORDS);
        $serviceKeyword = $this->firstMatchingKeyword($key, self::SERVICE_KEYWORDS);
        $servicePhrase = $this->firstMatchingKeyword($key, self::SERVICE_PHRASES);

        if ($servicePhrase !== null) {
            return [
                'classification' => Product::ClassificationService,
                'reason' => "Service phrase: {$servicePhrase}",
                'review' => false,
            ];
        }

        if ($serviceKeyword !== null && ! $this->isPaintMaterialOnly($key, $rawKeyword)) {
            return [
                'classification' => Product::ClassificationService,
                'reason' => "Service keyword: {$serviceKeyword}",
                'review' => false,
            ];
        }

        if ($rawKeyword !== null) {
            return [
                'classification' => Product::ClassificationRawMaterial,
                'reason' => "Raw material keyword: {$rawKeyword}",
                'review' => false,
            ];
        }

        return [
            'classification' => Product::ClassificationOther,
            'reason' => 'No high-confidence service or raw-material keyword matched.',
            'review' => true,
        ];
    }

    private function firstMatchingKeyword(string $haystack, array $keywords): ?string
    {
        foreach ($keywords as $keyword) {
            $normalizedKeyword = $this->searchKey($keyword);

            if ($normalizedKeyword !== '' && str_contains($haystack, $normalizedKeyword)) {
                return $keyword;
            }
        }

        return null;
    }

    private function isPaintMaterialOnly(string $itemKey, ?string $rawKeyword): bool
    {
        if ($rawKeyword === null) {
            return false;
        }

        $paintMaterialKeywords = ['دوكو', 'تنر', 'فيلر', 'بروثان', 'سيلر', 'مط زيرو'];

        if (! in_array($rawKeyword, $paintMaterialKeywords, true)) {
            return false;
        }

        return ! str_contains($itemKey, $this->searchKey('رش'));
    }

    private function cleanUnitName(string $value): string
    {
        return str_replace('كليو', 'كيلو', $this->cleanDisplayText($value));
    }

    private function cleanDisplayText(string $value): string
    {
        $value = str_replace(["\u{FEFF}", "\u{00A0}", "\t", "\r", "\n"], ' ', $value);
        $value = preg_replace('/[[:space:]]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+([،؛:])/u', '$1', $value) ?? $value;
        $value = preg_replace('/([،؛:])(?=\S)/u', '$1 ', $value) ?? $value;

        return trim($value);
    }

    private function searchKey(string $value): string
    {
        $value = mb_strtolower($this->cleanDisplayText($value));
        $value = str_replace(['أ', 'إ', 'آ'], 'ا', $value);
        $value = str_replace(['ى'], 'ي', $value);
        $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $value) ?? $value;
        $value = preg_replace('/[[:space:]]+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function isHeaderRow(string $unitName, string $itemName): bool
    {
        return $this->searchKey($unitName) === $this->searchKey('الوحدة')
            && $this->searchKey($itemName) === $this->searchKey('اسم الصنف');
    }

    private function documentNumberKeyForClassification(string $classification): string
    {
        return $classification === Product::ClassificationRawMaterial
            ? ProductDocumentNumberSettingsService::RawMaterialsKey
            : ProductDocumentNumberSettingsService::ProductsKey;
    }

    /**
     * @return Closure(QueryBuilder): void
     */
    private function documentNumberScope(string $documentKey): Closure
    {
        return function (QueryBuilder $query) use ($documentKey): void {
            if ($documentKey === ProductDocumentNumberSettingsService::RawMaterialsKey) {
                $query->where('item_classification', Product::ClassificationRawMaterial);

                return;
            }

            $query->where(function (QueryBuilder $query): void {
                $query
                    ->whereNull('item_classification')
                    ->orWhere('item_classification', '<>', Product::ClassificationRawMaterial);
            });
        };
    }

    /**
     * @return list<string>
     */
    private function productReferences(Product $product): array
    {
        $references = [];

        foreach ($this->productForeignReferences() as $reference) {
            $count = DB::table($reference['table'])
                ->where($reference['column'], $product->getKey())
                ->count();

            if ($count > 0) {
                $references[] = sprintf('%s.%s (%d)', $reference['table'], $reference['column'], $count);
            }
        }

        return $references;
    }

    /**
     * @return list<array{table: string, column: string}>
     */
    private function productForeignReferences(): array
    {
        if ($this->productForeignReferences !== null) {
            return $this->productForeignReferences;
        }

        $references = [];
        $productTable = (new Product)->getTable();

        foreach (Schema::getTables() as $table) {
            $tableName = (string) ($table['name'] ?? '');

            if ($tableName === '' || $tableName === $productTable) {
                continue;
            }

            foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                $foreignTable = (string) ($foreignKey['foreign_table'] ?? '');
                $foreignColumns = $foreignKey['foreign_columns'] ?? [];
                $columns = $foreignKey['columns'] ?? [];

                if ($foreignTable !== $productTable || $foreignColumns !== ['id'] || count($columns) !== 1) {
                    continue;
                }

                $references[] = [
                    'table' => $tableName,
                    'column' => (string) $columns[0],
                ];
            }
        }

        return $this->productForeignReferences = $references;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function baseImportReportRow(int $lineNumber, string $unitName, string $itemName, array $overrides = []): array
    {
        return [
            'line_number' => (string) $lineNumber,
            'item_name' => $itemName,
            'unit_name' => $unitName,
            'unit_doc_num' => '',
            'classification' => '',
            'classification_label' => '',
            'classification_reason' => '',
            'classification_review' => '',
            'action' => '',
            'reason' => '',
            'doc_num' => '',
            'existing_doc_num' => '',
            'existing_unit' => '',
            'source_duplicate_of_line' => '',
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rollbackReportRow(object $track, ?Product $product, string $action, string $reason): array
    {
        return [
            'tracking_id' => (string) $track->id,
            'record_id' => (string) $track->record_id,
            'record_name' => (string) ($track->record_name ?? $product?->name ?? ''),
            'doc_num' => (string) ($product?->doc_num ?? ''),
            'company_id' => (string) ($product?->company_id ?? ''),
            'action' => $action,
            'reason' => $reason,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeImportRowsReport(array $rows): string
    {
        return $this->writeCsvReport('import_rows', [
            'line_number',
            'item_name',
            'unit_name',
            'unit_doc_num',
            'classification',
            'classification_label',
            'classification_reason',
            'classification_review',
            'action',
            'reason',
            'doc_num',
            'existing_doc_num',
            'existing_unit',
            'source_duplicate_of_line',
        ], $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeClassificationReviewReport(array $rows): string
    {
        $reviewRows = array_values(array_filter(
            $rows,
            fn (array $row): bool => ($row['classification_review'] ?? '') === 'yes',
        ));

        return $this->writeCsvReport('classification_review', [
            'line_number',
            'item_name',
            'unit_name',
            'classification',
            'classification_label',
            'classification_reason',
            'action',
            'reason',
        ], $reviewRows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeRollbackReport(array $rows): string
    {
        return $this->writeCsvReport('rollback', [
            'tracking_id',
            'record_id',
            'record_name',
            'doc_num',
            'company_id',
            'action',
            'reason',
        ], $rows);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeCsvReport(string $prefix, array $headers, array $rows): string
    {
        $relativePath = self::REPORT_DIRECTORY.'/'.$prefix.'_'.now()->format('Ymd_His_u').'.csv';
        $absolutePath = storage_path('app/'.$relativePath);

        File::ensureDirectoryExists(dirname($absolutePath));

        $handle = fopen($absolutePath, 'wb');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to write report [%s].', $relativePath));
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                fn (string $header): string => (string) ($row[$header] ?? ''),
                $headers,
            ));
        }

        fclose($handle);

        return $relativePath;
    }

    /**
     * @param  array{
     *     total_source_rows: int,
     *     valid_rows: int,
     *     invalid_rows: int,
     *     duplicate_source_rows: int,
     *     rows: list<array<string, mixed>>
     * }  $parseResult
     * @param  array{
     *     rows: list<array<string, mixed>>,
     *     classification_counts: array{service: int, raw_material: int, other: int},
     *     insertable_count: int,
     *     missing_units: int,
     *     skipped_existing: int,
     *     unit_conflicts: int,
     *     trashed_conflicts: int
     * }  $analysis
     * @param  list<string>  $reportPaths
     */
    private function printImportSummary(int $companyId, array $parseResult, array $analysis, array $reportPaths): void
    {
        $inserted = $this->countRowsByAction($analysis['rows'], 'inserted');
        $conflicts = $analysis['unit_conflicts'] + $analysis['trashed_conflicts'];
        $dryRun = (bool) $this->option('dry-run');

        $this->newLine();
        $this->line(sprintf('Inventory items TSV import summary for company ID %d', $companyId));
        $this->line(sprintf('Source file: %s', self::SOURCE_RELATIVE_PATH));
        $this->line(sprintf('Total source rows: %d', $parseResult['total_source_rows']));
        $this->line(sprintf('Valid rows: %d', $parseResult['valid_rows']));
        $this->line(sprintf('Invalid rows: %d', $parseResult['invalid_rows']));
        $this->line(sprintf('Duplicate source rows skipped: %d', $parseResult['duplicate_source_rows']));
        $this->line(sprintf($dryRun ? 'Items that would be inserted: %d' : 'Inserted items: %d', $dryRun ? $analysis['insertable_count'] : $inserted));
        $this->line(sprintf('Skipped existing items: %d', $analysis['skipped_existing']));
        $this->line(sprintf('Missing units: %d', $analysis['missing_units']));
        $this->line(sprintf('Conflicts: %d', $conflicts));
        $this->line(sprintf('Unit conflicts: %d', $analysis['unit_conflicts']));
        $this->line(sprintf('Trashed conflicts: %d', $analysis['trashed_conflicts']));
        $this->newLine();
        $this->line('Classification counts:');
        $this->line(sprintf('  Services: %d', $analysis['classification_counts']['service']));
        $this->line(sprintf('  Raw materials: %d', $analysis['classification_counts']['raw_material']));
        $this->line(sprintf('  Other: %d', $analysis['classification_counts']['other']));

        if ($analysis['missing_units'] > 0) {
            $this->newLine();
            $this->warn('Some units were missing. Run `php artisan inventory:seed-units --company-id='.$companyId.'` first if those units are part of the initial unit set.');
        }

        $this->newLine();
        $this->line('Reports written:');

        foreach ($reportPaths as $reportPath) {
            $this->line(sprintf('  - storage/app/%s', $reportPath));
        }
    }

    private function printRollbackSummary(int $companyId, int $deleted, int $skipped, int $missing, string $reportPath, bool $dryRun): void
    {
        $this->newLine();
        $this->line(sprintf('Inventory items rollback summary for company ID %d', $companyId));
        $this->line(sprintf($dryRun ? 'Would soft-delete: %d' : 'Soft-deleted: %d', $deleted));
        $this->line(sprintf('Skipped: %d', $skipped));
        $this->line(sprintf('Already missing / already trashed: %d', $missing));
        $this->newLine();
        $this->line(sprintf('Rollback report: storage/app/%s', $reportPath));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function countRowsByAction(array $rows, string $action): int
    {
        return count(array_filter($rows, fn (array $row): bool => ($row['action'] ?? null) === $action));
    }

    private function classificationLabel(string $classification): string
    {
        return (string) __("products.classifications.{$classification}");
    }
}

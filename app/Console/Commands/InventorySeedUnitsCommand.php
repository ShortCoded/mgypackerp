<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\Company;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Services\DocumentNumberService;

class InventorySeedUnitsCommand extends Command
{
    protected $signature = 'inventory:seed-units
        {--rollback : Rollback previously seeded units and clean tracking}
        {--dry-run : Preview changes without making modifications}
        {--restore-trashed : Restore trashed records that match seed names}
        {--company-id= : Company ID to seed units for (default: first active company)}';

    protected $description = 'Seed initial Arabic measurement units into the item_units table.';

    private const SEED_KEY = 'inventory_units_initial_ar_units';

    private const TRACKING_TABLE = 'seeded_reference_records';

    private const UNITS = [
        'متر مربع',
        'متر',
        'عدد',
        'كيلو',
        'عربية',
        'كرتونة',
        'جالون',
        'لوح',
        'زجاجة',
        'علبة',
        'طقم',
        'سم 3',
        'بكرة',
        'مفصلة',
        'امبوبة',
        'دستة',
        'غرفة',
        'بالواحدة',
        'شكارة',
        'بستلة',
        'كيس',
        'فرشة',
        'لفة',
    ];

    public function handle(DocumentNumberService $documentNumberService): int
    {
        if ($this->option('rollback')) {
            return $this->handleRollback();
        }

        if (! $this->ensureTrackingTableExists()) {
            return self::FAILURE;
        }

        $companyId = $this->resolveCompanyId();

        if ($companyId === null) {
            return self::FAILURE;
        }

        $unitNames = $this->normalizedUniqueUnits();

        $existing = ItemUnit::withTrashed()
            ->where('company_id', $companyId)
            ->get(['id', 'name', 'doc_num', 'deleted_at'])
            ->keyBy(fn (ItemUnit $u): string => $u->name);

        $results = [
            'inserted' => [],
            'skipped_existing' => [],
            'skipped_trashed' => [],
            'restored' => [],
        ];

        foreach ($unitNames as $name) {
            $record = $existing->get($name);

            if ($record === null) {
                $results['inserted'][] = $name;
            } elseif ($record->trashed()) {
                if ($this->option('restore-trashed')) {
                    $results['restored'][] = $name;
                } else {
                    $results['skipped_trashed'][] = $name;
                }
            } else {
                $results['skipped_existing'][] = $name;
            }
        }

        $this->printSeedSummary($companyId, $results);

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        if (count($results['inserted']) === 0 && count($results['restored']) === 0) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($companyId, $documentNumberService, $results, $unitNames, $existing): void {
                $maxDocNumber = (int) ItemUnit::query()
                    ->where('company_id', $companyId)
                    ->withTrashed()
                    ->max('doc_number');

                $nextNumber = $maxDocNumber + 1;

                foreach ($unitNames as $name) {
                    if (in_array($name, $results['inserted'], true)) {
                        $docNum = $documentNumberService->format('item_units', $nextNumber);

                        $item = ItemUnit::query()->create([
                            'company_id' => $companyId,
                            'name' => $name,
                            'status' => 'active',
                            'doc_number' => $nextNumber,
                            'doc_num' => $docNum,
                        ]);

                        DB::table(self::TRACKING_TABLE)->insert([
                            'seed_key' => self::SEED_KEY,
                            'table_name' => $item->getTable(),
                            'record_id' => $item->id,
                            'record_name' => $name,
                            'created_at' => now(),
                        ]);

                        $nextNumber++;
                    } elseif (in_array($name, $results['restored'], true)) {
                        /** @var ItemUnit $record */
                        $record = $existing->get($name);
                        $record->restore();
                        $record->forceFill([
                            'restored_by' => null,
                            'restored_at' => now(),
                        ]);
                        $record->saveQuietly();

                        DB::table(self::TRACKING_TABLE)->insert([
                            'seed_key' => self::SEED_KEY,
                            'table_name' => $record->getTable(),
                            'record_id' => $record->id,
                            'record_name' => $name,
                            'created_at' => now(),
                        ]);
                    }
                }
            });
        } catch (\Throwable $e) {
            $this->error(sprintf('Seeding failed: %s', $e->getMessage()));

            return self::FAILURE;
        }

        $this->info('Seeding completed successfully.');

        return self::SUCCESS;
    }

    private function handleRollback(): int
    {
        if (! $this->ensureTrackingTableExists()) {
            return self::FAILURE;
        }

        $trackingRecords = DB::table(self::TRACKING_TABLE)
            ->where('seed_key', self::SEED_KEY)
            ->orderBy('id')
            ->get();

        if ($trackingRecords->isEmpty()) {
            $this->warn(sprintf('No records found for seed key [%s]. Nothing to rollback.', self::SEED_KEY));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line(sprintf('Found %d seeded record(s) to rollback.', $trackingRecords->count()));

        $deleted = [];
        $skipped = [];
        $missing = [];

        foreach ($trackingRecords as $track) {
            $record = ItemUnit::withTrashed()->find($track->record_id);

            if ($record === null) {
                $missing[] = $track;

                continue;
            }

            try {
                $record->delete();

                DB::table(self::TRACKING_TABLE)
                    ->where('id', $track->id)
                    ->delete();

                $deleted[] = $track;
            } catch (\Throwable $e) {
                $skipped[] = $track;
            }
        }

        if ($missing !== []) {
            DB::table(self::TRACKING_TABLE)
                ->whereIn('id', array_map(fn ($t) => $t->id, $missing))
                ->delete();
        }

        $this->printRollbackSummary($deleted, $skipped, $missing);

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function normalizedUniqueUnits(): array
    {
        $normalized = array_map(function (string $name): string {
            $name = trim(preg_replace('/\s+/', ' ', $name));

            return str_replace('كليو', 'كيلو', $name);
        }, self::UNITS);

        $seen = [];
        $unique = [];

        foreach ($normalized as $name) {
            if (! isset($seen[$name])) {
                $seen[$name] = true;
                $unique[] = $name;
            }
        }

        return $unique;
    }

    private function resolveCompanyId(): ?int
    {
        $option = $this->option('company-id');

        if ($option !== null) {
            $company = Company::query()->find((int) $option);

            if ($company === null) {
                $this->error(sprintf('Company with ID [%s] not found.', $option));

                return null;
            }

            return $company->id;
        }

        $company = Company::query()
            ->where('is_main', true)
            ->where('status', 'active')
            ->orderBy('id')
            ->first();

        if ($company === null) {
            $company = Company::query()
                ->where('status', 'active')
                ->orderBy('id')
                ->first();
        }

        if ($company === null) {
            $this->error('No active company found. Create a company first or specify --company-id.');

            return null;
        }

        return $company->id;
    }

    private function ensureTrackingTableExists(): bool
    {
        if (! Schema::hasTable(self::TRACKING_TABLE)) {
            $this->error(sprintf(
                'Tracking table [%s] does not exist. Run `php artisan migrate` first.',
                self::TRACKING_TABLE
            ));

            return false;
        }

        return true;
    }

    /**
     * @param  array{inserted: list<string>, skipped_existing: list<string>, skipped_trashed: list<string>, restored: list<string>}  $results
     */
    private function printSeedSummary(int $companyId, array $results): void
    {
        $this->newLine();
        $this->line(sprintf('Company ID: %d', $companyId));
        $this->line(sprintf('Total unique units to process: %d', count($this->normalizedUniqueUnits())));

        $this->newLine();
        $this->line(sprintf('Will insert: %d', count($results['inserted'])));
        $this->line(sprintf('Already exist (skipped): %d', count($results['skipped_existing'])));
        $this->line(sprintf('Trashed (skipped): %d', count($results['skipped_trashed'])));

        if (count($results['restored']) > 0) {
            $this->line(sprintf('Will restore: %d', count($results['restored'])));
        }

        if ($this->option('dry-run') && count($results['skipped_trashed']) > 0 && ! $this->option('restore-trashed')) {
            $this->newLine();
            $this->warn('Tip: use --restore-trashed to restore soft-deleted units that match seed names.');
        }

        $this->newLine();

        if (count($results['inserted']) > 0) {
            $this->line('To be inserted:');
            $this->list($results['inserted']);
        }

        if (count($results['skipped_existing']) > 0) {
            $this->line('Already existing (skipped):');
            $this->list($results['skipped_existing']);
        }

        if (count($results['skipped_trashed']) > 0) {
            $this->line('Trashed (skipped):');
            $this->list($results['skipped_trashed']);
        }

        if (count($results['restored']) > 0) {
            $this->line('To be restored:');
            $this->list($results['restored']);
        }
    }

    /**
     * @param  list<string>  $items
     */
    private function list(array $items): void
    {
        foreach ($items as $item) {
            $this->line(sprintf('  - %s', $item));
        }
    }

    /**
     * @param  list<object>  $deleted
     * @param  list<object>  $skipped
     * @param  list<object>  $missing
     */
    private function printRollbackSummary(array $deleted, array $skipped, array $missing): void
    {
        $this->newLine();
        $this->line('Rollback Summary:');
        $this->line(sprintf('  Soft-deleted: %d', count($deleted)));

        if ($missing !== []) {
            $this->line(sprintf('  Already gone (tracking cleaned): %d', count($missing)));
            foreach ($missing as $track) {
                $this->line(sprintf('    - %s (id=%d)', $track->record_name ?? '?', $track->record_id));
            }
        }

        if ($skipped !== []) {
            $this->warn(sprintf('  Skipped (could not delete): %d', count($skipped)));
            foreach ($skipped as $track) {
                $this->warn(sprintf('    - %s (id=%d)', $track->record_name ?? '?', $track->record_id));
            }
        }

        $this->newLine();

        if ($skipped === []) {
            $this->info('Rollback completed successfully.');
        } else {
            $this->warn('Rollback completed with skipped records. Check above for details.');
        }
    }
}

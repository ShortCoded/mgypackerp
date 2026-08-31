<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Accounting\Services\CostCenterHierarchyRegistry;
use Modules\Core\Models\Company;
use Throwable;

class SyncCostCenterHierarchyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cost-centers:sync-standard-hierarchy
        {--company= : Limit the dry run or sync to one company document number}
        {--apply : Apply missing nodes; production application is intentionally blocked}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dry-run or safely add the standard departmental cost-center hierarchy.';

    /**
     * Execute the console command.
     */
    public function handle(CostCenterHierarchyRegistry $registry): int
    {
        if ((bool) $this->option('apply') && app()->isProduction()) {
            $this->error('Production application is blocked. Review and deploy the migration and data change through an approved process.');

            return self::FAILURE;
        }

        $companyId = $this->companyId();

        if ($this->option('company') !== null && $companyId === null) {
            $this->error('The requested company was not found.');

            return self::FAILURE;
        }

        try {
            $result = (bool) $this->option('apply')
                ? $registry->synchronize($companyId)
                : $registry->plan($companyId);
        } catch (Throwable $exception) {
            $this->error('The hierarchy sync was rolled back: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            'companies=%d create=%d reuse=%d conflicts=%d blocked=%d',
            $result['companies'],
            $result['create'],
            $result['reuse'],
            count($result['conflicts']),
            count($result['blocked']),
        ));

        foreach (['conflicts', 'blocked', 'create_codes'] as $key) {
            if ($result[$key] !== []) {
                $this->line($key.'='.implode(',', $result[$key]));
            }
        }

        if ($result['conflicts'] !== [] || $result['blocked'] !== []) {
            $this->error('No changes were made because conflicts require manual review.');

            return self::FAILURE;
        }

        $this->info((bool) $this->option('apply')
            ? 'Missing hierarchy nodes were added. Existing rows were not changed.'
            : 'Dry run complete; no database writes were performed.');

        return self::SUCCESS;
    }

    private function companyId(): ?int
    {
        $company = $this->option('company');

        if (! is_string($company) || trim($company) === '') {
            return null;
        }

        return Company::query()
            ->whereNull('deleted_at')
            ->where('doc_num', trim($company))
            ->value('id');
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Modules\Core\Models\Company;
use Modules\FixedAssets\Services\FixedAssetRootAccountAuditService;
use Throwable;

class AuditFixedAssetRootAccountsCommand extends Command implements Isolatable
{
    protected $signature = 'fixed-assets:audit-root-accounts
        {--company= : Required company document number}
        {--json : Emit the complete machine-readable audit report}';

    protected $description = 'Run a database-enforced read-only audit of duplicate Fixed Assets foundational accounts.';

    public function handle(FixedAssetRootAccountAuditService $audit): int
    {
        $companyDocNum = trim((string) $this->option('company'));

        if ($companyDocNum === '') {
            $this->error('The --company option is required. No database writes were performed.');

            return self::FAILURE;
        }

        $company = Company::query()
            ->where('doc_num', $companyDocNum)
            ->whereNull('deleted_at')
            ->first();

        if (! $company instanceof Company) {
            $this->error('The requested active company was not found. No database writes were performed.');

            return self::FAILURE;
        }

        try {
            $result = $audit->auditReadOnly($company);
        } catch (Throwable $exception) {
            $this->error('The read-only Fixed Assets root audit failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->renderAudit($result);
        }

        $this->info(sprintf(
            'Read-only audit complete; the %s transaction was rolled back and no database writes were performed.',
            $result['read_only_driver'] ?? 'database',
        ));

        return self::SUCCESS;
    }

    public function isolatableId(): string
    {
        return trim((string) $this->option('company'));
    }

    /** @param array<string, mixed> $result */
    private function renderAudit(array $result): void
    {
        $this->line(sprintf(
            'company=%s classification=%s resolution=%s duplicate_candidates=%d',
            $result['company']['doc_num'] ?? '',
            $result['classification'],
            $result['resolution_status'],
            $result['duplicate_candidate_count'],
        ));
        $this->table([
            'ID', 'Document', 'Code', 'Name', 'Parent path', 'Status', 'Deleted', 'Children',
            'Category refs', 'Asset refs', 'Journal lines', 'Opening lines', 'Archive',
        ], collect($result['duplicate_candidates'])->map(fn (array $candidate): array => [
            $candidate['id'],
            $candidate['doc_num'],
            $candidate['code'],
            $candidate['name'],
            $candidate['parent_path'],
            $candidate['status'],
            $candidate['deleted'] ? 'yes' : 'no',
            $candidate['children_with_trashed_count'],
            $candidate['fixed_asset_category_reference_count'],
            $candidate['fixed_asset_reference_count'],
            $candidate['journal_line_count'],
            $candidate['opening_balance_line_count'],
            $candidate['archive_status'],
        ])->all());
    }
}

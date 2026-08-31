<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Modules\Core\Models\Company;
use Modules\FixedAssets\Services\FixedAssetDuplicateAccountAuditService;
use Throwable;

class AuditFixedAssetDuplicateAccountsCommand extends Command implements Isolatable
{
    protected $signature = 'fixed-assets:audit-linked-accounts
        {--company= : Required company document number}
        {--asset= : Optional exact fixed asset document number}
        {--json : Emit the complete machine-readable audit report}';

    protected $description = 'Run a database-enforced read-only audit of historical duplicate fixed asset accounts.';

    public function handle(FixedAssetDuplicateAccountAuditService $audit): int
    {
        $companyDocNum = trim((string) $this->option('company'));
        $assetDocNum = trim((string) $this->option('asset'));

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
            $result = $audit->auditReadOnly($company, $assetDocNum !== '' ? $assetDocNum : null);
        } catch (Throwable $exception) {
            $this->error('The read-only fixed asset account audit failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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
        return trim((string) $this->option('company')).':'.trim((string) $this->option('asset'));
    }

    /** @param array<string, mixed> $result */
    private function renderAudit(array $result): void
    {
        $this->line(sprintf(
            'company=%s assets_scanned=%d actual_duplicates=%d review_candidates=%d safe_empty_account_repairs=%d both_posted_blocked_cases=%d other_reference_blocked_cases=%d',
            $result['company']['doc_num'] ?? '',
            $result['assets_scanned'],
            $result['actual_duplicates'],
            $result['review_candidates'],
            $result['safe_empty_account_repairs'],
            $result['both_posted_blocked_cases'],
            $result['other_reference_blocked_cases'],
        ));
        $rows = [];

        foreach ($result['cases'] as $case) {
            foreach ($case['accounts'] as $account) {
                $rows[] = [
                    $case['asset_id'],
                    $case['asset_doc_num'],
                    $account['association_role'],
                    $account['id'],
                    $account['doc_num'],
                    $account['full_path'],
                    $account['status'],
                    $account['deleted'] ? 'yes' : 'no',
                    $account['posted_journal_line_count'],
                    $account['posted_debit_total'],
                    $account['posted_credit_total'],
                    $account['posted_balance'],
                    $account['detection_confidence'],
                    (int) $case['recommended_canonical_account_id'] === (int) $account['id'] ? 'yes' : 'no',
                    $case['classification'],
                    $case['repair_status'],
                ];
            }
        }

        $this->table([
            'Asset ID', 'Asset', 'Role', 'Account ID', 'Account', 'Full path', 'Status', 'Deleted',
            'Posted lines', 'Posted debit', 'Posted credit', 'Posted balance', 'Confidence', 'Canonical',
            'Classification', 'Repair',
        ], $rows);

        foreach ($result['cases'] as $case) {
            $this->line(sprintf('%s: %s', $case['asset_doc_num'], $case['decision_reason']));
        }
    }
}

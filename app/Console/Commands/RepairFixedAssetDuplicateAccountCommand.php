<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Modules\Core\Models\Company;
use Modules\FixedAssets\Services\FixedAssetDuplicateAccountRepairService;
use Throwable;

class RepairFixedAssetDuplicateAccountCommand extends Command implements Isolatable
{
    protected $signature = 'fixed-assets:repair-duplicate-account
        {--company= : Required company document number}
        {--asset= : Required fixed asset document number}
        {--canonical-account-id= : Explicit canonical account database ID}
        {--duplicate-account-id= : Explicit empty duplicate account database ID}
        {--expected-current-account-id= : Reviewed fixed_assets.account_id}
        {--expected-parent-account-id= : Reviewed approved current category account ID}
        {--expected-canonical-journal-count= : Reviewed total journal-line count}
        {--expected-canonical-debit= : Reviewed total debit}
        {--expected-canonical-credit= : Reviewed total credit}
        {--expected-canonical-balance= : Reviewed debit minus credit balance}
        {--expected-duplicate-journal-count= : Reviewed total journal-line count}
        {--expected-duplicate-debit= : Reviewed total debit}
        {--expected-duplicate-credit= : Reviewed total credit}
        {--expected-duplicate-balance= : Reviewed debit minus credit balance}
        {--apply : Apply this single reviewed repair; omission is a database-enforced dry run}
        {--review-token= : Exact SHA-256 token emitted by the approved dry run}
        {--production-ack= : Exact acknowledgement string emitted by the dry run}';

    protected $description = 'Review or transactionally apply one manifest-gated fixed asset duplicate-account repair.';

    public function handle(FixedAssetDuplicateAccountRepairService $repairs): int
    {
        $companyDocNum = trim((string) $this->option('company'));
        $assetDocNum = trim((string) $this->option('asset'));

        if ($companyDocNum === '' || $assetDocNum === '') {
            $this->error('Both --company and --asset are required. No database writes were performed.');

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

        $expectations = $this->expectations();

        try {
            if (! (bool) $this->option('apply')) {
                $review = $repairs->reviewReadOnly($company, $assetDocNum, $expectations);
                $this->line((string) json_encode($review, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                $this->info('Dry run complete in a database-enforced read-only transaction. Approve this exact manifest before apply.');

                return self::SUCCESS;
            }

            if (! app()->isDownForMaintenance()) {
                $this->error('Apply requires Laravel maintenance mode. Stop Octane and queue workers before retrying.');

                return self::FAILURE;
            }

            $reviewToken = trim((string) $this->option('review-token'));

            if ($reviewToken === '' || preg_match('/\A[a-f0-9]{64}\z/', $reviewToken) !== 1) {
                $this->error('Apply requires the exact 64-character --review-token emitted by the approved dry run.');

                return self::FAILURE;
            }

            $canonicalAccountId = (int) ($expectations['canonical_account_id'] ?? 0);
            $duplicateAccountId = (int) ($expectations['duplicate_account_id'] ?? 0);
            $requiredAcknowledgement = $repairs->productionAcknowledgement(
                $companyDocNum,
                $assetDocNum,
                $canonicalAccountId,
                $duplicateAccountId,
            );

            if (! hash_equals($requiredAcknowledgement, trim((string) $this->option('production-ack')))) {
                $this->error('The explicit Production acknowledgement does not match this company, asset, and account pair.');

                return self::FAILURE;
            }

            $result = $repairs->apply(
                $company,
                $assetDocNum,
                $expectations,
                $reviewToken,
                trim((string) $this->option('production-ack')),
            );
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $this->info($result['idempotent']
                ? 'The exact reviewed repair was already applied; verification passed and no additional writes were made.'
                : 'The one-asset repair committed successfully; verification passed and no journal lines were changed.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Fixed asset duplicate-account remediation refused: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    public function isolatableId(): string
    {
        return trim((string) $this->option('company')).':'.trim((string) $this->option('asset'));
    }

    /** @return array<string, int|string> */
    private function expectations(): array
    {
        return [
            'canonical_account_id' => (string) $this->option('canonical-account-id'),
            'duplicate_account_id' => (string) $this->option('duplicate-account-id'),
            'expected_current_account_id' => (string) $this->option('expected-current-account-id'),
            'expected_parent_account_id' => (string) $this->option('expected-parent-account-id'),
            'expected_canonical_journal_count' => (string) $this->option('expected-canonical-journal-count'),
            'expected_canonical_debit' => (string) $this->option('expected-canonical-debit'),
            'expected_canonical_credit' => (string) $this->option('expected-canonical-credit'),
            'expected_canonical_balance' => (string) $this->option('expected-canonical-balance'),
            'expected_duplicate_journal_count' => (string) $this->option('expected-duplicate-journal-count'),
            'expected_duplicate_debit' => (string) $this->option('expected-duplicate-debit'),
            'expected_duplicate_credit' => (string) $this->option('expected-duplicate-credit'),
            'expected_duplicate_balance' => (string) $this->option('expected-duplicate-balance'),
        ];
    }
}

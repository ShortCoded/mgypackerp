<?php

namespace App\Console\Commands;

use App\Services\PostingAccountConfigurationAudit;
use Illuminate\Console\Command;
use Modules\Core\Models\Company;

class AuditPostingAccounts extends Command
{
    protected $signature = 'accounts:audit-posting {--company= : Company ID or document number}';

    protected $description = 'Audit classification-based posting accounts for inventory, purchases, and sales';

    public function handle(PostingAccountConfigurationAudit $audit): int
    {
        $companyReference = trim((string) $this->option('company'));
        $companies = Company::query()
            ->when($companyReference !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('id', ctype_digit($companyReference) ? (int) $companyReference : -1)
                ->orWhere('doc_num', $companyReference)))
            ->orderBy('id')
            ->get();

        if ($companies->isEmpty()) {
            $this->error('No matching active company was found.');

            return self::FAILURE;
        }

        $failed = false;
        foreach ($companies as $company) {
            $result = $audit->forCompany((int) $company->getKey());
            $failed = $failed || ! $result['ok'];

            $this->newLine();
            $this->info($company->name.' ['.$company->doc_num.']');
            $this->table(
                ['Classification code', 'Classification', 'State', 'Eligible posting account(s)'],
                collect($result['rows'])->map(fn (array $row): array => [
                    $row['code'],
                    $row['classification'],
                    $row['status'],
                    $row['accounts'] !== '' ? $row['accounts'] : '—',
                ])->all(),
            );
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

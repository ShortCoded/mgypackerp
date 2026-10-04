<?php

use Carbon\CarbonImmutable;
use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Accounting\Database\Seeders\AccountClassificationsSeeder;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Exports\LedgerReportExport;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\LedgerQueryService;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

test('isolated PostgreSQL keeps a maximum-scale posted ledger amount exact through XLSX and CSV', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Run only against the dedicated disposable PostgreSQL acceptance database.');
    }
    if (! in_array(DB::selectOne('select current_database() as name')->name, ['mgypack_acceptance_receipt_20261001', 'mgypack_acceptance_closure_20261003'], true)) {
        $this->markTestSkipped('Run only against the dedicated disposable PostgreSQL acceptance database.');
    }

    $this->seed(DefaultOperatingContextSeeder::class);
    $this->seed(AccountClassificationsSeeder::class);
    $this->seed(DefaultChartOfAccountsSeeder::class);
    $this->seed(CurrencySeeder::class);

    $company = Company::query()->active()->orderBy('id')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->active()->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->open()->firstOrFail();
    $currency = Currency::query()->forCompany($company->getKey())->active()->where('is_main', true)->firstOrFail();
    $accounts = Account::query()->forCompany($company->getKey())->active()
        ->where('is_group', false)->where('is_postable', true)->orderBy('account_code')->limit(2)->get();
    $amount = '99999999999999.1234';
    $date = CarbonImmutable::parse($period->from_date)->toDateString();
    $entry = JournalEntry::query()->create([
        'doc_number' => 999990,
        'doc_num' => 'JE-PG-LEDGER-999990',
        'entry_date' => $date,
        'company_id' => $company->getKey(),
        'financial_period_id' => $period->getKey(),
        'branch_id' => $branch->getKey(),
        'currency_id' => $currency->getKey(),
        'exchange_rate' => '1.000000',
        'description' => 'Synthetic PostgreSQL ledger precision',
        'status' => JournalEntry::StatusPosted,
        'is_posted' => true,
        'is_system_generated' => false,
        'approved' => true,
        'posted_at' => now(),
    ]);
    $entry->lines()->create([
        'line_no' => 1, 'account_id' => $accounts[0]->getKey(),
        'debit_amount' => $amount, 'credit_amount' => '0.0000',
        'description' => 'Synthetic debit', 'branch_id' => $branch->getKey(),
    ]);
    $entry->lines()->create([
        'line_no' => 2, 'account_id' => $accounts[1]->getKey(),
        'debit_amount' => '0.0000', 'credit_amount' => $amount,
        'description' => 'Synthetic credit', 'branch_id' => $branch->getKey(),
    ]);

    expect((string) DB::table('journal_entry_lines')->where('journal_entry_id', $entry->getKey())
        ->where('line_no', 1)->value('debit_amount'))->toBe($amount);

    $result = app(LedgerQueryService::class)->accountLedger([
        'company_id' => $company->getKey(),
        'financial_period_id' => $period->getKey(),
        'account_id' => $accounts[0]->getKey(),
        'from_date' => $date,
        'to_date' => $date,
        'branch_id' => $branch->getKey(),
        'cost_center_id' => null,
    ]);
    expect($result['period']['debit'])->toBe($amount)
        ->and($result['movements'][0]['debit'])->toBe($amount)
        ->and($result['ending']['debit'])->toBe($amount)
        ->and(Excel::raw(new LedgerReportExport($result), ExcelFormat::CSV))->toContain($amount);

    $path = tempnam(sys_get_temp_dir(), 'ledger-pg-precision-');
    file_put_contents($path, Excel::raw(new LedgerReportExport($result), ExcelFormat::XLSX));
    try {
        $sheet = IOFactory::load($path)->getActiveSheet();
        expect($sheet->getCell('H3')->getValue())->toBe($amount)
            ->and($sheet->getCell('H3')->getDataType())->toBe(DataType::TYPE_STRING)
            ->and($sheet->getCell('J4')->getValue())->toBe($amount);
    } finally {
        @unlink($path);
    }
});

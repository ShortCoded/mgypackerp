<?php

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\HR\Services\PayrollReportService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

uses(TestCase::class);

test('postgresql preserves the exact payroll decimal boundary through reports and exports', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The PostgreSQL precision release test requires the pgsql connection.');
    }

    $databaseName = (string) DB::selectOne('select current_database() as name')->name;
    if (! str_starts_with($databaseName, 'mgypack_hr_final_')) {
        $this->markTestSkipped('The PostgreSQL precision release test only runs against an isolated mgypack_hr_final_* database.');
    }

    $serverVersion = (string) DB::selectOne("select current_setting('server_version') as version")->version;
    expect($serverVersion)->toStartWith('18.6');

    $columns = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where(function ($query): void {
            $query
                ->where(function ($query): void {
                    $query->where('table_name', 'hr_payslips')
                        ->whereIn('column_name', ['gross_amount', 'deduction_amount', 'net_amount']);
                })
                ->orWhere(function ($query): void {
                    $query->where('table_name', 'hr_payroll_payments')
                        ->where('column_name', 'amount');
                });
        })
        ->get(['table_name', 'column_name', 'data_type', 'numeric_precision', 'numeric_scale'])
        ->keyBy(fn (object $column): string => $column->table_name.'.'.$column->column_name);

    expect($columns)->toHaveCount(4);
    foreach ([
        'hr_payslips.gross_amount',
        'hr_payslips.deduction_amount',
        'hr_payslips.net_amount',
        'hr_payroll_payments.amount',
    ] as $column) {
        expect($columns[$column]->data_type)->toBe('numeric')
            ->and((int) $columns[$column]->numeric_precision)->toBe(18)
            ->and((int) $columns[$column]->numeric_scale)->toBe(4);
    }

    $company = DB::table('companies')->where('doc_num', 'Company-HR-E2E-FULL-CYCLE')->first();
    $branch = DB::table('branches')->where('doc_num', 'Branch-HR-E2E-FULL-CYCLE')->first();
    $financialPeriod = DB::table('financial_periods')->where('doc_num', 'FinancialPeriod-HR-E2E-FULL-CYCLE')->first();
    $reviewer = User::query()->where('doc_num', 'User-HR-E2E-FULL-CYCLE-REVIEWER')->first();

    expect($company)->not->toBeNull()
        ->and($branch)->not->toBeNull()
        ->and($financialPeriod)->not->toBeNull()
        ->and($reviewer)->not->toBeNull();

    $run = DB::table('hr_payroll_runs as run')
        ->join('hr_payroll_periods as period', 'period.id', '=', 'run.payroll_period_id')
        ->where('period.company_id', $company->id)
        ->where('run.branch_id', $branch->id)
        ->whereDate('period.period_start', '2026-09-01')
        ->whereDate('period.period_end', '2026-09-30')
        ->whereNull('run.deleted_at')
        ->first(['run.id']);
    expect($run)->not->toBeNull();

    $payslip = DB::table('hr_payslips')->where('payroll_run_id', $run->id)->first(['id']);
    $payment = DB::table('hr_payroll_payments')
        ->where('payroll_run_id', $run->id)
        ->first(['id']);
    expect($payslip)->not->toBeNull()
        ->and($payment)->not->toBeNull();

    $maximum = '99999999999999.9999';
    $negativeMaximum = '-99999999999999.9999';
    $formattedMaximum = '99,999,999,999,999.9999';
    $numericFormat = app(NumericFormatService::class);

    expect($numericFormat->normalizeToScale($formattedMaximum, 4))->toBe($maximum)
        ->and($numericFormat->normalizeToScale('٩٩٬٩٩٩٬٩٩٩٬٩٩٩٬٩٩٩٫٩٩٩٩', 4))->toBe($maximum)
        ->and($numericFormat->format($maximum))->toBe($formattedMaximum);

    $normalizingRequest = new class extends FormRequest
    {
        use NormalizesNumericInput;

        /** @param list<string> $paths */
        public function normalizeNumbers(array $paths): void
        {
            $this->normalizeNumericInput($paths);
        }
    };
    $normalizingRequest->initialize([], [
        'english_amount' => $formattedMaximum,
        'arabic_amount' => '٩٩٬٩٩٩٬٩٩٩٬٩٩٩٬٩٩٩٫٩٩٩٩',
    ], [], [], [], ['REQUEST_METHOD' => 'POST']);
    $normalizingRequest->normalizeNumbers(['english_amount', 'arabic_amount']);
    expect($normalizingRequest->input('english_amount'))->toBe($maximum)
        ->and($normalizingRequest->input('arabic_amount'))->toBe($maximum);

    $session = [
        OperatingContextService::CompanyIdKey => $company->id,
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->id,
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $financialPeriod->id,
        OperatingContextService::FinancialPeriodDocNumKey => $financialPeriod->doc_num,
    ];

    DB::beginTransaction();

    try {
        DB::statement('create temporary table hr_decimal_boundary_probe (amount numeric(18,4) not null) on commit drop');
        DB::table('hr_decimal_boundary_probe')->insert([
            ['amount' => $maximum],
            ['amount' => $negativeMaximum],
        ]);
        expect(DB::table('hr_decimal_boundary_probe')->orderByDesc('amount')->pluck('amount')->all())
            ->toBe([$maximum, $negativeMaximum]);

        DB::table('hr_payslips')->where('id', $payslip->id)->update([
            'gross_amount' => $maximum,
            'deduction_amount' => $maximum,
            'net_amount' => $maximum,
        ]);
        DB::table('hr_payroll_payments')->where('id', $payment->id)->update(['amount' => $maximum]);

        $persistedPayslip = DB::table('hr_payslips')->where('id', $payslip->id)
            ->first(['gross_amount', 'deduction_amount', 'net_amount']);
        $persistedPayment = DB::table('hr_payroll_payments')->where('id', $payment->id)->value('amount');
        expect($persistedPayslip->gross_amount)->toBe($maximum)
            ->and($persistedPayslip->deduction_amount)->toBe($maximum)
            ->and($persistedPayslip->net_amount)->toBe($maximum)
            ->and($persistedPayment)->toBe($maximum)
            ->and($persistedPayslip->gross_amount)->toBeString()
            ->and($persistedPayment)->toBeString();

        $filters = ['run_id' => (int) $run->id];
        $reports = app(PayrollReportService::class);
        $payrollReport = $reports->payroll((int) $company->id, $reviewer, $filters);
        $payrollTotal = collect($payrollReport['totals'])->firstWhere('currency_code', 'EGP');
        expect($payrollTotal)->not->toBeNull()
            ->and($payrollTotal['gross'])->toBe($maximum)
            ->and($payrollTotal['deductions'])->toBe($maximum)
            ->and($payrollTotal['net'])->toBe($maximum)
            ->and($payrollTotal['gross'])->toBeString();

        $paymentTotals = $reports->payments((int) $company->id, $reviewer, $filters)['totals'];
        expect($paymentTotals['amount'])->toBe($maximum)
            ->and($paymentTotals['approved'])->toBe($maximum)
            ->and($paymentTotals['amount'])->toBeString();

        $payslipPayload = $reports->payslipForAdmin((int) $payslip->id, (int) $company->id, $reviewer);
        expect($payslipPayload['payslip']->gross_amount)->toBe($maximum)
            ->and($payslipPayload['payslip']->deduction_amount)->toBe($maximum)
            ->and($payslipPayload['payslip']->net_amount)->toBe($maximum)
            ->and((string) $payslipPayload['payments']->first()->amount)->toBe($maximum);

        $this->actingAs($reviewer)->withSession($session)
            ->get(route('admin.hr.reports.payroll', $filters))
            ->assertOk()
            ->assertSee($formattedMaximum);
        $this->withSession($session)
            ->get(route('admin.hr.reports.payments', $filters))
            ->assertOk()
            ->assertSee($formattedMaximum);
        $this->withSession($session)
            ->get(route('admin.hr.payslips.show', $payslip->id))
            ->assertOk()
            ->assertSee($formattedMaximum);

        $payrollCsv = $this->withSession($session)
            ->get(route('admin.hr.reports.payroll.export', [...$filters, 'format' => 'csv']));
        $payrollCsv->assertOk()->assertDownload('payroll-report.csv');
        expect(file_get_contents($payrollCsv->baseResponse->getFile()->getPathname()))
            ->toContain($maximum);

        $paymentCsv = $this->withSession($session)
            ->get(route('admin.hr.reports.payments.export', [...$filters, 'format' => 'csv']));
        $paymentCsv->assertOk()->assertDownload('payroll-payment-report.csv');
        expect(file_get_contents($paymentCsv->baseResponse->getFile()->getPathname()))
            ->toContain($maximum);

        $payrollXlsx = $this->withSession($session)
            ->get(route('admin.hr.reports.payroll.export', [...$filters, 'format' => 'xlsx']));
        $payrollXlsx->assertOk()->assertDownload('payroll-report.xlsx');
        $payrollSheet = IOFactory::load($payrollXlsx->baseResponse->getFile()->getPathname())->getActiveSheet();
        $payrollDecimalCells = collect($payrollSheet->getCellCollection()->getCoordinates())
            ->map(fn (string $coordinate) => $payrollSheet->getCell($coordinate))
            ->filter(fn ($cell): bool => $cell->getValue() === $maximum);
        expect($payrollDecimalCells)->not->toBeEmpty()
            ->and($payrollDecimalCells->every(fn ($cell): bool => $cell->getDataType() === DataType::TYPE_STRING))->toBeTrue();

        $paymentXlsx = $this->withSession($session)
            ->get(route('admin.hr.reports.payments.export', [...$filters, 'format' => 'xlsx']));
        $paymentXlsx->assertOk()->assertDownload('payroll-payment-report.xlsx');
        $paymentSheet = IOFactory::load($paymentXlsx->baseResponse->getFile()->getPathname())->getActiveSheet();
        $paymentDecimalCell = collect($paymentSheet->getCellCollection()->getCoordinates())
            ->map(fn (string $coordinate) => $paymentSheet->getCell($coordinate))
            ->first(fn ($cell): bool => $cell->getValue() === $maximum);
        expect($paymentDecimalCell)->not->toBeNull()
            ->and($paymentDecimalCell->getDataType())->toBe(DataType::TYPE_STRING);

        $pdf = Mockery::mock(ReportPdfService::class);
        $pdf->shouldReceive('stream')->times(3)->withArgs(
            function (string $view, array $data, string $filename) use ($maximum): bool {
                if ($filename === 'payroll-report.pdf' || $filename === 'payroll-payment-report.pdf') {
                    return $view === 'reports.hr.payroll'
                        && collect($data['rows'])->flatten()->containsStrict($maximum);
                }

                return str_starts_with($filename, 'payslip-')
                    && $view === 'reports.hr.payslip'
                    && (string) $data['payslip']->gross_amount === $maximum
                    && (string) $data['payslip']->deduction_amount === $maximum
                    && (string) $data['payslip']->net_amount === $maximum;
            },
        )->andReturn(response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']));
        $this->app->instance(ReportPdfService::class, $pdf);

        $this->withSession($session)
            ->get(route('admin.hr.reports.payroll.export', [...$filters, 'format' => 'pdf']))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->withSession($session)
            ->get(route('admin.hr.reports.payments.export', [...$filters, 'format' => 'pdf']))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->withSession($session)
            ->get(route('admin.hr.payslips.pdf', $payslip->id))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }
});

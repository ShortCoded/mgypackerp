<?php

namespace Modules\Finance\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Currency;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashboxCount;

final class CashboxCountService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly OperatingContextService $context,
        private readonly FinanceReportService $reports,
        private readonly NumericFormatService $numbers,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): CashboxCount
    {
        return DB::transaction(function () use ($data): CashboxCount {
            $scope = $this->requiredScope();
            $cashbox = Cashbox::query()->forCompany($scope['company_id'])
                ->where('branch_id', $scope['branch_id'])->where('doc_num', $data['cashbox_doc_num'])->firstOrFail();
            $currency = Currency::query()->forCompany($scope['company_id'])
                ->where('doc_num', $data['currency_doc_num'])->firstOrFail();
            $report = $this->reports->report([
                'type' => FinanceReportService::CashboxBalances,
                'as_of_date' => $data['count_date'],
                'cashbox_doc_num' => $cashbox->doc_num,
                'currency_doc_num' => $currency->doc_num,
                'branch_id' => $scope['branch_id'],
            ]);
            $row = $report['rows']->first(fn (array $row): bool => (int) ($row['_cashbox_id'] ?? 0) === (int) $cashbox->getKey()
                && (int) ($row['_currency_id'] ?? 0) === (int) $currency->getKey());
            $bookBalance = (string) ($row['balance'] ?? '0.0000');
            $actualAmount = $this->amount($data['actual_amount']);

            return CashboxCount::query()->create([
                ...$this->documents->nextForCompany('cashbox_counts', CashboxCount::class, $scope['company_id']),
                'company_id' => $scope['company_id'],
                'branch_id' => $scope['branch_id'],
                'cashbox_id' => $cashbox->getKey(),
                'currency_id' => $currency->getKey(),
                'count_date' => $data['count_date'],
                'book_balance' => $bookBalance,
                'actual_amount' => $actualAmount,
                'variance' => bcsub($actualAmount, $bookBalance, 4),
                'status' => CashboxCount::StatusSaved,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ])->load(['cashbox', 'currency', 'branch', 'createdBy']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(CashboxCount $count, array $data): CashboxCount
    {
        if ($count->status !== CashboxCount::StatusReopened) {
            throw new DomainException(__('cashbox_count.messages.reopen_required'));
        }

        $actualAmount = $this->amount($data['actual_amount']);
        $count->update([
            'actual_amount' => $actualAmount,
            'variance' => bcsub($actualAmount, (string) $count->book_balance, 4),
            'notes' => $data['notes'] ?? null,
            'status' => CashboxCount::StatusSaved,
            'updated_by' => auth()->id(),
        ]);

        return $count->refresh();
    }

    public function reopen(CashboxCount $count): CashboxCount
    {
        $count->update([
            'status' => CashboxCount::StatusReopened,
            'reopened_by' => auth()->id(),
            'reopened_at' => now(),
            'updated_by' => auth()->id(),
        ]);

        return $count->refresh();
    }

    /** @return array{company_id: int, branch_id: int} */
    private function requiredScope(): array
    {
        $context = $this->context->snapshot(request());
        abort_unless($context['company_id'] && $context['branch_id'], 409, __('operating_context.messages.required'));

        return ['company_id' => (int) $context['company_id'], 'branch_id' => (int) $context['branch_id']];
    }

    private function amount(mixed $value): string
    {
        return $this->numbers->normalizeToScale($value, 4) ?? '0.0000';
    }
}

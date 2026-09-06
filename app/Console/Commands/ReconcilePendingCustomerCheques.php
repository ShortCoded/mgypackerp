<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Services\CustomerReceiptSettlementService;

class ReconcilePendingCustomerCheques extends Command
{
    protected $signature = 'sales:reconcile-pending-cheques {--company= : Company ID} {--apply : Post audited reversal entries and defer settlement}';

    protected $description = 'Inspect historical customer cheques posted before actual bank collection';

    public function handle(CustomerReceiptSettlementService $settlements): int
    {
        if (! ctype_digit((string) $this->option('company'))) {
            $this->error('Supply --company with the company ID.');

            return self::FAILURE;
        }
        $receipts = CustomerReceipt::query()->with('cheque')->where('company_id', (int) $this->option('company'))
            ->where('status', CustomerReceipt::StatusApproved)->whereNotNull('journal_entry_id')
            ->whereHas('cheque', fn ($query) => $query->whereIn('status', ['received', 'deposited', 'returned', 'cancelled']))->orderBy('id')->get();
        $this->table(['Receipt', 'Cheque', 'Status', 'Amount'], $receipts->map(fn ($receipt) => [$receipt->doc_num, $receipt->cheque->doc_num, $receipt->cheque->status, $receipt->amount])->all());
        if ($this->option('apply')) {
            foreach ($receipts as $receipt) {
                if (in_array($receipt->cheque->status, ['received', 'deposited'], true)) {
                    $settlements->deferLegacyCheque($receipt);
                } else {
                    $settlements->reverse($receipt, __('Historical returned or cancelled cheque settlement reversal.'));
                }
            }
            $this->info('Reconciled '.$receipts->count().' receipt(s), preserving original journals.');
        } else {
            $this->info('Read-only inspection. Use --apply to post the corrections.');
        }

        return self::SUCCESS;
    }
}

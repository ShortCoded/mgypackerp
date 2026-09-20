<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Str;

final class JournalSourceLabelService
{
    public function label(mixed $sourceType): string
    {
        $source = trim((string) $sourceType);
        $isReversal = str_contains(Str::lower($source), 'reversal');
        $key = $this->canonicalKey($source);
        $label = __("ledger_reports.sources.{$key}");

        return $isReversal
            ? __('ledger_reports.sources.reversal', ['source' => $label])
            : $label;
    }

    public function labelWithDocument(mixed $sourceType, mixed $sourceDocument): string
    {
        return trim(implode(' / ', array_filter([
            $this->label($sourceType),
            trim((string) $sourceDocument),
        ], fn (string $value): bool => $value !== '')));
    }

    private function canonicalKey(string $sourceType): string
    {
        if ($sourceType === '') {
            return 'manual';
        }

        $source = Str::of($sourceType)
            ->replace('\\', '_')
            ->snake()
            ->lower()
            ->toString();

        return match (true) {
            $source === 'manual' => 'manual',
            str_contains($source, 'journal_entry') => 'journal_entry',
            str_contains($source, 'opening_stock'), str_starts_with($source, 'opening_balance') => 'opening_balance',
            str_starts_with($source, 'cash_receipt_voucher') => 'cash_receipt_voucher',
            str_starts_with($source, 'cash_payment_voucher') => 'cash_payment_voucher',
            str_starts_with($source, 'bank_receipt_voucher') => 'bank_receipt_voucher',
            str_starts_with($source, 'bank_payment_voucher') => 'bank_payment_voucher',
            str_starts_with($source, 'customer_receipt') => 'customer_receipt',
            str_starts_with($source, 'supplier_payment'), str_starts_with($source, 'supplier_cheque') => 'supplier_payment',
            str_starts_with($source, 'purchase_invoice') => 'purchase_invoice',
            str_contains($source, 'purchase_return'), str_starts_with($source, 'grni_purchase_return') => 'purchase_return',
            str_starts_with($source, 'customer_invoice') => 'sales_invoice',
            str_contains($source, 'sales_return'), str_starts_with($source, 'customer_credit') => 'sales_return',
            str_starts_with($source, 'sales_delivery') => 'delivery_cogs',
            str_contains($source, 'inventory_document'), str_starts_with($source, 'inventory_'), str_starts_with($source, 'grni_'), str_starts_with($source, 'goods_receipt') => 'inventory',
            str_starts_with($source, 'production_'), $source === 'production_order' => 'production',
            str_starts_with($source, 'maintenance_') => 'maintenance',
            str_starts_with($source, 'fixed_asset_') => 'fixed_assets',
            str_starts_with($source, 'hr_payroll_'), str_starts_with($source, 'payroll_') => 'payroll',
            str_starts_with($source, 'overhead_allocation') => 'overhead_allocation',
            str_starts_with($source, 'period_closing') => 'period_closing',
            str_contains($source, 'cheque') => 'cheque',
            default => 'other',
        };
    }
}

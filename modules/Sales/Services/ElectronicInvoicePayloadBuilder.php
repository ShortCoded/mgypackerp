<?php

namespace Modules\Sales\Services;

use DomainException;
use Modules\Sales\Models\CustomerInvoice;

class ElectronicInvoicePayloadBuilder
{
    /** @return array<string, mixed> */
    public function build(CustomerInvoice $invoice): array
    {
        $invoice->loadMissing(['company', 'customer', 'currency', 'lines.unit', 'originalInvoice']);
        if ($invoice->posting_status !== 'posted') {
            throw new DomainException(__('Only a finalized posted Invoice can be submitted electronically.'));
        }
        $issuerTaxpayerId = config('e_invoice.issuer_taxpayer_id') ?: $invoice->company?->vat_registration_number;
        $branchCode = config('e_invoice.branch_code') ?: $invoice->branch_id;
        if (blank($issuerTaxpayerId)) {
            throw new DomainException(__('The issuing company VAT registration number is required for Electronic Invoice submission.'));
        }
        if (blank($branchCode)) {
            throw new DomainException(__('The issuing branch Electronic Invoice code is required.'));
        }
        if (bccomp((string) $invoice->tax_amount, '0', 4) > 0 && blank($invoice->customer?->tax_number)) {
            throw new DomainException(__('The Customer tax identity is required for a taxable Electronic Invoice.'));
        }
        if (blank($invoice->currency?->code) || bccomp((string) $invoice->exchange_rate, '0', 6) <= 0) {
            throw new DomainException(__('Electronic Invoice currency and exchange rate are required.'));
        }

        $lines = $invoice->lines->map(function ($line): array {
            $snapshot = $line->source_snapshot ?? [];
            $unitCode = $snapshot['unit_code'] ?? $line->unit?->doc_num;
            $taxCode = $snapshot['tax_code'] ?? null;
            if (blank($unitCode) || blank($taxCode)) {
                throw new DomainException(__('Every Electronic Invoice line requires immutable unit and tax codes.'));
            }

            return [
                'line_id' => $line->public_id,
                'description' => $line->description,
                'quantity' => (string) $line->quantity,
                'unit_code' => $unitCode,
                'unit_price' => (string) $line->unit_price,
                'discount' => (string) $line->discount_amount,
                'net_amount' => bcsub((string) $line->line_total, (string) $line->tax_amount, 4),
                'tax_code' => $taxCode,
                'tax_rate' => (string) ($snapshot['tax_rate'] ?? 0),
                'tax_amount' => (string) $line->tax_amount,
                'total' => (string) $line->line_total,
            ];
        })->values()->all();
        $lineTotal = collect($lines)->reduce(fn (string $carry, array $line): string => bcadd($carry, $line['total'], 4), '0.0000');
        if (bccomp($lineTotal, (string) $invoice->total_amount, 4) !== 0) {
            throw new DomainException(__('Electronic Invoice line totals do not reconcile to the finalized document total.'));
        }
        if ($invoice->document_type === CustomerInvoice::TypeCreditNote
            && blank($invoice->originalInvoice?->electronic_invoice_uuid)) {
            throw new DomainException(__('Electronic Credit Note requires the original provider Invoice reference.'));
        }

        return [
            'version' => (string) config('e_invoice.payload_version', '1.0'),
            'document_type' => $invoice->document_type,
            'document_number' => $invoice->doc_num,
            'issue_date' => $invoice->invoice_date->toDateString(),
            'source' => ['type' => $invoice->source_type ?? 'sales_invoice', 'document' => $invoice->source_doc_num],
            'issuer' => ['name' => $invoice->company->name, 'taxpayer_id' => $issuerTaxpayerId, 'branch_code' => $branchCode],
            'receiver' => ['name' => $invoice->customer->name, 'taxpayer_id' => $invoice->customer->tax_number],
            'currency' => $invoice->currency->code,
            'exchange_rate' => (string) $invoice->exchange_rate,
            'original_document_reference' => $invoice->originalInvoice?->electronic_invoice_uuid,
            'lines' => $lines,
            'totals' => [
                'subtotal' => (string) $invoice->subtotal_amount, 'discount' => (string) $invoice->discount_amount,
                'taxable' => (string) $invoice->taxable_amount, 'tax' => (string) $invoice->tax_amount,
                'total' => (string) $invoice->total_amount,
            ],
        ];
    }
}

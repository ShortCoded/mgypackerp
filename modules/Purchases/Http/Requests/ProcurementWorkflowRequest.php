<?php

namespace Modules\Purchases\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Purchases\Models\SupplierPaymentContext;

class ProcurementWorkflowRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput([
            'lines.*.requested_quantity', 'approved_quantities.*', 'lines.*.quantity', 'lines.*.offered_quantity',
            'lines.*.unit_price', 'lines.*.discount_amount', 'lines.*.tax_rate', 'lines.*.selected_quantity',
            'schedules.*.scheduled_quantity', 'lines.*.delivered_quantity', 'lines.*.accepted_quantity',
            'lines.*.rejected_quantity', 'lines.*.quantity', 'amount', 'allocations.*.amount',
            'requested_values.lines.*.ordered_quantity', 'requested_values.lines.*.unit_price', 'exchange_rate',
        ]);

        $dateService = app(DateFormatService::class);
        $data = $this->all();
        foreach (['request_date', 'required_by_date', 'issue_date', 'quotation_due_date', 'required_delivery_date', 'quotation_date', 'valid_until', 'selection_date', 'document_date', 'supplier_delivery_date', 'inspection_at', 'return_date', 'payment_date', 'cheque_date', 'cheque_due_date'] as $field) {
            if (filled($data[$field] ?? null) && $dateService->isValidDate((string) $data[$field])) {
                $data[$field] = $dateService->normalizeForStorage((string) $data[$field]);
            }
        }
        foreach (['lines', 'schedules'] as $collection) {
            foreach ($data[$collection] ?? [] as $index => $line) {
                foreach (['required_date', 'delivery_date', 'scheduled_date', 'required_delivery_date'] as $field) {
                    if (filled($line[$field] ?? null) && $dateService->isValidDate((string) $line[$field])) {
                        $data[$collection][$index][$field] = $dateService->normalizeForStorage((string) $line[$field]);
                    }
                }
            }
        }
        $route = (string) $this->route()?->getName();
        if ($route === 'admin.purchases.supplier-selection.store') {
            $data['lines'] = array_values(array_filter($data['lines'] ?? [], fn (array $line): bool => filled($line['selected_quantity'] ?? null)));
        }
        if (in_array($route, ['admin.purchases.goods-receipt-notes.store', 'admin.purchases.purchase-returns.store'], true)) {
            $data['lines'] = array_values(array_filter($data['lines'] ?? [], fn (array $line): bool => filled($line[$route === 'admin.purchases.goods-receipt-notes.store' ? 'delivered_quantity' : 'quantity'] ?? null)));
        }
        if ($route === 'admin.purchases.purchase-order-delivery-schedule.store') {
            $data['schedules'] = array_values(array_filter($data['schedules'] ?? [], fn (array $line): bool => filled($line['scheduled_quantity'] ?? null)));
        }
        if ($route === 'admin.purchases.supplier-payments.store' || $route === 'admin.purchases.supplier-payment-allocations.store') {
            $data['allocations'] = array_values(array_filter($data['allocations'] ?? [], fn (array $line): bool => filled($line['amount'] ?? null)));
        }
        if ($route === 'admin.purchases.supplier-payments.store') {
            $data['payment_method'] = trim((string) ($data['payment_method'] ?? SupplierPaymentContext::MethodCash));
            $data['cashbox_doc_num'] = trim((string) ($data['cashbox_doc_num'] ?? '')) ?: null;
            $data['bank_account_doc_num'] = trim((string) ($data['bank_account_doc_num'] ?? '')) ?: null;
            $data['cheque_number'] = trim((string) ($data['cheque_number'] ?? '')) ?: null;
        }
        $data['attachment_file_doc_nums'] = collect($data['attachment_file_doc_nums'] ?? [])
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $this->replace($data);
    }

    public function rules(): array
    {
        $route = (string) $this->route()?->getName();

        return match ($route) {
            'admin.purchases.purchase-requisitions.store' => $this->requisitionRules(),
            'admin.purchases.purchase-requisitions.approve' => [
                'approved_quantities' => ['nullable', 'array'],
                'approved_quantities.*' => ['numeric', 'decimal:0,8', 'min:0'],
            ],
            'admin.purchases.request-for-quotations.store' => $this->rfqRules(),
            'admin.purchases.supplier-quotation-entry.store' => $this->quotationRules(),
            'admin.purchases.supplier-selection.store' => $this->selectionRules(),
            'admin.purchases.purchase-order-delivery-schedule.store' => $this->deliveryScheduleRules(),
            'admin.purchases.goods-receipt-notes.store' => $this->receiptRules(),
            'admin.purchases.goods-receipt-notes.cancel' => [
                'cancel_reason' => ['required', 'string', 'max:2000'],
            ],
            'admin.purchases.goods-receipt-inspection.store' => $this->inspectionRules(),
            'admin.purchases.purchase-order-change-requests.store' => $this->changeRequestRules(),
            'admin.purchases.purchase-returns.store' => $this->returnRules(),
            'admin.purchases.purchase-returns.reverse' => [
                'reversal_reason' => ['required', 'string', 'max:2000'],
            ],
            'admin.purchases.supplier-payments.store' => $this->supplierPaymentRules(),
            'admin.purchases.supplier-payments.cancel' => [
                'cancel_reason' => ['required', 'string', 'max:2000'],
            ],
            'admin.purchases.supplier-payment-allocations.store' => [
                'allocations' => ['required', 'array', 'min:1'],
                'allocations.*.purchase_invoice_doc_num' => ['required', 'string'],
                'allocations.*.payment_schedule_public_id' => ['nullable', 'uuid'],
                'allocations.*.amount' => ['required', 'numeric', 'decimal:0,4', 'gt:0'],
            ],
            default => [],
        };
    }

    private function requisitionRules(): array
    {
        $companyId = $this->companyId();

        return [
            'request_date' => ['required', 'date'],
            'required_by_date' => ['nullable', 'date', 'after_or_equal:request_date'],
            'branch_store_uuid' => ['nullable', 'uuid'],
            'department' => ['nullable', 'string', 'max:120'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_doc_num' => ['required', 'string', Rule::exists('products', 'doc_num')->where(fn ($query) => $query->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at'))],
            'lines.*.unit_doc_num' => ['required', 'string'],
            'lines.*.requested_quantity' => ['required', 'numeric', 'decimal:0,8', 'gt:0'],
            'lines.*.required_date' => ['nullable', 'date'],
            'lines.*.source_type' => ['required', Rule::in(['manual', 'production_order', 'work_order'])],
            'lines.*.source_doc_num' => ['nullable', 'string', 'max:100', 'required_unless:lines.*.source_type,manual'],
            'lines.*.source_line_reference' => ['nullable', 'string', 'max:100', 'required_if:lines.*.source_type,production_order'],
            'lines.*.specification' => ['nullable', 'string'],
            'lines.*.notes' => ['nullable', 'string'],
        ];
    }

    private function rfqRules(): array
    {
        $companyId = $this->companyId();

        return [
            'issue_date' => ['required', 'date'],
            'quotation_due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'required_delivery_date' => ['nullable', 'date'],
            'commercial_notes' => ['nullable', 'string'],
            'supplier_doc_nums' => ['required', 'array', 'min:1'],
            'supplier_doc_nums.*' => ['required', 'string', Rule::exists('suppliers', 'doc_num')->where(fn ($query) => $query->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at'))],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.requisition_line_public_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'decimal:0,8', 'gt:0'],
            'lines.*.notes' => ['nullable', 'string'],
        ];
    }

    private function quotationRules(): array
    {
        return [
            'supplier_doc_num' => ['required', 'string'],
            'currency_doc_num' => ['nullable', 'string'],
            'exchange_rate' => ['required', 'numeric', 'decimal:0,6', 'gt:0'],
            'supplier_reference' => ['nullable', 'string', 'max:120'],
            'quotation_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:quotation_date'],
            'lead_time_days' => ['nullable', 'integer', 'min:0'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'freight_amount' => ['nullable', 'numeric', 'decimal:0,4', 'min:0'],
            'commercial_notes' => ['nullable', 'string'],
            'attachment_file_doc_nums' => ['nullable', 'array', 'max:20'],
            'attachment_file_doc_nums.*' => ['string', 'max:100', 'distinct'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.rfq_line_public_id' => ['required', 'uuid'],
            'lines.*.offered_quantity' => ['required', 'numeric', 'decimal:0,8', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'decimal:0,4', 'min:0'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'decimal:0,4', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'decimal:0,4', 'between:0,100'],
            'lines.*.delivery_date' => ['nullable', 'date'],
            'lines.*.notes' => ['nullable', 'string'],
        ];
    }

    private function selectionRules(): array
    {
        return [
            'selection_date' => ['required', 'date'],
            'selection_reason' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.quotation_line_public_id' => ['required', 'uuid'],
            'lines.*.selected_quantity' => ['required', 'numeric', 'decimal:0,8', 'gt:0'],
            'lines.*.reason' => ['nullable', 'string'],
        ];
    }

    private function deliveryScheduleRules(): array
    {
        return [
            'schedules' => ['required', 'array', 'min:1'],
            'schedules.*.purchase_order_line_public_id' => ['required', 'uuid'],
            'schedules.*.scheduled_date' => ['required', 'date'],
            'schedules.*.scheduled_quantity' => ['required', 'numeric', 'decimal:0,8', 'gt:0'],
            'schedules.*.notes' => ['nullable', 'string'],
        ];
    }

    private function receiptRules(): array
    {
        return [
            'document_date' => ['required', 'date'],
            'received_at' => ['nullable', 'date'],
            'supplier_delivery_note' => ['nullable', 'string', 'max:120'],
            'supplier_delivery_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_public_id' => ['required', 'uuid'],
            'lines.*.delivery_schedule_public_id' => ['nullable', 'uuid'],
            'lines.*.delivered_quantity' => ['required', 'numeric', 'decimal:0,8', 'gt:0'],
            'lines.*.supplier_lot_number' => ['nullable', 'string', 'max:120'],
            'lines.*.notes' => ['nullable', 'string'],
        ];
    }

    private function inspectionRules(): array
    {
        return [
            'inspection_at' => ['nullable', 'date'],
            'observations' => ['nullable', 'string'],
            'attachment_file_doc_nums' => ['nullable', 'array', 'max:20'],
            'attachment_file_doc_nums.*' => ['string', 'max:100', 'distinct'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.receipt_line_public_id' => ['required', 'uuid'],
            'lines.*.accepted_quantity' => ['required', 'numeric', 'decimal:0,8', 'min:0'],
            'lines.*.rejected_quantity' => ['required', 'numeric', 'decimal:0,8', 'min:0'],
            'lines.*.disposition' => ['nullable', Rule::in(['quarantine', 'return_supplier', 'reinspect', 'conditional_acceptance'])],
            'lines.*.reason' => ['nullable', 'string'],
            'lines.*.measurements' => ['nullable', 'array'],
        ];
    }

    private function changeRequestRules(): array
    {
        return [
            'request_date' => ['required', 'date'],
            'reason' => ['required', 'string'],
            'requested_values' => ['required', 'array'],
            'requested_values.expected_delivery_date' => ['nullable', 'date'],
            'requested_values.payment_terms' => ['nullable', 'string', 'max:255'],
            'requested_values.notes' => ['nullable', 'string'],
            'requested_values.lines' => ['nullable', 'array'],
            'requested_values.lines.*.public_id' => ['required', 'uuid'],
            'requested_values.lines.*.ordered_quantity' => ['nullable', 'numeric', 'decimal:0,8', 'gt:0'],
            'requested_values.lines.*.unit_price' => ['nullable', 'numeric', 'decimal:0,4', 'min:0'],
            'requested_values.lines.*.required_delivery_date' => ['nullable', 'date'],
        ];
    }

    private function returnRules(): array
    {
        return [
            'purchase_order_doc_num' => ['required', 'string'],
            'purchase_invoice_doc_num' => ['nullable', 'string'],
            'return_date' => ['required', 'date'],
            'reason_code' => ['required', Rule::in(['quality_rejection', 'latent_defect', 'wrong_specification', 'excess_delivery', 'wrong_item', 'damaged', 'commercial_return'])],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.receipt_line_public_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'decimal:0,8', 'gt:0'],
            'lines.*.from_quarantine' => ['nullable', 'boolean'],
            'lines.*.reason' => ['nullable', 'string'],
        ];
    }

    private function supplierPaymentRules(): array
    {
        $companyId = $this->companyId();

        return [
            'supplier_doc_num' => ['required', 'string', Rule::exists('suppliers', 'doc_num')->where(fn ($query) => $query->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at'))],
            'purchase_order_doc_num' => ['nullable', 'string'],
            'payment_method' => ['required', Rule::in(SupplierPaymentContext::methods())],
            'payment_date' => ['required', 'date'],
            'cashbox_doc_num' => ['nullable', 'required_if:payment_method,cash', 'string', Rule::exists('cashboxes', 'doc_num')->where(fn ($query) => $query->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at'))],
            'bank_account_doc_num' => ['nullable', 'required_if:payment_method,bank,cheque', 'string', Rule::exists('bank_accounts', 'doc_num')->where(fn ($query) => $query->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at'))],
            'currency_doc_num' => ['required', 'string', Rule::exists('currencies', 'doc_num')->where(fn ($query) => $query->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at'))],
            'exchange_rate' => ['nullable', 'numeric', 'decimal:0,6', 'gt:0'],
            'amount' => ['required', 'numeric', 'decimal:0,4', 'gt:0'],
            'cheque_number' => ['nullable', 'required_if:payment_method,cheque', 'string', 'max:100'],
            'cheque_date' => ['nullable', 'required_if:payment_method,cheque', 'date'],
            'cheque_due_date' => ['nullable', 'required_if:payment_method,cheque', 'date', 'after_or_equal:cheque_date'],
            'is_advance' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.purchase_invoice_doc_num' => ['required', 'string'],
            'allocations.*.payment_schedule_public_id' => ['nullable', 'uuid'],
            'allocations.*.amount' => ['required', 'numeric', 'decimal:0,4', 'gt:0'],
        ];
    }

    private function companyId(): int
    {
        return (int) (app(OperatingContextService::class)->snapshot($this)['company_id'] ?? 0);
    }
}

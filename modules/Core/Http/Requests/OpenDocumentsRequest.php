<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Services\OpenDocumentsService;

class OpenDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $type = $this->input('document_type');

        return is_string($type) && app(OpenDocumentsService::class)->canExecute($this, trim($type));
    }

    protected function prepareForValidation(): void
    {
        $fields = [];
        foreach (['document_type', 'from_number', 'to_number', 'reason', 'source_period_doc_num'] as $field) {
            $value = $this->input($field);
            $fields[$field] = is_scalar($value) ? trim((string) $value) : $value;
        }
        $this->merge($fields);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string', Rule::in(app(OpenDocumentsService::class)->supportedTypeKeys())],
            'from_number' => ['required', 'integer', 'min:1'],
            'to_number' => ['required', 'integer', 'min:1'],
            'source_period_doc_num' => ['nullable', 'string', 'max:100', Rule::prohibitedIf(! in_array($this->input('document_type'), [OpenDocumentsService::PurchaseInvoices, OpenDocumentsService::PurchaseReceipts, OpenDocumentsService::ProductionRuns, OpenDocumentsService::InventoryCorrections], true))],
            'reason' => [Rule::requiredIf($this->routeIs('admin.tools.open-documents.store')
                && in_array($this->input('document_type'), [OpenDocumentsService::SalesRequests, OpenDocumentsService::SalesOrders, OpenDocumentsService::CustomerInvoices, OpenDocumentsService::PurchaseOrders, OpenDocumentsService::PurchaseRequisitions, OpenDocumentsService::ProductionMaterialRequests, OpenDocumentsService::PurchaseReceipts, OpenDocumentsService::PurchaseInvoices, OpenDocumentsService::InventoryMovements], true)), 'nullable', 'string', 'max:2000'],
            'preview_token' => [Rule::requiredIf($this->routeIs('admin.tools.open-documents.store')), 'nullable', 'string', 'size:64', 'regex:/^[a-f0-9]+$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('from_number') || $validator->errors()->has('to_number')) {
                return;
            }

            if ((int) $this->input('from_number') > (int) $this->input('to_number')) {
                $validator->errors()->add('from_number', __('open_documents.validation.from_lte_to'));

                return;
            }

            $maximumRange = match ((string) $this->input('document_type')) {
                OpenDocumentsService::CustomerInvoices, OpenDocumentsService::PurchaseReceipts, OpenDocumentsService::PurchaseInvoices, OpenDocumentsService::InventoryMovements, OpenDocumentsService::ProductionRuns, OpenDocumentsService::SalesReturns, OpenDocumentsService::OpeningStockCosts, OpenDocumentsService::OpeningStockQuantities => 10,
                OpenDocumentsService::SalesRequests, OpenDocumentsService::SalesOrders, OpenDocumentsService::PurchaseOrders, OpenDocumentsService::PurchaseRequisitions, OpenDocumentsService::ProductionMaterialRequests => 50,
                default => 1000,
            };

            if ((int) $this->input('to_number') - (int) $this->input('from_number') + 1 > $maximumRange) {
                $validator->errors()->add('to_number', __('open_documents.validation.range_too_large', ['count' => $maximumRange]));
            }
        });
    }

    /**
     * @param  string|null  $key
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);

        if ($key !== null || ! is_array($data)) {
            return $data;
        }

        return [
            'document_type' => (string) $data['document_type'],
            'from_number' => (int) $data['from_number'],
            'to_number' => (int) $data['to_number'],
            'reason' => $data['reason'] ?? null,
            'source_period_doc_num' => $data['source_period_doc_num'] ?? null,
            'preview_token' => $data['preview_token'] ?? null,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'document_type' => __('open_documents.fields.document_type'),
            'from_number' => __('open_documents.fields.from_number'),
            'to_number' => __('open_documents.fields.to_number'),
            'reason' => __('open_documents.fields.reason'),
            'source_period_doc_num' => __('open_documents.fields.source_period'),
            'preview_token' => __('open_documents.fields.preview_token'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'preview_token.required' => __('open_documents.validation.preview_required'),
            'preview_token.size' => __('open_documents.validation.preview_required'),
            'preview_token.regex' => __('open_documents.validation.preview_required'),
        ];
    }
}

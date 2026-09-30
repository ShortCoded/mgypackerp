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
        return app(OpenDocumentsService::class)->canExecute($this, trim((string) $this->input('document_type')));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'document_type' => trim((string) $this->input('document_type')),
            'from_number' => trim((string) $this->input('from_number')),
            'to_number' => trim((string) $this->input('to_number')),
            'reason' => trim((string) $this->input('reason')),
        ]);
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
            'reason' => [Rule::requiredIf(in_array($this->input('document_type'), [OpenDocumentsService::SalesRequests, OpenDocumentsService::SalesOrders, OpenDocumentsService::CustomerInvoices, OpenDocumentsService::PurchaseOrders, OpenDocumentsService::PurchaseRequisitions, OpenDocumentsService::ProductionMaterialRequests], true)), 'nullable', 'string', 'max:2000'],
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
                OpenDocumentsService::CustomerInvoices => 10,
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
        ];
    }
}

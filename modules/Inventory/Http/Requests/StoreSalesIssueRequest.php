<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;

class StoreSalesIssueRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('document_date')) {
            $this->merge(['document_date' => app(DateFormatService::class)->normalizeForStorage((string) $this->input('document_date'))]);
        }
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('inventory.documents.create')
            && (bool) $this->user()?->can('inventory.documents.issue');
    }

    public function rules(): array
    {
        $context = app(OperatingContextService::class)->snapshot($this);

        return [
            'sales_issue_order_doc_num' => [
                'required', 'string',
                Rule::exists('sales_issue_orders', 'doc_num')->where(fn ($query) => $query
                    ->where('company_id', $context['company_id'])
                    ->where('status', 'pending')),
            ],
            'branch_store_uuid' => [
                'required', 'uuid',
                Rule::exists('branch_stores', 'public_uuid')->where(fn ($query) => $query
                    ->where('branch_id', $context['branch_id'])
                    ->whereNull('deleted_at')),
            ],
            'document_date' => ['required', 'date'],
            'lines' => ['prohibited'],
        ];
    }
}

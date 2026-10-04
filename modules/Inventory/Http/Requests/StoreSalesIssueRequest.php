<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;

class StoreSalesIssueRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('document_date')) {
            $value = $this->input('document_date');
            if (is_string($value)) {
                $this->merge(['document_date' => app(DateFormatService::class)->normalizeForStorage($value)]);
            }
        }
        if (is_array($this->input('layer_selections'))) {
            $numbers = app(NumericFormatService::class);
            $this->merge(['layer_selections' => collect($this->input('layer_selections'))->map(function (mixed $line) use ($numbers): mixed {
                if (is_array($line) && is_array($line['receipt_layers'] ?? null)) {
                    $line['receipt_layers'] = collect($line['receipt_layers'])->map(function (mixed $slice) use ($numbers): mixed {
                        if (is_array($slice)) {
                            $slice['quantity'] = $numbers->normalizeForValidation($slice['quantity'] ?? null);
                        }

                        return $slice;
                    })->all();
                }

                return $line;
            })->all()]);
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
            'layer_selections' => ['nullable', 'array', 'max:100'],
            'layer_selections.*' => ['array'],
            'layer_selections.*.invoice_line_id' => ['required', 'integer', 'distinct'],
            'layer_selections.*.receipt_layers' => ['required', 'array', 'max:100'],
            'layer_selections.*.receipt_layers.*' => ['array'],
            'layer_selections.*.receipt_layers.*.layer_id' => ['required', 'integer', 'min:1'],
            'layer_selections.*.receipt_layers.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines' => ['prohibited'],
        ];
    }
}

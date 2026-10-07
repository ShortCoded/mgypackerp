<?php

namespace Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;

class ProductionHandoverRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->routeIs('admin.inventory.*') ? 'inventory.production_receipts.create' : 'production.handovers.create');
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput(['lines.*.quantity']);
        $this->merge(['lines' => collect($this->input('lines', []))->filter(fn (mixed $line): bool => ! is_array($line)
            || (filled($line['quantity'] ?? null) && (! is_numeric($line['quantity']) || bccomp((string) $line['quantity'], '0', 8) !== 0)))->values()->all()]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $warehouse = $this->routeIs('admin.inventory.*');

        return ['document_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'branch_store_id' => [$warehouse ? 'prohibited' : 'required', 'integer'], 'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,8'],
            'lines.*.'.($warehouse ? 'line_public_id' : 'run_public_id') => ['required', 'uuid', 'distinct'],
            'lines.*.serial_numbers' => [$warehouse ? 'prohibited' : 'nullable', 'string', 'max:1000000']];
    }
}

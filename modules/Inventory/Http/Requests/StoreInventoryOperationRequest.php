<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Product;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;

class StoreInventoryOperationRequest extends FormRequest
{
    use NormalizesNumericInput;

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput(['lines.*.quantity']);

        $dates = app(DateFormatService::class);
        $sourceStoreUuid = $this->input('branch_store_uuid');
        $destinationStoreUuid = $this->input('destination_branch_store_uuid');

        if (blank($sourceStoreUuid) && $this->filled('branch_store_id')) {
            $sourceStoreUuid = BranchStore::query()->whereKey($this->input('branch_store_id'))->value('public_uuid');
        }
        if (blank($destinationStoreUuid) && $this->filled('destination_branch_store_id')) {
            $destinationStoreUuid = BranchStore::query()->whereKey($this->input('destination_branch_store_id'))->value('public_uuid');
        }

        $lines = collect($this->input('lines', []))->map(function (mixed $line) use ($dates): mixed {
            if (! is_array($line)) {
                return $line;
            }

            if (blank($line['product_doc_num'] ?? null) && filled($line['product_id'] ?? null)) {
                $line['product_doc_num'] = Product::query()->whereKey($line['product_id'])->value('doc_num');
            }

            foreach (['manufacture_date', 'expiry_date'] as $field) {
                if (filled($line[$field] ?? null)) {
                    $line[$field] = $dates->normalizeForStorage((string) $line[$field]) ?? $line[$field];
                }
            }

            return $line;
        })->all();

        $this->merge([
            'branch_store_uuid' => $sourceStoreUuid,
            'destination_branch_store_uuid' => $destinationStoreUuid,
            'document_date' => $dates->normalizeForStorage((string) $this->input('document_date')) ?? $this->input('document_date'),
            'lines' => $lines,
        ]);
    }

    public function authorize(): bool
    {
        $permission = match ($this->input('document_type')) {
            InventoryDocument::TypeReceipt => 'inventory.documents.receive',
            InventoryDocument::TypeIssue => 'inventory.documents.issue',
            InventoryDocument::TypeReturn => 'inventory.documents.return',
            InventoryDocument::TypeTransfer => 'inventory.documents.transfer',
            InventoryDocument::TypeAdjustmentIn, InventoryDocument::TypeAdjustmentOut => 'inventory.documents.adjust',
            InventoryDocument::TypeDamage, InventoryDocument::TypeScrap => 'inventory.documents.damage_scrap',
            default => null,
        };

        return $permission !== null && (bool) $this->user()?->can($permission);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $context = app(OperatingContextService::class)->snapshot($this);

        return [
            'branch_store_uuid' => [
                'required',
                'uuid',
                Rule::exists('branch_stores', 'public_uuid')->where(fn ($query) => $query
                    ->where('branch_id', $context['branch_id'])
                    ->whereNull('deleted_at')),
            ],
            'destination_branch_store_uuid' => [
                Rule::requiredIf(fn (): bool => $this->input('document_type') === InventoryDocument::TypeTransfer),
                'nullable',
                'uuid',
                'different:branch_store_uuid',
                Rule::exists('branch_stores', 'public_uuid')->where(fn ($query) => $query
                    ->whereIn('branch_id', fn ($branches) => $branches
                        ->select('id')
                        ->from('branches')
                        ->where('company_id', $context['company_id'])
                        ->where('status', 'active')
                        ->whereNull('deleted_at'))
                    ->whereNull('deleted_at')),
            ],
            'warehouse_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
            'destination_warehouse_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
            'document_type' => ['required', Rule::in(InventoryDocument::manualMovementTypes())],
            'document_date' => ['required', 'date'],
            'movement_reason' => ['required', 'string', 'max:255'],
            'source_stock_status' => ['nullable', Rule::in($this->stockStatuses())],
            'destination_stock_status' => ['nullable', Rule::in($this->stockStatuses())],
            'notes' => ['nullable', 'string'],
            'submit_action' => ['nullable', Rule::in(['save', 'save_view', 'save_edit', 'save_back', 'save_clone', 'post_and_view'])],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_doc_num' => [
                'required',
                'string',
                Rule::exists('products', 'doc_num')->where(fn ($query) => $query
                    ->where('company_id', $context['company_id'])
                    ->where('status', 'active')
                    ->where('item_classification', '<>', Product::ClassificationService)
                    ->whereNull('deleted_at')),
            ],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.warehouse_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
            'lines.*.destination_warehouse_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
            'lines.*.batch_lot' => ['nullable', 'string', 'max:100'],
            'lines.*.manufacture_date' => ['nullable', 'date'],
            'lines.*.expiry_date' => ['nullable', 'date', 'after_or_equal:document_date'],
            'lines.*.notes' => ['nullable', 'string'],
        ];
    }

    /** @return list<string> */
    private function stockStatuses(): array
    {
        return [
            InventoryTransaction::StatusAvailable,
            InventoryTransaction::StatusReserved,
            InventoryTransaction::StatusQcHold,
            InventoryTransaction::StatusQuarantine,
            InventoryTransaction::StatusRework,
            InventoryTransaction::StatusProductionStaging,
            InventoryTransaction::StatusWip,
            InventoryTransaction::StatusRejected,
            InventoryTransaction::StatusDamaged,
            InventoryTransaction::StatusScrap,
            InventoryTransaction::StatusInTransit,
        ];
    }
}

<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Support\Str;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventorySerialIdentity;
use Modules\Inventory\Models\InventoryTransaction;

class InventorySerialService
{
    public function resolve(Product $product, string $serial): InventorySerialIdentity
    {
        $serial = trim($serial);
        if (! $product->tracks_serials || $serial === '' || mb_strlen($serial) > 100 || preg_match('/[\x00-\x1f\x7f]/u', $serial)) {
            throw new DomainException(__('inventory_serial.invalid_serial'));
        }

        return InventorySerialIdentity::query()->firstOrCreate([
            'company_id' => $product->company_id, 'product_id' => $product->id, 'normalized_serial' => Str::lower($serial),
        ], ['serial_number' => $serial]);
    }

    public function lockForReceipt(InventoryTransaction $transaction, ?InventoryReceiptLayer $source, string $quantity, ?int $serialId = null): ?InventorySerialIdentity
    {
        $product = Product::query()->findOrFail($transaction->product_id);
        $id = $source?->inventory_serial_identity_id ?? $serialId ?? $transaction->inventory_serial_identity_id;
        if (! $product->tracks_serials && $id === null) {
            return null;
        }
        if ($id === null || bccomp($quantity, '1', 8) !== 0) {
            throw new DomainException(__('inventory_serial.exact_unit_required'));
        }
        $identity = InventorySerialIdentity::query()->whereKey($id)->where('company_id', $transaction->company_id)
            ->where('product_id', $transaction->product_id)->lockForUpdate()->first();
        if ($identity === null || $identity->current_receipt_layer_id !== null
            || ($source === null && InventoryReceiptLayer::query()->where('inventory_serial_identity_id', $id)->exists())
            || ($transaction->inventory_serial_identity_id !== null && (int) $transaction->inventory_serial_identity_id !== (int) $id)) {
            throw new DomainException(__('inventory_serial.already_received'));
        }
        if (bccomp((string) $transaction->quantity_in, '1', 8) === 0) {
            $transaction->forceFill(['inventory_serial_identity_id' => $id])->save();
        }

        return $identity;
    }

    /** @return list<string> */
    public function numbers(mixed $input): array
    {
        if ($input === null || $input === '') {
            return [];
        }
        $values = is_string($input) ? preg_split('/\r\n|\r|\n/', $input) : $input;
        if (! is_array($values) || count($values) > 10000) {
            throw new DomainException(__('inventory_serial.invalid_serial'));
        }
        $result = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new DomainException(__('inventory_serial.invalid_serial'));
            }
            if (trim($value) !== '') {
                $result[] = trim($value);
            }
        }

        return $result;
    }

    /** @param list<string>|null $sourceNumbers @return list<string> */
    public function receiptNumbers(Product $product, string $baseQuantity, mixed $input, ?array $sourceNumbers = null): array
    {
        $numbers = $this->numbers($input);
        if (! $product->tracks_serials) {
            if ($numbers !== []) {
                throw new DomainException(__('inventory_serial.invalid_serial'));
            }

            return [];
        }
        $keys = [];
        $sourceKeys = $sourceNumbers === null ? null : array_map(fn (string $serial): string => Str::lower(trim($serial)), $sourceNumbers);
        foreach ($numbers as $serial) {
            $key = Str::lower($serial);
            if (mb_strlen($serial) > 100 || preg_match('/[\x00-\x1f\x7f]/u', $serial)
                || in_array($key, $keys, true) || ($sourceKeys !== null && ! in_array($key, $sourceKeys, true))) {
                throw new DomainException(__('inventory_serial.source_selection'));
            }
            $keys[] = $key;
        }
        if (bccomp((string) count($numbers), $baseQuantity, 8) !== 0) {
            throw new DomainException(__('inventory_serial.count_mismatch'));
        }

        return $numbers;
    }

    public function consume(InventoryTransaction $transaction, InventoryReceiptLayer $layer, string $quantity, bool $selectedPurchaseReturn = false): void
    {
        $identity = InventorySerialIdentity::query()->whereKey($layer->inventory_serial_identity_id)->lockForUpdate()->first();
        if ($identity === null || (int) $identity->company_id !== (int) $transaction->company_id
            || (int) $identity->product_id !== (int) $transaction->product_id
            || (int) $identity->current_receipt_layer_id !== (int) $layer->id
            || bccomp($quantity, '1', 8) !== 0
            || (bccomp((string) $transaction->quantity_out, '1', 8) !== 0 && ! $transaction->is_reversal
                && ! ($selectedPurchaseReturn && $transaction->transaction_type === 'purchase_return'))
            || ($transaction->inventory_serial_identity_id !== null && (int) $transaction->inventory_serial_identity_id !== (int) $identity->id)) {
            throw new DomainException(__('inventory_serial.exact_unit_required'));
        }
        if (bccomp((string) $transaction->quantity_out, '1', 8) === 0) {
            $transaction->forceFill(['inventory_serial_identity_id' => $identity->id])->save();
        }
        $identity->forceFill(['current_receipt_layer_id' => null])->save();
    }
}

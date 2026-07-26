<?php

namespace Modules\Core\Services;

use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;

class ProductComponentUnitOptionsService
{
    /**
     * @return list<int>
     */
    public function validUnitIds(Product $product): array
    {
        return collect([
            $product->item_unit_id,
            $product->equivalent_unit_id,
        ])
            ->filter(fn (mixed $id): bool => $id !== null && $id !== '')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function unitForProduct(Product $product, mixed $unitDocNum, int $companyId): ?ItemUnit
    {
        $unitDocNum = trim((string) $unitDocNum);
        $validUnitIds = $this->validUnitIds($product);

        if ($unitDocNum === '' || $validUnitIds === []) {
            return null;
        }

        return ItemUnit::query()
            ->forCompany($companyId)
            ->whereIn('id', $validUnitIds)
            ->where('doc_num', $unitDocNum)
            ->first();
    }

    public function unitIsValidForProduct(Product $product, mixed $unitDocNum, int $companyId): bool
    {
        return $this->unitForProduct($product, $unitDocNum, $companyId) instanceof ItemUnit;
    }

    /**
     * @return list<array{id: string, text: string}>
     */
    public function options(Product $product): array
    {
        $product->loadMissing(['unit', 'equivalentUnit']);

        return collect([$product->unit, $product->equivalentUnit])
            ->filter(fn (mixed $unit): bool => $unit instanceof ItemUnit
                && ! $unit->trashed()
                && (int) $unit->company_id === (int) $product->company_id)
            ->unique(fn (ItemUnit $unit): int => (int) $unit->getKey())
            ->map(fn (ItemUnit $unit): array => $this->option($unit))
            ->values()
            ->all();
    }

    /**
     * @return array{id: string, text: string}
     */
    public function option(ItemUnit $unit): array
    {
        return [
            'id' => (string) $unit->doc_num,
            'text' => trim(implode(' / ', array_filter([$unit->doc_num, $unit->name]))),
        ];
    }
}

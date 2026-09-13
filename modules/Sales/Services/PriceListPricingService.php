<?php

namespace Modules\Sales\Services;

use DomainException;
use Modules\Core\Models\Product;
use Modules\Sales\Models\PriceListLine;
use Modules\Sales\Models\SalesOrderLine;

class PriceListPricingService
{
    public function __construct(
        private readonly SalesUnitConversionService $unitConversions,
        private readonly SalesAmountService $amounts,
    ) {}

    /** @return array{unit_price: string, base_unit_price: string, price_list_line_id: int, price_list_doc_num: string, source: string, allowed_discount_type: string|null, allowed_discount_value: string, maximum_discount_amount: string} */
    public function resolve(int $companyId, ?int $customerId, int $currencyId, Product $product, mixed $unitId, mixed $quantity, string $date): array
    {
        $line = $customerId ? $this->latestLine($companyId, $customerId, $currencyId, $product->getKey(), $date) : null;
        $source = 'customer';
        if (! $line) {
            $line = $this->latestLine($companyId, null, $currencyId, $product->getKey(), $date);
            $source = 'general';
        }
        if (! $line) {
            throw new DomainException(__('price_lists.messages.unpriced_products', ['products' => $product->doc_num.' / '.$product->name]));
        }

        $conversion = $this->unitConversions->snapshot($product, $unitId, $quantity);
        $unitPrice = $this->amounts->round(bcmul((string) $line->unit_price, $conversion['conversion_factor'], 8));
        $maximumDiscount = $this->maximumDiscountAmount($line->allowed_discount_type, (string) $line->allowed_discount_value, $unitPrice, (string) $quantity, $conversion['conversion_factor']);

        return [
            'unit_price' => $unitPrice, 'base_unit_price' => (string) $line->unit_price,
            'price_list_line_id' => $line->getKey(), 'price_list_doc_num' => $line->priceList->doc_num,
            'source' => $source, 'allowed_discount_type' => $line->allowed_discount_type,
            'allowed_discount_value' => (string) $line->allowed_discount_value,
            'maximum_discount_amount' => $maximumDiscount,
        ];
    }

    /** @param list<array<string, mixed>> $lines @return list<array<string, mixed>> */
    public function applyToLines(array $lines, int $companyId, ?int $customerId, int $currencyId, string $date, string $discountMode): array
    {
        $priced = [];
        $missing = [];
        foreach ($lines as $line) {
            $product = Product::query()->forCompany($companyId)->active()->findOrFail($line['product_id']);
            try {
                $price = $this->resolve($companyId, $customerId, $currencyId, $product, $line['unit_id'] ?? null, $line['quantity'], $date);
            } catch (DomainException) {
                $missing[] = $product->doc_num.' / '.$product->name;

                continue;
            }
            $actualDiscount = $discountMode === 'quotation'
                ? $this->quotationDiscountAmount($line, $price['unit_price'])
                : (string) ($line['discount_amount'] ?? 0);
            if ($this->amounts->compare($actualDiscount, $price['maximum_discount_amount']) > 0) {
                throw new DomainException(__('price_lists.messages.discount_exceeded', ['product' => $product->doc_num.' / '.$product->name, 'maximum' => $price['maximum_discount_amount']]));
            }
            $priced[] = [
                ...$line, 'unit_price' => $price['unit_price'], 'price_list_line_id' => $price['price_list_line_id'],
                'allowed_discount_type' => $price['allowed_discount_type'], 'allowed_discount_value' => $price['allowed_discount_value'],
            ];
        }
        if ($missing !== []) {
            throw new DomainException(__('price_lists.messages.unpriced_products', ['products' => implode('، ', $missing)]));
        }

        return $priced;
    }

    /** @param list<array<string, mixed>> $lines @param iterable<int, SalesOrderLine> $storedLines @return list<array<string, mixed>> */
    public function preserveStoredOrderPrices(array $lines, iterable $storedLines, int $companyId, ?int $customerId, int $currencyId, string $date): array
    {
        $stored = collect($storedLines)->values();
        $usedStoredLineIds = [];
        $result = [];
        foreach ($lines as $line) {
            $source = $stored->first(fn (SalesOrderLine $storedLine): bool => ! in_array($storedLine->getKey(), $usedStoredLineIds, true)
                && (int) $storedLine->product_id === (int) $line['product_id']
                && (int) $storedLine->unit_id === (int) ($line['unit_id'] ?? 0));
            if (! $source) {
                $result[] = $this->applyToLines([$line], $companyId, $customerId, $currencyId, $date, 'amount')[0];

                continue;
            }
            $usedStoredLineIds[] = $source->getKey();
            $product = Product::query()->forCompany($companyId)->active()->findOrFail($line['product_id']);
            $conversion = $this->unitConversions->snapshot($product, $line['unit_id'], $line['quantity']);
            $maximum = $this->maximumDiscountAmount($source->allowed_discount_type, (string) $source->allowed_discount_value, (string) $source->unit_price, (string) $line['quantity'], $conversion['conversion_factor']);
            if ($this->amounts->compare((string) ($line['discount_amount'] ?? 0), $maximum) > 0) {
                throw new DomainException(__('price_lists.messages.discount_exceeded', ['product' => $product->doc_num.' / '.$product->name, 'maximum' => $maximum]));
            }
            $result[] = [
                ...$line, 'unit_price' => $source->unit_price, 'price_list_line_id' => $source->price_list_line_id,
                'allowed_discount_type' => $source->allowed_discount_type, 'allowed_discount_value' => $source->allowed_discount_value,
            ];
        }

        return $result;
    }

    private function latestLine(int $companyId, ?int $customerId, int $currencyId, int $productId, string $date): ?PriceListLine
    {
        return PriceListLine::query()->with('priceList')->where('product_id', $productId)
            ->whereHas('priceList', fn ($query) => $query->forCompany($companyId)->where('currency_id', $currencyId)->where('customer_id', $customerId)->effectiveOn($date))
            ->join('price_lists', 'price_lists.id', '=', 'price_list_lines.price_list_id')->select('price_list_lines.*')
            ->orderByDesc('price_lists.valid_from')->orderByDesc('price_lists.price_list_date')->orderByDesc('price_lists.id')->first();
    }

    private function maximumDiscountAmount(?string $type, string $value, string $unitPrice, string $quantity, string $conversionFactor): string
    {
        $gross = $this->amounts->multiply($unitPrice, $quantity);

        return match ($type) {
            PriceListLine::DiscountPercentage => $this->amounts->round($this->amounts->multiply($gross, bcdiv($value, '100', 8), 8)),
            PriceListLine::DiscountFixed => $this->amounts->round(bcmul(bcmul($value, $conversionFactor, 8), $quantity, 8)),
            default => '0.0000',
        };
    }

    /** @param array<string, mixed> $line */
    private function quotationDiscountAmount(array $line, string $unitPrice): string
    {
        $gross = $this->amounts->multiply((string) $line['quantity'], $unitPrice);
        $value = (string) ($line['discount_value'] ?? 0);

        return match ($line['discount_type'] ?? null) {
            PriceListLine::DiscountPercentage => $this->amounts->round($this->amounts->multiply($gross, bcdiv($value, '100', 8), 8)),
            PriceListLine::DiscountFixed => $this->amounts->round($value),
            default => '0.0000',
        };
    }
}

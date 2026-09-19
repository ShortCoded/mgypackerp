<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Currency;
use Modules\Core\Models\Product;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\PriceList;

class PriceListService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly CrudAuditService $audit,
        private readonly SalesAmountService $amounts,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, int $companyId): PriceList
    {
        return DB::transaction(function () use ($data, $companyId): PriceList {
            $record = PriceList::query()->create([
                ...$this->headerValues($data, $companyId),
                ...$this->documents->nextForCompany('price_lists', PriceList::class, $companyId),
                'created_by' => auth()->id(),
            ]);
            $this->syncLines($record, $data['lines']);
            $this->audit->clearCreationUpdateAudit($record);

            return $record->load(['customer', 'currency', 'lines.product']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(PriceList $record, array $data, int $companyId): PriceList
    {
        abort_unless((int) $record->company_id === $companyId, 404);

        return DB::transaction(function () use ($record, $data, $companyId): PriceList {
            $this->audit->saveUpdate($record, $this->headerValues($data, $companyId));
            $this->syncLines($record, $data['lines']);

            return $record->refresh()->load(['customer', 'currency', 'lines.product']);
        });
    }

    public function delete(PriceList $record, int $companyId): void
    {
        abort_unless((int) $record->company_id === $companyId, 404);

        DB::transaction(fn () => $this->audit->softDelete($record));
    }

    /**
     * @param  list<string>  $docNums
     */
    public function bulkDelete(array $docNums, int $companyId): int
    {
        return DB::transaction(function () use ($docNums, $companyId): int {
            $deleted = 0;

            foreach (PriceList::query()->forCompany($companyId)->whereIn('doc_num', $docNums)->get() as $record) {
                $this->delete($record, $companyId);
                $deleted++;
            }

            return $deleted;
        });
    }

    public function restore(PriceList $record, int $companyId): PriceList
    {
        return DB::transaction(function () use ($record, $companyId): PriceList {
            $record = PriceList::withTrashed()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            abort_unless((int) $record->company_id === $companyId, 404);

            if (! $record->trashed()) {
                throw new DomainException(__('price_lists.messages.restore_not_allowed'));
            }

            $this->audit->restore($record, auth()->id());

            return $record->refresh();
        });
    }

    public function increaseByPercentage(PriceList $record, string $percentage, int $companyId): PriceList
    {
        abort_unless((int) $record->company_id === $companyId, 404);

        return DB::transaction(function () use ($record, $percentage, $companyId): PriceList {
            $locked = PriceList::query()->forCompany($companyId)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $lines = $locked->lines()->lockForUpdate()->get();

            if ($lines->isEmpty()) {
                throw new DomainException(__('price_lists.messages.no_lines_to_increase'));
            }

            $multiplier = $this->amounts->add(1, $this->amounts->multiply($percentage, '0.01', 8), 8);

            foreach ($lines as $line) {
                $newPrice = $this->amounts->round($this->amounts->multiply($line->unit_price, $multiplier, 8));
                $line->forceFill(['unit_price' => $newPrice])->save();
            }

            $this->audit->touchUpdateAudit($locked);

            return $locked->refresh()->load(['customer', 'currency', 'lines.product']);
        });
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function headerValues(array $data, int $companyId): array
    {
        return [
            'company_id' => $companyId,
            'customer_id' => filled($data['customer_doc_num'] ?? null)
                ? Customer::query()->forCompany($companyId)->active()->where('doc_num', $data['customer_doc_num'])->valueOrFail('id')
                : null,
            'currency_id' => Currency::query()->forCompany($companyId)->active()->where('doc_num', $data['currency_doc_num'])->valueOrFail('id'),
            'price_list_date' => $data['price_list_date'], 'valid_from' => $data['valid_from'],
            'valid_until' => $data['valid_until'] ?? null, 'notes' => $data['notes'] ?? null,
        ];
    }

    /** @param list<array<string, mixed>> $lines */
    private function syncLines(PriceList $record, array $lines): void
    {
        $retainedLineIds = [];

        foreach ($lines as $index => $line) {
            $product = Product::query()->forCompany((int) $record->company_id)->active()->where('doc_num', $line['product_doc_num'])->firstOrFail();
            $type = filled($line['allowed_discount_type'] ?? null) ? $line['allowed_discount_type'] : null;
            $priceListLine = $record->lines()->updateOrCreate(['product_id' => $product->getKey()], [
                'line_number' => $index + 1, 'unit_price' => $line['unit_price'],
                'allowed_discount_type' => $type,
                'allowed_discount_value' => $type ? ($line['allowed_discount_value'] ?? 0) : 0,
            ]);
            $retainedLineIds[] = $priceListLine->getKey();
        }

        $record->lines()->whereNotIn('id', $retainedLineIds)->delete();
    }
}

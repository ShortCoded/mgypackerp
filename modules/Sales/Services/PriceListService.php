<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Currency;
use Modules\Core\Models\Product;
use Modules\Core\Services\ActivityLogger;
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
        private readonly ActivityLogger $activities,
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
            $locked = PriceList::query()->forCompany($companyId)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $locked->load(['lines.product']);
            $before = $this->historySnapshot($locked);
            $this->audit->saveUpdate($locked, $this->headerValues($data, $companyId));
            $this->syncLines($locked, $data['lines']);
            $locked->refresh()->load(['lines.product']);
            $after = $this->historySnapshot($locked);
            $invalidated = $this->invalidateLifecycleIfChanged($locked, $before, $after);
            $this->logChanges($locked, 'manual', $before, $after, lifecycleInvalidated: $invalidated);

            return $locked->load(['customer', 'currency', 'lines.product']);
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
            $records = PriceList::query()
                ->forCompany($companyId)
                ->whereIn('doc_num', $docNums)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($records as $record) {
                $this->audit->softDelete($record);
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

            $locked->load(['lines.product']);
            $before = $this->historySnapshot($locked);

            $multiplier = $this->amounts->add(1, $this->amounts->multiply($percentage, '0.01', 8), 8);

            foreach ($lines as $line) {
                $newPrice = $this->amounts->round($this->amounts->multiply($line->unit_price, $multiplier, 8));
                $line->forceFill(['unit_price' => $newPrice])->save();
            }

            $this->audit->touchUpdateAudit($locked);
            $locked->refresh()->load(['lines.product']);
            $after = $this->historySnapshot($locked);
            $invalidated = $this->invalidateLifecycleIfChanged($locked, $before, $after);
            $this->logChanges($locked, 'percentage', $before, $after, $percentage, $invalidated);

            return $locked->load(['customer', 'currency', 'lines.product']);
        });
    }

    public function review(PriceList $record, int $companyId): PriceList
    {
        return DB::transaction(function () use ($record, $companyId): PriceList {
            $locked = PriceList::query()->forCompany($companyId)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->reviewed_at !== null) {
                return $locked->load(['reviewedBy', 'approvedBy']);
            }

            $locked->forceFill([
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'approved_by' => null,
                'approved_at' => null,
            ])->save();
            $this->logLifecycle($locked, 'review');

            return $locked->refresh()->load(['reviewedBy', 'approvedBy']);
        });
    }

    public function approve(PriceList $record, int $companyId): PriceList
    {
        return DB::transaction(function () use ($record, $companyId): PriceList {
            $locked = PriceList::query()->forCompany($companyId)->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->approved_at !== null) {
                return $locked->load(['reviewedBy', 'approvedBy']);
            }

            if ($locked->reviewed_at === null || $locked->reviewed_by === null) {
                throw new DomainException(__('price_lists.messages.approval_requires_review'));
            }

            $locked->forceFill([
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ])->save();
            $this->logLifecycle($locked, 'approve');

            return $locked->refresh()->load(['reviewedBy', 'approvedBy']);
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
            'is_print_only' => (bool) ($data['is_print_only'] ?? false),
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

    /** @return array{header: array<string, mixed>, lines: array<string, array<string, mixed>>} */
    private function historySnapshot(PriceList $record): array
    {
        return [
            'header' => [
                'customer' => $record->customer_id
                    ? trim(implode(' / ', array_filter([$record->customer?->doc_num, $record->customer?->name])))
                    : __('price_lists.general'),
                'currency' => trim(implode(' / ', array_filter([$record->currency?->code, $record->currency?->name]))),
                'price_list_date' => $record->price_list_date?->toDateString(),
                'valid_from' => $record->valid_from?->toDateString(),
                'valid_until' => $record->valid_until?->toDateString(),
                'notes' => $record->notes,
                'is_print_only' => (bool) $record->is_print_only,
            ],
            'lines' => $record->lines->mapWithKeys(fn ($line): array => [(string) $line->product_id => [
                'product' => trim(implode(' / ', array_filter([$line->product?->doc_num, $line->product?->name]))),
                'unit_price' => (string) $line->unit_price,
                'allowed_discount_type' => $line->allowed_discount_type,
                'allowed_discount_value' => (string) $line->allowed_discount_value,
            ]])->all(),
        ];
    }

    /**
     * @param  array{header: array<string, mixed>, lines: array<string, array<string, mixed>>}  $before
     * @param  array{header: array<string, mixed>, lines: array<string, array<string, mixed>>}  $after
     */
    private function logChanges(PriceList $record, string $changeType, array $before, array $after, ?string $percentage = null, bool $lifecycleInvalidated = false): void
    {
        $headerChanges = [];
        foreach ($before['header'] as $field => $oldValue) {
            $newValue = $after['header'][$field] ?? null;
            if ($oldValue !== $newValue) {
                $headerChanges[$field] = ['old' => $oldValue, 'new' => $newValue];
            }
        }

        $lineChanges = [];
        foreach (array_unique([...array_keys($before['lines']), ...array_keys($after['lines'])]) as $productId) {
            $oldLine = $before['lines'][$productId] ?? null;
            $newLine = $after['lines'][$productId] ?? null;
            if ($oldLine !== $newLine) {
                $lineChanges[] = [
                    'product' => $newLine['product'] ?? $oldLine['product'] ?? '',
                    'old' => $oldLine,
                    'new' => $newLine,
                ];
            }
        }

        if ($headerChanges === [] && $lineChanges === []) {
            return;
        }

        $this->activities->log(request(), 'sales', 'price_lists.'.$changeType, 'success', [
            'subject' => $record,
            'properties_only' => true,
            'properties' => array_filter([
                'change_type' => $changeType,
                'percentage' => $percentage,
                'lifecycle_invalidated' => $lifecycleInvalidated,
                'header_changes' => $headerChanges,
                'line_changes' => $lineChanges,
            ], fn (mixed $value): bool => $value !== null && $value !== []),
        ]);
    }

    /**
     * @param  array{header: array<string, mixed>, lines: array<string, array<string, mixed>>}  $before
     * @param  array{header: array<string, mixed>, lines: array<string, array<string, mixed>>}  $after
     */
    private function invalidateLifecycleIfChanged(PriceList $record, array $before, array $after): bool
    {
        if ($before === $after || ($record->reviewed_at === null && $record->approved_at === null)) {
            return false;
        }

        $record->forceFill([
            'reviewed_by' => null,
            'reviewed_at' => null,
            'approved_by' => null,
            'approved_at' => null,
        ])->save();

        return true;
    }

    private function logLifecycle(PriceList $record, string $changeType): void
    {
        $this->activities->log(request(), 'sales', 'price_lists.'.$changeType, 'success', [
            'subject' => $record,
            'properties_only' => true,
            'properties' => ['change_type' => $changeType],
        ]);
    }
}

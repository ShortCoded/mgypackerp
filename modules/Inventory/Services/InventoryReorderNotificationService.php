<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Product;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\NotificationService;
use Modules\Core\Services\OperationalNotificationService;
use Modules\Inventory\Models\InventoryReorderAlert;
use Modules\Inventory\Models\InventoryReservation;

class InventoryReorderNotificationService
{
    public function __construct(
        private readonly InventoryReportService $reports,
        private readonly FinancialPeriodService $periods,
        private readonly OperationalNotificationService $operationalNotifications,
        private readonly NotificationService $notifications,
    ) {}

    public function monitor(?int $companyId = null): int
    {
        $created = 0;
        foreach (Company::withTrashed()->when($companyId !== null, fn ($query) => $query->whereKey($companyId))
            ->where(fn ($query) => $query->where(fn ($active) => $active->whereNull('deleted_at')->where('status', 'active'))
                ->orWhereIn('id', InventoryReorderAlert::query()->where('is_low', true)->select('company_id')))->lazyById(50) as $company) {
            $periodId = $this->prepareCompany($company->id);
            if ($periodId === null) {
                continue;
            }
            foreach (Branch::query()->where('company_id', $company->id)->where('status', 'active')->lazyById(25) as $branch) {
                Product::withTrashed()->where('company_id', $company->id)->where(function (Builder $query) use ($company, $branch, $periodId): void {
                    $query->where(fn ($active) => $active->whereNull('deleted_at')->where('status', 'active')
                        ->whereIn('item_classification', Product::stockableItemClassifications())->where('reorder_point', '>', 0))
                        ->orWhereIn('id', InventoryReorderAlert::query()->where('company_id', $company->id)
                            ->where('branch_id', $branch->id)->where('financial_period_id', $periodId)->where('is_low', true)->select('product_id'));
                })->chunkById(100, function ($products) use ($company, $branch, $periodId, &$created): void {
                    $created += $this->monitorBatch($company->id, $branch->id, $periodId, $products->modelKeys());
                });
            }
        }

        return $created;
    }

    private function prepareCompany(int $companyId): ?int
    {
        return DB::transaction(function () use ($companyId): ?int {
            $company = Company::withTrashed()->whereKey($companyId)->lockForUpdate()->first();
            if (! $company || $company->trashed() || $company->status !== 'active') {
                $this->recover(InventoryReorderAlert::query()->where('company_id', $companyId));

                return null;
            }
            try {
                $period = $this->periods->resolveOpenForPostingDate($companyId, today()->toDateString(), lockForUpdate: true);
            } catch (DomainException) {
                $this->recover(InventoryReorderAlert::query()->where('company_id', $companyId));

                return null;
            }
            $this->recover(InventoryReorderAlert::query()->where('company_id', $companyId)->where('financial_period_id', '<>', $period->id));
            $this->recover(InventoryReorderAlert::query()->where('company_id', $companyId)
                ->whereNotIn('branch_id', Branch::query()->where('company_id', $companyId)->where('status', 'active')->select('id')));

            return $period->id;
        }, 3);
    }

    /** @param list<int> $productIds */
    private function monitorBatch(int $companyId, int $branchId, int $periodId, array $productIds): int
    {
        return DB::transaction(function () use ($companyId, $branchId, $periodId, $productIds): int {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->first();
            $branch = Branch::query()->where('company_id', $companyId)->whereKey($branchId)->first();
            if (! $company || $company->status !== 'active' || ! $branch || $branch->status !== 'active') {
                $this->recover(InventoryReorderAlert::query()->where('company_id', $companyId)->where('branch_id', $branchId));

                return 0;
            }
            try {
                $this->periods->resolveOpenForPostingDate($companyId, today()->toDateString(), $periodId, lockForUpdate: true);
            } catch (DomainException) {
                $this->recover(InventoryReorderAlert::query()->where('company_id', $companyId)->where('branch_id', $branchId)->where('financial_period_id', $periodId));

                return 0;
            }
            $eligibleIds = Product::query()->where('company_id', $companyId)->whereKey($productIds)->where('status', 'active')
                ->whereIn('item_classification', Product::stockableItemClassifications())->where('reorder_point', '>', 0)->pluck('id')->all();
            $filters = ['financial_period_id' => $periodId, 'branch_id' => $branchId,
                'product_ids' => $eligibleIds, 'as_of' => today()->toDateString()];
            $lowStock = $eligibleIds === [] ? collect() : $this->reports->lowStockRows($companyId, $branchId,
                $this->reports->balances($companyId, $filters),
                $this->reports->reservations($companyId, [...$filters, 'status' => InventoryReservation::StatusActive]), $filters);
            $lowPairs = $lowStock->map(fn ($row): string => $row->branchStore->id.'|'.$row->product->id)->all();
            $states = InventoryReorderAlert::query()->where('company_id', $companyId)->where('branch_id', $branchId)
                ->where('financial_period_id', $periodId)->whereIn('product_id', $productIds)->lockForUpdate()->get();
            foreach ($states as $state) {
                if (! in_array($state->branch_store_id.'|'.$state->product_id, $lowPairs, true)) {
                    $this->recover(InventoryReorderAlert::query()->whereKey($state->id));
                }
            }
            $recipients = $this->operationalNotifications->recipientsForScope(InventoryReorderAlert::Permission, $companyId, $branchId, $periodId);
            $created = 0;
            foreach ($lowStock as $row) {
                $state = InventoryReorderAlert::query()->firstOrCreate(['company_id' => $companyId, 'financial_period_id' => $periodId,
                    'branch_store_id' => $row->branchStore->id, 'product_id' => $row->product->id], ['branch_id' => $branchId]);
                if (! $state->is_low) {
                    $state->forceFill(['is_low' => true, 'episode' => $state->episode + 1, 'low_since' => now(), 'recovered_at' => null]);
                }
                $state->forceFill(['last_checked_at' => now()])->save();
                $metadata = ['financial_period_id' => $periodId, 'product_id' => $row->product->id,
                    'product_code' => $row->product->doc_num, 'product_name' => $row->product->name,
                    'branch_store_id' => $row->branchStore->id, 'store_name' => $row->branchStore->name,
                    'branch_name' => $branch->name, 'unit_name' => $row->product->unit?->name ?? '',
                    'available_quantity' => $row->available, 'reorder_point' => $row->reorder_point, 'episode' => $state->episode];
                $message = $this->notifications->reorderPresentation($metadata);
                $eventUuid = Str::uuid()->toString();
                foreach ($recipients as $recipient) {
                    $notification = $this->notifications->createImmediate($recipient, InventoryReorderAlert::NotificationType,
                        $message['title'], $message['body'], route('admin.inventory.reports.index',
                            ['branch_store_id' => $row->branchStore->id, 'product_id' => $row->product->id, 'operational_focus' => 'low_stock'], false),
                        $metadata, "inventory.reorder:{$state->id}:{$state->episode}:{$recipient->id}",
                        ['event_uuid' => $eventUuid, 'module' => 'inventory', 'severity' => 'action', 'requires_action' => true,
                            'sound_key' => 'action', 'required_permission' => InventoryReorderAlert::Permission,
                            'company_id' => $companyId, 'branch_id' => $branchId]);
                    $created += (int) $notification->wasRecentlyCreated;
                }
            }

            return $created;
        }, 3);
    }

    /** @param Builder<InventoryReorderAlert> $query */
    private function recover(Builder $query): void
    {
        $query->where('is_low', true)->update(['is_low' => false, 'recovered_at' => now(), 'last_checked_at' => now()]);
    }
}

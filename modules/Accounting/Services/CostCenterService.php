<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingCompanyContextService;

class CostCenterService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
        private readonly CrudAuditService $audit,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function create(array $data): CostCenter
    {
        return DB::transaction(function () use ($data): CostCenter {
            $companyId = $this->companies->requireCompanyId();
            $linkedAccountIds = $this->linkedAccountIds($data['linked_account_doc_nums'] ?? [], $companyId);
            $document = array_key_exists('doc_number', $data) && $data['doc_number']
                ? ['doc_number' => (int) $data['doc_number'], 'doc_num' => $this->documentNumbers->format('cost_centers', (int) $data['doc_number'])]
                : $this->documentNumbers->nextForCompany('cost_centers', CostCenter::class, $companyId);

            $costCenter = CostCenter::query()->create([
                ...$this->values($data, $companyId),
                ...$document,
                'created_by' => auth()->id(),
            ]);
            $costCenter->accounts()->sync($linkedAccountIds);
            $this->audit->clearCreationUpdateAudit($costCenter);

            return $costCenter->refresh()->load('accounts');
        });
    }

    public function update(CostCenter $costCenter, array $data): array
    {
        return DB::transaction(function () use ($costCenter, $data): array {
            $companyId = $this->companies->requireCompanyId();
            $this->assertBelongsToCompany($costCenter, $companyId);
            $values = $this->values($data, $companyId, $costCenter);
            $linkedAccountIds = $this->linkedAccountIds($data['linked_account_doc_nums'] ?? [], $companyId, $costCenter);
            $currentLinkedAccountIds = $costCenter->accounts()
                ->pluck('accounts.id')
                ->map(fn (mixed $id): int => (int) $id)
                ->sort()
                ->values()
                ->all();

            if (array_key_exists('doc_number', $data) && $data['doc_number']) {
                $values['doc_number'] = (int) $data['doc_number'];
                $values['doc_num'] = $this->documentNumbers->format('cost_centers', (int) $data['doc_number']);
            }

            $changes = [];
            foreach ($values as $field => $value) {
                if ((string) $costCenter->{$field} !== (string) $value) {
                    $changes[$field] = ['old' => $costCenter->{$field}, 'new' => $value];
                }
            }

            $sortedLinkedAccountIds = collect($linkedAccountIds)->sort()->values()->all();
            if ($currentLinkedAccountIds !== $sortedLinkedAccountIds) {
                $changes['linked_account_ids'] = [
                    'old' => $currentLinkedAccountIds,
                    'new' => $sortedLinkedAccountIds,
                ];
            }

            if ($changes === []) {
                return ['record' => $costCenter->refresh()->load('accounts'), 'changed' => false, 'changes' => []];
            }

            $this->audit->saveUpdate($costCenter, $values);
            $costCenter->accounts()->sync($linkedAccountIds);

            return ['record' => $costCenter->refresh()->load('accounts'), 'changed' => true, 'changes' => $changes];
        });
    }

    public function delete(CostCenter $costCenter): void
    {
        $this->assertBelongsToCompany($costCenter, $this->companies->requireCompanyId());

        if ($costCenter->isProtectedRoot()) {
            throw new DomainException(__('cost_centers.messages.delete_blocked_system'));
        }

        if ($costCenter->children()->exists()) {
            throw new DomainException(__('cost_centers.messages.delete_blocked_children'));
        }

        DB::transaction(function () use ($costCenter): void {
            $this->audit->softDelete($costCenter);
        });
    }

    /**
     * @param  list<string>  $docNums
     */
    public function bulkDelete(array $docNums): int
    {
        $deleted = 0;
        $companyId = $this->companies->requireCompanyId();

        DB::transaction(function () use ($docNums, $companyId, &$deleted): void {
            foreach (CostCenter::query()->forCompany($companyId)->whereIn('doc_num', $docNums)->get() as $costCenter) {
                $this->delete($costCenter);
                $deleted++;
            }
        });

        return $deleted;
    }

    public function restore(CostCenter $costCenter): CostCenter
    {
        return DB::transaction(function () use ($costCenter): CostCenter {
            $companyId = $this->companies->requireCompanyId();
            $costCenter = CostCenter::withTrashed()->whereKey($costCenter->getKey())->lockForUpdate()->firstOrFail();
            $this->assertBelongsToCompany($costCenter, $companyId);

            if (CostCenter::query()->forCompany($companyId)->where('doc_num', $costCenter->doc_num)->whereKeyNot($costCenter->getKey())->exists()
                || CostCenter::query()->forCompany($companyId)->where('doc_number', $costCenter->doc_number)->whereKeyNot($costCenter->getKey())->exists()
                || CostCenter::query()->forCompany($companyId)->where('cost_center_code', $costCenter->cost_center_code)->whereKeyNot($costCenter->getKey())->exists()) {
                throw new DomainException(__('cost_centers.messages.restore_conflict'));
            }

            $this->audit->restore($costCenter, auth()->id());

            return $costCenter->refresh();
        });
    }

    private function values(array $data, int $companyId, ?CostCenter $current = null): array
    {
        $parent = ! empty($data['parent_doc_num'])
            ? CostCenter::query()->forCompany($companyId)->where('doc_num', $data['parent_doc_num'])->first()
            : null;
        $submittedCode = trim((string) ($data['cost_center_code'] ?? ''));
        $parentChanged = $current instanceof CostCenter && (int) ($current->parent_id ?? 0) !== (int) ($parent?->getKey() ?? 0);

        return [
            'company_id' => $companyId,
            'parent_id' => $parent?->getKey(),
            'cost_center_code' => $this->resolvedCostCenterCode($companyId, $parent, $submittedCode, $current, $parentChanged),
            'name' => $data['name'],
            'name_en' => $data['name_en'] ?? null,
            'is_group' => (bool) ($data['is_group'] ?? false),
            'status' => $data['status'] ?? 'active',
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * @return list<int>
     */
    private function linkedAccountIds(mixed $docNums, int $companyId, ?CostCenter $current = null): array
    {
        if (! is_array($docNums)) {
            return [];
        }

        $normalizedDocNums = collect($docNums)
            ->filter(fn (mixed $docNum): bool => is_string($docNum) && trim($docNum) !== '')
            ->map(fn (string $docNum): string => trim($docNum))
            ->unique()
            ->values();

        if ($normalizedDocNums->isEmpty()) {
            return [];
        }

        $currentAccounts = $current instanceof CostCenter
            ? $current->accounts()
                ->where('accounts.company_id', $companyId)
                ->get(['accounts.id', 'accounts.doc_num'])
                ->keyBy('doc_num')
            : collect();
        $selectableAccounts = Account::query()
            ->forCompany($companyId)
            ->active()
            ->whereIn('doc_num', $normalizedDocNums->all())
            ->get(['id', 'doc_num'])
            ->keyBy('doc_num');

        return $normalizedDocNums
            ->map(function (string $docNum) use ($currentAccounts, $selectableAccounts): int {
                $account = $currentAccounts->get($docNum) ?? $selectableAccounts->get($docNum);

                if (! $account instanceof Account) {
                    throw (new ModelNotFoundException)->setModel(Account::class, [$docNum]);
                }

                return (int) $account->getKey();
            })
            ->unique()
            ->values()
            ->all();
    }

    public function nextCostCenterCode(?CostCenter $parent, ?int $companyId = null): string
    {
        $companyId ??= $parent?->company_id ? (int) $parent->company_id : $this->companies->requireCompanyId();

        if ($parent instanceof CostCenter) {
            return $this->nextChildCostCenterCode($parent);
        }

        return $this->nextRootCostCenterCode($companyId);
    }

    public function assertVisible(CostCenter $costCenter): void
    {
        $this->assertBelongsToCompany($costCenter, $this->companies->requireCompanyId());
    }

    private function assertBelongsToCompany(CostCenter $costCenter, int $companyId): void
    {
        if ((int) $costCenter->company_id !== $companyId) {
            abort(404);
        }
    }

    private function resolvedCostCenterCode(
        int $companyId,
        ?CostCenter $parent,
        string $submittedCode,
        ?CostCenter $current,
        bool $parentChanged
    ): string {
        if ($submittedCode !== '' && (! $parentChanged || ! $current instanceof CostCenter || $submittedCode !== (string) $current->cost_center_code)) {
            return $submittedCode;
        }

        return $this->nextCostCenterCode($parent, $companyId);
    }

    private function nextChildCostCenterCode(CostCenter $parent): string
    {
        $prefix = (string) $parent->cost_center_code;
        $suffixes = CostCenter::query()
            ->withTrashed()
            ->where('company_id', $parent->company_id)
            ->where('parent_id', $parent->getKey())
            ->pluck('cost_center_code')
            ->map(function (string $costCenterCode) use ($prefix): ?int {
                $suffix = substr($costCenterCode, strlen($prefix));

                return $suffix !== '' && ctype_digit($suffix) ? (int) $suffix : null;
            })
            ->filter(fn (?int $suffix): bool => $suffix !== null)
            ->values();

        return $prefix.(string) (($suffixes->max() ?? 0) + 1);
    }

    private function nextRootCostCenterCode(int $companyId): string
    {
        $codes = CostCenter::query()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->whereNull('parent_id')
            ->pluck('cost_center_code')
            ->map(fn (string $code): ?int => ctype_digit($code) ? (int) $code : null)
            ->filter(fn (?int $code): bool => $code !== null)
            ->values();

        return (string) (($codes->max() ?? 0) + 1);
    }
}

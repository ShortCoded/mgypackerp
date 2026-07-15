<?php

namespace Modules\Production\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Production\Models\ProductionIdentifier;

class ProductionIdentifierService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
        private readonly CrudAuditService $audit,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function create(array $data): ProductionIdentifier
    {
        return DB::transaction(function () use ($data): ProductionIdentifier {
            $companyId = $this->companies->requireCompanyId();
            $document = $this->documentNumbers->nextForCompany('production_identifiers', ProductionIdentifier::class, $companyId);

            $identifier = ProductionIdentifier::query()->create([
                ...$this->values($data, $companyId),
                ...$document,
                'created_by' => auth()->id(),
            ]);
            $this->audit->clearCreationUpdateAudit($identifier);

            return $identifier->refresh();
        });
    }

    public function update(ProductionIdentifier $identifier, array $data): array
    {
        return DB::transaction(function () use ($identifier, $data): array {
            $companyId = $this->companies->requireCompanyId();
            $this->assertBelongsToCompany($identifier, $companyId);
            $values = $this->values($data, $companyId);

            $changes = [];
            foreach ($values as $field => $value) {
                if ((string) $identifier->{$field} !== (string) $value) {
                    $changes[$field] = ['old' => $identifier->{$field}, 'new' => $value];
                }
            }

            if ($changes === []) {
                return ['record' => $identifier->refresh(), 'changed' => false, 'changes' => []];
            }

            $this->audit->saveUpdate($identifier, $values);

            return ['record' => $identifier->refresh(), 'changed' => true, 'changes' => $changes];
        });
    }

    public function delete(ProductionIdentifier $identifier): void
    {
        $this->assertBelongsToCompany($identifier, $this->companies->requireCompanyId());

        if ($identifier->children()->exists()) {
            throw new DomainException(__('production_identifiers.messages.delete_blocked_children'));
        }

        DB::transaction(function () use ($identifier): void {
            $this->audit->softDelete($identifier);
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
            foreach (ProductionIdentifier::query()->forCompany($companyId)->whereIn('doc_num', $docNums)->get() as $identifier) {
                $this->delete($identifier);
                $deleted++;
            }
        });

        return $deleted;
    }

    public function restore(ProductionIdentifier $identifier): ProductionIdentifier
    {
        return DB::transaction(function () use ($identifier): ProductionIdentifier {
            $companyId = $this->companies->requireCompanyId();
            $identifier = ProductionIdentifier::withTrashed()->whereKey($identifier->getKey())->lockForUpdate()->firstOrFail();
            $this->assertBelongsToCompany($identifier, $companyId);

            if (ProductionIdentifier::query()->forCompany($companyId)->where('doc_num', $identifier->doc_num)->whereKeyNot($identifier->getKey())->exists()
                || ProductionIdentifier::query()->forCompany($companyId)->where('doc_number', $identifier->doc_number)->whereKeyNot($identifier->getKey())->exists()) {
                throw new DomainException(__('production_identifiers.messages.restore_conflict'));
            }

            $this->audit->restore($identifier, auth()->id());

            return $identifier->refresh();
        });
    }

    public function assertVisible(ProductionIdentifier $identifier): void
    {
        $this->assertBelongsToCompany($identifier, $this->companies->requireCompanyId());
    }

    private function values(array $data, int $companyId): array
    {
        $parent = ! empty($data['parent_doc_num'])
            ? ProductionIdentifier::query()
                ->forCompany($companyId)
                ->where('doc_num', $data['parent_doc_num'])
                ->first()
            : null;

        return [
            'company_id' => $companyId,
            'parent_id' => $parent?->getKey(),
            'name' => $data['name'],
            'is_group' => (bool) ($data['is_group'] ?? false),
            'status' => $data['status'] ?? 'active',
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function assertBelongsToCompany(ProductionIdentifier $identifier, int $companyId): void
    {
        if ((int) $identifier->company_id !== $companyId) {
            abort(404);
        }
    }
}

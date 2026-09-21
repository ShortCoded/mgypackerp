<?php

namespace Modules\HR\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Core\Services\Select2ResponseService;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrFoundationModel;
use Modules\HR\Models\HrLookupModel;

class HrSelect2Service
{
    public function __construct(
        private readonly DataTableSearchService $searchService,
        private readonly Select2ResponseService $select2,
        private readonly HrLookupRegistry $lookups,
        private readonly HrFoundationRegistry $foundation,
        private readonly OperatingCompanyContextService $companies,
        private readonly OperatingScopeAccessService $scope,
    ) {}

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function lookup(string $resource, Request $request): array
    {
        $definition = $this->lookups->get($resource);
        $model = $definition->modelClass;
        $search = $request->input('q', $request->input('term'));

        /** @var Builder<HrLookupModel> $query */
        $query = $model::query()
            ->select(['doc_num', 'name', 'notes', 'doc_number'])
            ->orderBy('name')
            ->orderBy('doc_number');

        $this->applySearch($query, $search, $definition->table);

        return $this->select2->paginated($query, $request, fn (HrLookupModel $record): array => $this->asSelect2Option($record));
    }

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function foundation(string $resource, Request $request): array
    {
        $definition = $this->foundation->get($resource);
        $model = $definition->modelClass;
        $search = $request->input('q', $request->input('term'));

        /** @var Builder<HrFoundationModel> $query */
        $query = $model::query()
            ->select(['doc_num', 'name', 'notes', 'doc_number'])
            ->where('status', 'active')
            ->orderBy('name')
            ->orderBy('doc_number');

        if ($definition->companyScoped) {
            $this->companies->applyCompanyScope($query, $definition->table, $request);
        }

        if ($resource === 'sections') {
            $query->addSelect('department_id')->with('department:id,doc_num');
            $departmentDocNum = $request->string('department_doc_num')->trim()->toString();

            if ($departmentDocNum !== '') {
                $query->whereHas('department', fn (Builder $query): Builder => $query->where('doc_num', $departmentDocNum));
            }
        }

        $this->applySearch($query, $search, $definition->table);

        return $this->select2->paginated($query, $request, function (HrFoundationModel $record) use ($resource): array {
            $option = $this->asSelect2Option($record);

            if ($resource === 'sections') {
                $option['department_doc_num'] = (string) ($record->department?->doc_num ?? '');
            }

            return $option;
        });
    }

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function employees(Request $request): array
    {
        $search = $request->input('q', $request->input('term'));
        $company = $this->companies->currentCompany($request);
        $branchIds = $company === null
            ? []
            : $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])->pluck('branches.id')->all();

        $query = HrEmployee::query()
            ->select(['id', 'doc_num', 'full_name', 'doc_number'])
            ->when($company === null, fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->when($company !== null, fn (Builder $query): Builder => $query
                ->where('company_id', $company->getKey())
                ->whereIn('branch_id', $branchIds !== [] ? $branchIds : [0]))
            ->where('status', 'active')
            ->orderBy('full_name')
            ->orderBy('doc_number');

        $terms = $this->searchService->terms(is_string($search) ? $search : null);

        if ($terms !== []) {
            $this->searchService->applyMultiTermSearch($query, $terms, [
                'text' => [
                    'hr_employees.doc_num',
                    'hr_employees.full_name',
                ],
            ]);
        }

        $useDocumentNumber = $request->string('identity')->toString() === 'doc_num';

        return $this->select2->paginated($query, $request, fn (HrEmployee $employee): array => [
            'id' => $useDocumentNumber ? (string) $employee->doc_num : (string) $employee->getKey(),
            'text' => trim(implode(' / ', array_filter([$employee->full_name, $employee->doc_num]))),
        ]);
    }

    /**
     * @param  Builder<*>  $query
     */
    private function applySearch(Builder $query, mixed $search, string $table): void
    {
        $terms = $this->searchService->terms(is_string($search) ? $search : null);

        if ($terms === []) {
            return;
        }

        $this->searchService->applyMultiTermSearch($query, $terms, [
            'text' => [
                "{$table}.doc_num",
                "{$table}.name",
                "{$table}.notes",
            ],
        ]);
    }

    /**
     * @return array{id: string, text: string}
     */
    public function asSelect2Option(HrLookupModel|HrFoundationModel|Model $record): array
    {
        $name = (string) ($record->name ?? $record->full_name ?? '');

        return [
            'id' => (string) $record->doc_num,
            'text' => trim(implode(' / ', array_filter([$name, $record->doc_num]))),
        ];
    }
}

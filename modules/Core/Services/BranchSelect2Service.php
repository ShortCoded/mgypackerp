<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Modules\Core\Models\Branch;

class BranchSelect2Service
{
    public function __construct(
        private readonly DataTableSearchService $searchService,
        private readonly Select2ResponseService $select2,
        private readonly OperatingScopeAccessService $scopeAccess,
    ) {}

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function paginated(Request $request): array
    {
        $search = $request->input('q', $request->input('term'));
        $companyDocNums = $this->companyDocNums($request);

        if ($this->shouldRestrictToOperatingScope($request)) {
            $user = $request->user();
            $query = $user instanceof User
                ? $this->scopeAccess->allowedBranchQuery($user, $companyDocNums)
                : Branch::query()
                    ->join('companies', 'companies.id', '=', 'branches.company_id')
                    ->whereRaw('1 = 0');
        } else {
            $query = Branch::query()
                ->join('companies', 'companies.id', '=', 'branches.company_id')
                ->whereNull('companies.deleted_at')
                ->where('companies.status', 'active')
                ->active()
                ->orderBy('branches.name')
                ->orderBy('branches.doc_number');

            if ($companyDocNums !== []) {
                $query->whereIn('companies.doc_num', $companyDocNums);
            }
        }

        $query->select([
            'branches.doc_num',
            'branches.name',
            'branches.doc_number',
            'branches.type',
            'companies.name as company_name',
            'companies.doc_num as company_doc_num',
            'companies.doc_number as company_doc_number',
        ]);

        $terms = $this->searchService->terms(is_string($search) ? $search : null);

        if ($terms !== []) {
            $this->searchService->applyMultiTermSearch($query, $terms, [
                'text' => [
                    'branches.doc_num',
                    'branches.name',
                    'branches.type',
                    'companies.name',
                    'companies.doc_num',
                ],
            ]);
        }

        return $this->select2->paginated($query, $request, fn (Branch $branch): array => $this->item($branch));
    }

    /**
     * @return array{id: string, text: string, company_doc_num: string|null}
     */
    public function item(Branch $branch): array
    {
        return [
            'id' => (string) $branch->doc_num,
            'text' => $this->label($branch),
            'company_doc_num' => $branch->company_doc_num ?? $branch->company?->doc_num,
        ];
    }

    public function label(Branch $branch): string
    {
        return trim(implode(' / ', array_filter([
            $branch->name,
            $branch->doc_num,
            $branch->company_name ?? $branch->company?->name,
            $this->typeLabel($branch),
        ])));
    }

    private function typeLabel(Branch $branch): ?string
    {
        if (! is_string($branch->type) || trim($branch->type) === '') {
            return null;
        }

        $key = "branches.types.{$branch->type}";
        $label = __($key);

        return $label === $key ? $branch->type : $label;
    }

    /**
     * @return list<string>
     */
    private function companyDocNums(Request $request): array
    {
        $value = $request->input('company_doc_nums', $request->input('company_doc_num', []));
        $values = is_array($value) ? $value : explode(',', (string) $value);

        return collect($values)
            ->filter(fn (mixed $docNum): bool => is_string($docNum) && trim($docNum) !== '')
            ->map(fn (string $docNum): string => trim($docNum))
            ->unique()
            ->values()
            ->all();
    }

    private function shouldRestrictToOperatingScope(Request $request): bool
    {
        return $request->string('access_scope')->trim()->toString() === 'operating_scope';
    }
}

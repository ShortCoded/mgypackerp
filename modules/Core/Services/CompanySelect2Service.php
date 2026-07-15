<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Modules\Core\Models\Company;

class CompanySelect2Service
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

        if ($this->shouldRestrictToAccessibleCompanies($request)) {
            $user = $request->user();

            if ($user instanceof User) {
                $query = $this->scopeAccess->allowedCompanyQuery($user);
            } else {
                $query = Company::query()->whereRaw('1 = 0');
            }
        } else {
            $query = Company::query()
                ->active()
                ->orderBy('companies.name')
                ->orderBy('companies.doc_number');
        }

        $query->select([
            'companies.doc_num',
            'companies.name',
            'companies.legal_name',
            'companies.commercial_name',
            'companies.doc_number',
        ]);

        $terms = $this->searchService->terms(is_string($search) ? $search : null);

        if ($terms !== []) {
            $this->searchService->applyMultiTermSearch($query, $terms, [
                'text' => [
                    'companies.doc_num',
                    'companies.name',
                    'companies.legal_name',
                    'companies.commercial_name',
                ],
            ]);
        }

        return $this->select2->paginated($query, $request, fn (Company $company): array => $this->item($company));
    }

    /**
     * @return array{id: string, text: string}
     */
    public function item(Company $company): array
    {
        return [
            'id' => (string) $company->doc_num,
            'text' => $this->label($company),
        ];
    }

    public function label(Company $company): string
    {
        return trim(implode(' / ', array_filter([
            $company->doc_num,
            $company->name,
        ])));
    }

    private function shouldRestrictToAccessibleCompanies(Request $request): bool
    {
        return in_array($request->string('access_scope')->trim()->toString(), [
            'branch_form',
            'operating_scope',
        ], true);
    }
}

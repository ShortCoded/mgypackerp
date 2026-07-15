<?php

namespace Modules\Core\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Models\Company;
use Modules\Core\Services\CompanyService;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\SettingService;
use Yajra\DataTables\Facades\DataTables;

class CompaniesDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $searchService,
        private readonly CompanyService $companies,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $searchColumns = $this->searchColumns();
        $trashFilter = $this->trashFilter($request);
        $canView = (bool) $request->user()?->can('companies.view');
        $canCreateCompany = $this->companies->canCreateCompany();

        $query = $this->baseQuery($trashFilter)
            ->leftJoin('users as created_users', 'created_users.id', '=', 'companies.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'companies.updated_by')
            ->leftJoin('hr_countries as company_countries', 'company_countries.id', '=', 'companies.country_id')
            ->leftJoin('hr_governorates as company_governorates', 'company_governorates.id', '=', 'companies.governorate_id')
            ->leftJoin('hr_cities as company_cities', 'company_cities.id', '=', 'companies.city_id')
            ->leftJoin('hr_areas as company_areas', 'company_areas.id', '=', 'companies.area_id')
            ->select([
                'companies.id',
                'companies.doc_number',
                'companies.doc_num',
                'companies.name',
                'companies.legal_name',
                'companies.commercial_name',
                'companies.status',
                'companies.is_main',
                'companies.phone',
                'companies.mobile',
                'companies.hotline',
                'companies.email',
                'companies.city',
                'company_cities.name as city_name',
                'companies.notes',
                'companies.created_at',
                'companies.updated_at',
                'companies.deleted_at',
                'created_users.name as created_by_name',
                'updated_users.name as updated_by_name',
            ]);

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request, $searchColumns): void {
                $search = $request->input('search.value');
                $terms = $this->searchService->terms(is_string($search) ? $search : null);

                if ($terms === []) {
                    return;
                }

                $this->searchService->applyMultiTermSearch($query, $terms, $searchColumns);
            })
            ->addColumn('checkbox', fn (Company $company): string => view('modules.core.companies.partials.checkbox', compact('company'))->render())
            ->editColumn('doc_num', fn (Company $company): string => $this->docNumColumn($company, $canView))
            ->editColumn('name', fn (Company $company): string => $this->ellipsisText($company->name))
            ->editColumn('legal_name', fn (Company $company): string => $this->ellipsisText($company->legal_name))
            ->editColumn('commercial_name', fn (Company $company): string => $this->ellipsisText($company->commercial_name))
            ->editColumn('status', fn (Company $company): string => view('modules.core.companies.partials.status', compact('company'))->render())
            ->editColumn('is_main', fn (Company $company): string => view('modules.core.companies.partials.main-badge', compact('company'))->render())
            ->editColumn('phone', fn (Company $company): string => trim(view('components.contact.phone-actions', [
                'phone' => $company->phone ?: $company->mobile ?: $company->hotline,
                'class' => 'dt-ellipsis-content',
            ])->render()))
            ->editColumn('email', fn (Company $company): string => trim(view('components.contact.email-link', [
                'email' => $company->email,
                'class' => 'dt-ellipsis-content',
            ])->render()))
            ->editColumn('city', fn (Company $company): string => $this->ellipsisText($company->city_name ?: $company->city))
            ->addColumn('created_by', fn (Company $company): string => $this->ellipsisText($company->created_by_name))
            ->editColumn('created_at', fn (Company $company): string => $this->plainText($company->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('updated_by', fn (Company $company): string => $this->ellipsisText($company->updated_by_name))
            ->editColumn('updated_at', fn (Company $company): string => $this->plainText($company->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (Company $company): string => view('modules.core.companies.partials.actions', [
                'company' => $company,
                'canCreateCompany' => $canCreateCompany,
            ])->render())
            ->orderColumn('doc_num', 'companies.doc_number $1')
            ->orderColumn('name', 'companies.name $1')
            ->orderColumn('legal_name', 'companies.legal_name $1')
            ->orderColumn('commercial_name', 'companies.commercial_name $1')
            ->orderColumn('status', 'companies.status $1')
            ->orderColumn('is_main', 'companies.is_main $1')
            ->orderColumn('phone', 'companies.phone $1')
            ->orderColumn('email', 'companies.email $1')
            ->orderColumn('city', 'company_cities.name $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'companies.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', 'companies.updated_at $1')
            ->removeColumn('id')
            ->rawColumns(['checkbox', 'doc_num', 'name', 'legal_name', 'commercial_name', 'status', 'is_main', 'phone', 'email', 'city', 'created_by', 'updated_by', 'actions'])
            ->toJson();
    }

    /**
     * @return Builder<Company>
     */
    private function baseQuery(string $trashFilter): Builder
    {
        return match ($trashFilter) {
            'trashed' => Company::onlyTrashed(),
            'all' => Company::withTrashed(),
            default => Company::query(),
        };
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('companies.view_trashed')) {
            return 'active';
        }

        $filter = $request->string('trash_filter')->toString();

        return in_array($filter, ['active', 'trashed', 'all'], true) ? $filter : 'active';
    }

    private function docNumColumn(Company $company, bool $canView): string
    {
        $docNum = (string) $company->doc_num;

        if ($docNum === '') {
            return '';
        }

        if (! $canView) {
            return sprintf('<span class="dt-code-value" dir="ltr">%s</span>', e($docNum));
        }

        return sprintf(
            '<a class="fw-semibold dt-code-value" dir="ltr" href="%s">%s</a>',
            e(route('admin.companies.show', $docNum)),
            e($docNum),
        );
    }

    /**
     * @return array{text: list<string>, dates: list<string>, date_text: list<string>}
     */
    private function searchColumns(): array
    {
        return [
            'text' => [
                'companies.doc_num',
                'companies.name',
                'companies.legal_name',
                'companies.commercial_name',
                'companies.status',
                'companies.notes',
                'companies.phone',
                'companies.mobile',
                'companies.hotline',
                'companies.email',
                'companies.city',
                'companies.country',
                'companies.governorate',
                'company_countries.name',
                'company_governorates.name',
                'company_cities.name',
                'company_areas.name',
                'created_users.name',
                'updated_users.name',
            ],
            'dates' => [
                'companies.created_at',
                'companies.updated_at',
                'companies.commercial_register_date',
                'companies.commercial_register_expiry_date',
            ],
            'date_text' => [
                'companies.created_at',
                'companies.updated_at',
                'companies.commercial_register_date',
                'companies.commercial_register_expiry_date',
            ],
        ];
    }
}

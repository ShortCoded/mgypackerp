<?php

namespace Modules\Core\DataTables;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\Reports\BusinessPartnerDataReport;
use Yajra\DataTables\Facades\DataTables;

class BusinessPartnerDataReportDataTable
{
    /**
     * @var array<string, array<string, string>>
     */
    private array $rowCache = [];

    public function __construct(
        protected readonly BusinessPartnerDataReport $report,
        private readonly DataTableSearchService $searchService,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $this->rowCache = [];
        $query = $this->report->listingQuery($this->report->filtersFromRequest($request));
        $table = $this->report->partnerTable();

        $dataTable = DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->searchService->terms(
                    is_string($request->input('search.value')) ? $request->input('search.value') : null,
                );

                if ($terms !== []) {
                    $this->searchService->applyMultiTermSearch($query, $terms, $this->report->searchColumns());
                }
            });

        foreach (BusinessPartnerDataReport::ColumnKeys as $column) {
            $dataTable->editColumn($column, fn (Model $record): string => $this->row($record)[$column] ?? '');
        }

        return $dataTable
            ->orderColumn('doc_num', "{$table}.doc_number $1")
            ->orderColumn('name', "{$table}.name $1")
            ->orderColumn('account_group', 'partner_account_groups.account_code $1')
            ->orderColumn('account', 'partner_accounts.account_code $1')
            ->orderColumn('phone', "{$table}.phone $1")
            ->orderColumn('mobile', "{$table}.mobile $1")
            ->orderColumn('email', "{$table}.email $1")
            ->orderColumn('contact_person', "{$table}.contact_person $1")
            ->orderColumn('address', "{$table}.address $1")
            ->orderColumn('country', "COALESCE(partner_countries.name, {$table}.country) $1")
            ->orderColumn('governorate', "COALESCE(partner_governorates.name, {$table}.governorate) $1")
            ->orderColumn('city', "COALESCE(partner_cities.name, {$table}.city) $1")
            ->orderColumn('area', 'partner_areas.name $1')
            ->orderColumn('tax_number', "{$table}.tax_number $1")
            ->orderColumn('commercial_register', "{$table}.commercial_register $1")
            ->orderColumn('status', "{$table}.status $1")
            ->orderColumn('created_at', "{$table}.created_at $1")
            ->removeColumn(
                'id',
                'company_id',
                'doc_number',
                'legacy_country',
                'legacy_governorate',
                'legacy_city',
                'account_doc_num',
                'account_code',
                'account_name',
                'account_name_en',
                'account_group_doc_num',
                'account_group_code',
                'account_group_name',
                'account_group_name_en',
                'country_name',
                'governorate_name',
                'city_name',
                'area_name',
            )
            ->toJson();
    }

    /**
     * @return array<string, string>
     */
    private function row(Model $record): array
    {
        $key = (string) ($record->getKey() ?? spl_object_id($record));

        return $this->rowCache[$key] ??= $this->report->row($record);
    }
}

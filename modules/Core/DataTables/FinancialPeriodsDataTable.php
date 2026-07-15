<?php

namespace Modules\Core\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;
use Yajra\DataTables\Facades\DataTables;

class FinancialPeriodsDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $searchService,
        private readonly OperatingCompanyContextService $companyContext,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $dateFormat = app(SettingService::class)->dateFormat();
        $searchColumns = $this->searchColumns();
        $trashFilter = $this->trashFilter($request);
        $canView = (bool) $request->user()?->can('financial_periods.view');
        $query = $this->baseQuery($trashFilter)
            ->leftJoin('users as created_users', 'created_users.id', '=', 'financial_periods.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'financial_periods.updated_by')
            ->select([
                'financial_periods.id',
                'financial_periods.doc_number',
                'financial_periods.doc_num',
                'financial_periods.name',
                'financial_periods.from_date',
                'financial_periods.to_date',
                'financial_periods.is_closed',
                'financial_periods.notes',
                'financial_periods.created_at',
                'financial_periods.updated_at',
                'financial_periods.deleted_at',
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
            ->addColumn('checkbox', fn (FinancialPeriod $financialPeriod): string => view('modules.core.financial-periods.partials.checkbox', ['financialPeriod' => $financialPeriod])->render())
            ->editColumn('doc_num', fn (FinancialPeriod $financialPeriod): string => $this->docNumColumn($financialPeriod, $canView))
            ->editColumn('name', fn (FinancialPeriod $financialPeriod): string => $this->ellipsisText($financialPeriod->name === null ? null : (string) $financialPeriod->name))
            ->editColumn('from_date', fn (FinancialPeriod $financialPeriod): string => $this->plainText($financialPeriod->from_date?->format($dateFormat) ?? ''))
            ->editColumn('to_date', fn (FinancialPeriod $financialPeriod): string => $this->plainText($financialPeriod->to_date?->format($dateFormat) ?? ''))
            ->editColumn('is_closed', fn (FinancialPeriod $financialPeriod): string => $this->badge(
                $financialPeriod->is_closed ? __('financial_periods.statuses.closed') : __('financial_periods.statuses.open'),
                $financialPeriod->is_closed ? 'secondary' : 'success',
            ))
            ->addColumn('created_by', fn (FinancialPeriod $financialPeriod): string => $this->ellipsisText($financialPeriod->created_by_name))
            ->editColumn('created_at', fn (FinancialPeriod $financialPeriod): string => $this->plainText($financialPeriod->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('updated_by', fn (FinancialPeriod $financialPeriod): string => $this->ellipsisText($financialPeriod->updated_by_name))
            ->editColumn('updated_at', fn (FinancialPeriod $financialPeriod): string => $this->plainText($financialPeriod->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (FinancialPeriod $financialPeriod): string => view('modules.core.financial-periods.partials.actions', ['financialPeriod' => $financialPeriod])->render())
            ->orderColumn('doc_num', 'financial_periods.doc_number $1')
            ->orderColumn('name', 'financial_periods.name $1')
            ->orderColumn('from_date', 'financial_periods.from_date $1')
            ->orderColumn('to_date', 'financial_periods.to_date $1')
            ->orderColumn('is_closed', 'financial_periods.is_closed $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'financial_periods.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', 'financial_periods.updated_at $1')
            ->removeColumn('id')
            ->rawColumns(['checkbox', 'doc_num', 'name', 'from_date', 'to_date', 'is_closed', 'created_by', 'updated_by', 'actions'])
            ->toJson();
    }

    /**
     * @return Builder<FinancialPeriod>
     */
    private function baseQuery(string $trashFilter): Builder
    {
        $query = match ($trashFilter) {
            'trashed' => FinancialPeriod::onlyTrashed(),
            'all' => FinancialPeriod::withTrashed(),
            default => FinancialPeriod::query(),
        };

        return $this->companyContext->applyCompanyScope($query, 'financial_periods');
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('financial_periods.view_trashed')) {
            return 'active';
        }

        $filter = $request->string('trash_filter')->toString();

        return in_array($filter, ['active', 'trashed', 'all'], true) ? $filter : 'active';
    }

    private function docNumColumn(FinancialPeriod $financialPeriod, bool $canView): string
    {
        $docNum = (string) $financialPeriod->doc_num;

        if ($docNum === '') {
            return '';
        }

        if (! $canView) {
            return sprintf('<span class="dt-code-value" dir="ltr">%s</span>', e($docNum));
        }

        return sprintf(
            '<a class="fw-semibold dt-code-value" dir="ltr" href="%s">%s</a>',
            e(route('admin.financial-periods.show', $docNum)),
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
                'financial_periods.doc_num',
                'financial_periods.name',
                'financial_periods.is_closed',
                'created_users.name',
                'updated_users.name',
            ],
            'dates' => [
                'financial_periods.created_at',
                'financial_periods.updated_at',
                'financial_periods.from_date',
                'financial_periods.to_date',
            ],
            'date_text' => [
                'financial_periods.created_at',
                'financial_periods.updated_at',
                'financial_periods.from_date',
                'financial_periods.to_date',
            ],
        ];
    }

    private function badge(string $label, string $color): string
    {
        return '<span class="badge rounded-pill badge-subtle-'.$color.'">'.e($label).'</span>';
    }
}

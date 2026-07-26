<?php

namespace Modules\Core\DataTables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Models\Currency;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;
use Yajra\DataTables\Facades\DataTables;

class CurrenciesDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly OperatingCompanyContextService $companies,
        private readonly NumericFormatService $numericFormatter,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $canView = (bool) $request->user()?->can('currencies.view');
        $query = match ($this->trashFilter($request)) {
            'trashed' => Currency::onlyTrashed(),
            'all' => Currency::withTrashed(),
            default => Currency::query(),
        };

        $query = $this->companies->applyCompanyScope($query, 'currencies', $request);

        $query->leftJoin('users as created_users', 'created_users.id', '=', 'currencies.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'currencies.updated_by')
            ->select(['currencies.*', 'created_users.name as created_by_name', 'updated_users.name as updated_by_name']);

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->search->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);
                if ($terms !== []) {
                    $this->search->applyMultiTermSearch($query, $terms, ['text' => ['currencies.doc_num', 'currencies.name', 'currencies.code', 'currencies.minor_unit_name', 'currencies.status']]);
                }
            })
            ->addColumn('checkbox', fn (Currency $currency): string => view('modules.core.currencies.partials.checkbox', ['record' => $currency])->render())
            ->editColumn('doc_num', fn (Currency $currency): string => $this->docNumColumn($currency, $canView))
            ->editColumn('name', fn (Currency $currency): string => $this->ellipsisText($currency->name))
            ->editColumn('code', fn (Currency $currency): string => '<span class="dt-code-value" dir="ltr">'.e($currency->code).'</span>')
            ->editColumn('minor_unit_name', fn (Currency $currency): string => $this->ellipsisText($currency->minor_unit_name))
            ->editColumn('minor_unit_factor', fn (Currency $currency): string => '<span class="dt-number-value" dir="ltr">'.e($this->numericFormatter->format($currency->minor_unit_factor)).'</span>')
            ->editColumn('is_main', fn (Currency $currency): string => $this->badge($currency->is_main ? __('common.actions.yes') : __('common.actions.no'), $currency->is_main ? 'success' : 'secondary'))
            ->editColumn('status', fn (Currency $currency): string => $this->badge(__("currencies.statuses.{$currency->status}"), $currency->status === 'active' ? 'success' : 'secondary'))
            ->addColumn('created_by', fn (Currency $currency): string => $this->ellipsisText($currency->created_by_name))
            ->editColumn('created_at', fn (Currency $currency): string => $this->plainText($currency->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('updated_by', fn (Currency $currency): string => $this->ellipsisText($currency->updated_by_name))
            ->editColumn('updated_at', fn (Currency $currency): string => $this->plainText($currency->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (Currency $currency): string => view('modules.core.currencies.partials.actions', ['record' => $currency])->render())
            ->orderColumn('doc_num', 'currencies.doc_number $1')
            ->orderColumn('name', 'currencies.name $1')
            ->orderColumn('code', 'currencies.code $1')
            ->orderColumn('minor_unit_name', 'currencies.minor_unit_name $1')
            ->orderColumn('minor_unit_factor', 'currencies.minor_unit_factor $1')
            ->orderColumn('is_main', 'currencies.is_main $1')
            ->orderColumn('status', 'currencies.status $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'currencies.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', 'currencies.updated_at $1')
            ->removeColumn('id')
            ->rawColumns(['checkbox', 'doc_num', 'name', 'code', 'minor_unit_name', 'minor_unit_factor', 'is_main', 'status', 'created_by', 'updated_by', 'actions'])
            ->toJson();
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('currencies.view_trashed')) {
            return 'active';
        }

        return in_array($request->string('trash_filter')->toString(), ['active', 'trashed', 'all'], true) ? $request->string('trash_filter')->toString() : 'active';
    }

    private function badge(string $label, string $color): string
    {
        return '<span class="badge rounded-pill badge-subtle-'.$color.'">'.e($label).'</span>';
    }

    private function docNumColumn(Currency $currency, bool $canView): string
    {
        if (! $canView) {
            return '<span class="fw-semibold text-700">'.e((string) $currency->doc_num).'</span>';
        }

        return '<a class="fw-semibold dt-code-value" dir="ltr" href="'.e(route('admin.currencies.show', $currency->doc_num)).'">'.e((string) $currency->doc_num).'</a>';
    }
}

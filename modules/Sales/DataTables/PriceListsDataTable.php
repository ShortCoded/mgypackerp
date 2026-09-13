<?php

namespace Modules\Sales\DataTables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;
use Modules\Sales\Models\PriceList;
use Yajra\DataTables\Facades\DataTables;

class PriceListsDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $settings = app(SettingService::class);
        $dateFormat = $settings->dateFormat();
        $dateTimeFormat = $settings->dateTimeFormat();
        $query = $this->baseQuery($request)
            ->leftJoin('customers', 'customers.id', '=', 'price_lists.customer_id')
            ->leftJoin('currencies', 'currencies.id', '=', 'price_lists.currency_id')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'price_lists.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'price_lists.updated_by')
            ->leftJoin('users as deleted_users', 'deleted_users.id', '=', 'price_lists.deleted_by')
            ->select([
                'price_lists.*',
                'customers.doc_num as customer_doc_num',
                'customers.name as customer_name',
                'currencies.code as currency_code',
                'currencies.name as currency_name',
                'created_users.name as created_by_name',
                'updated_users.name as updated_by_name',
                'deleted_users.name as deleted_by_name',
            ])
            ->withCount('lines');
        $canView = (bool) $request->user()?->can('price_lists.view');

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->search->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);

                if ($terms === []) {
                    return;
                }

                $this->search->applyMultiTermSearch($query, $terms, [
                    'text' => [
                        'price_lists.doc_num',
                        'customers.doc_num',
                        'customers.name',
                        'currencies.code',
                        'currencies.name',
                        'created_users.name',
                        'updated_users.name',
                        'deleted_users.name',
                    ],
                    'dates' => [
                        'price_lists.price_list_date',
                        'price_lists.valid_from',
                        'price_lists.valid_until',
                        'price_lists.created_at',
                        'price_lists.updated_at',
                        'price_lists.deleted_at',
                    ],
                    'date_text' => [
                        'price_lists.price_list_date',
                        'price_lists.valid_from',
                        'price_lists.valid_until',
                        'price_lists.created_at',
                        'price_lists.updated_at',
                        'price_lists.deleted_at',
                    ],
                ]);
            })
            ->addColumn('checkbox', fn (PriceList $record): string => view('modules.sales.price-lists.partials.checkbox', ['record' => $record])->render())
            ->editColumn('doc_num', fn (PriceList $record): string => $this->docNumColumn($record, $canView))
            ->addColumn('scope', fn (PriceList $record): string => $this->ellipsisText($this->scopeLabel($record)))
            ->addColumn('currency', fn (PriceList $record): string => $this->ellipsisText(trim(implode(' / ', array_filter([$record->currency_code, $record->currency_name]))) ?: __('common.empty_value')))
            ->editColumn('price_list_date', fn (PriceList $record): string => $this->plainText($record->price_list_date?->format($dateFormat) ?? ''))
            ->editColumn('valid_from', fn (PriceList $record): string => $this->plainText($record->valid_from?->format($dateFormat) ?? ''))
            ->editColumn('valid_until', fn (PriceList $record): string => $this->plainText($record->valid_until?->format($dateFormat) ?? __('price_lists.open_ended')))
            ->editColumn('lines_count', fn (PriceList $record): string => $this->plainText((string) $record->lines_count))
            ->addColumn('created_by', fn (PriceList $record): string => $this->ellipsisText($record->created_by_name ?: __('common.empty_value')))
            ->editColumn('created_at', fn (PriceList $record): string => $this->plainText($record->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('updated_by', fn (PriceList $record): string => $this->ellipsisText($record->updated_by_name ?: __('common.empty_value')))
            ->editColumn('updated_at', fn (PriceList $record): string => $this->plainText($record->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('deleted_by', fn (PriceList $record): string => $this->ellipsisText($record->deleted_by_name ?: __('common.empty_value')))
            ->editColumn('deleted_at', fn (PriceList $record): string => $this->plainText($record->deleted_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (PriceList $record): string => view('modules.sales.price-lists.partials.actions', ['record' => $record])->render())
            ->orderColumn('doc_num', 'price_lists.doc_number $1')
            ->orderColumn('scope', 'customers.name $1')
            ->orderColumn('currency', 'currencies.code $1')
            ->orderColumn('price_list_date', 'price_lists.price_list_date $1')
            ->orderColumn('valid_from', 'price_lists.valid_from $1')
            ->orderColumn('valid_until', 'price_lists.valid_until $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'price_lists.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', 'price_lists.updated_at $1')
            ->orderColumn('deleted_by', 'deleted_users.name $1')
            ->orderColumn('deleted_at', 'price_lists.deleted_at $1')
            ->removeColumn('id')
            ->removeColumn('company_id')
            ->removeColumn('customer_id')
            ->removeColumn('currency_id')
            ->removeColumn('doc_number')
            ->removeColumn('notes')
            ->removeColumn('restored_by')
            ->removeColumn('restored_at')
            ->removeColumn('customer_doc_num')
            ->removeColumn('customer_name')
            ->removeColumn('currency_code')
            ->removeColumn('currency_name')
            ->removeColumn('created_by_name')
            ->removeColumn('updated_by_name')
            ->removeColumn('deleted_by_name')
            ->rawColumns(['checkbox', 'doc_num', 'scope', 'currency', 'created_by', 'updated_by', 'deleted_by', 'actions'])
            ->toJson();
    }

    private function baseQuery(Request $request)
    {
        $companyId = $this->companies->currentCompanyId($request);
        $query = match ($this->trashFilter($request)) {
            'trashed' => PriceList::onlyTrashed(),
            'all' => PriceList::withTrashed(),
            default => PriceList::query(),
        };

        return $companyId === null ? $query->whereRaw('1 = 0') : $query->forCompany($companyId);
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('price_lists.view_trashed')) {
            return 'active';
        }

        $filter = $request->string('trash_filter')->trim()->toString();

        return in_array($filter, ['active', 'trashed', 'all'], true) ? $filter : 'active';
    }

    private function docNumColumn(PriceList $record, bool $canView): string
    {
        if (! $canView) {
            return '<span class="fw-semibold dt-code-value" dir="ltr">'.e((string) $record->doc_num).'</span>';
        }

        return '<a class="fw-semibold dt-code-value" dir="ltr" href="'.e(route('admin.sales.price-lists.show', $record->doc_num)).'">'.e((string) $record->doc_num).'</a>';
    }

    private function scopeLabel(PriceList $record): string
    {
        $customer = trim(implode(' / ', array_filter([$record->customer_doc_num, $record->customer_name])));

        return $customer !== '' ? $customer : __('price_lists.general');
    }
}

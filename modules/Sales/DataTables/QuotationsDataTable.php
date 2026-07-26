<?php

namespace Modules\Sales\DataTables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;
use Modules\Sales\Models\Quotation;
use Yajra\DataTables\Facades\DataTables;

class QuotationsDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly OperatingCompanyContextService $companies,
        private readonly NumericFormatService $numbers,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $dateFormat = app(SettingService::class)->dateFormat();
        $query = match ($this->trashFilter($request)) {
            'trashed' => Quotation::onlyTrashed(),
            'all' => Quotation::withTrashed(),
            default => Quotation::query(),
        };

        $query = $this->companies->applyCompanyScope($query, 'quotations', $request);

        $query
            ->leftJoin('customers', 'customers.id', '=', 'quotations.customer_id')
            ->leftJoin('currencies', 'currencies.id', '=', 'quotations.currency_id')
            ->leftJoin('quotation_revisions as current_revisions', 'current_revisions.id', '=', 'quotations.current_revision_id')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'quotations.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'quotations.updated_by')
            ->select([
                'quotations.*',
                'customers.name as customer_name',
                'customers.doc_num as customer_doc_num',
                'currencies.code as currency_code',
                'currencies.name as currency_name',
                'current_revisions.revision_number as current_revision_number',
                'current_revisions.revision_code as current_revision_code',
                'current_revisions.total as current_revision_total',
                'created_users.name as created_by_name',
                'updated_users.name as updated_by_name',
            ]);

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->search->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);
                if ($terms !== []) {
                    $this->search->applyMultiTermSearch($query, $terms, [
                        'text' => [
                            'quotations.doc_num',
                            'quotations.subject',
                            'quotations.project_name',
                            'quotations.quotation_type',
                            'quotations.status',
                            'customers.name',
                            'customers.doc_num',
                            'currencies.code',
                            'currencies.name',
                            'current_revisions.revision_code',
                        ],
                    ]);
                }
            })
            ->addColumn('checkbox', fn (Quotation $record): string => view('modules.sales.quotations.partials.checkbox', ['record' => $record])->render())
            ->editColumn('doc_num', fn (Quotation $record): string => '<a class="fw-semibold dt-code-value" href="'.e(route('admin.sales.quotations.show', $record->doc_num)).'">'.e($record->doc_num).'</a>')
            ->addColumn('customer', fn (Quotation $record): string => $this->ellipsisText($this->customerLabel($record)))
            ->addColumn('subject_project', fn (Quotation $record): string => $this->ellipsisText($record->subject ?: $record->project_name ?: __('common.empty_value')))
            ->editColumn('quotation_type', fn (Quotation $record): string => $this->plainText(__("quotations.types.{$record->quotation_type}")))
            ->addColumn('current_revision', fn (Quotation $record): string => $this->plainText($record->current_revision_code ?: __('common.empty_value')))
            ->editColumn('status', fn (Quotation $record): string => view('modules.sales.quotations.partials.status', ['status' => $record->status])->render())
            ->addColumn('currency', fn (Quotation $record): string => $this->ellipsisText(trim(implode(' / ', array_filter([$record->currency_code, $record->currency_name]))) ?: __('common.empty_value')))
            ->addColumn('total', fn (Quotation $record): string => $this->plainText($this->numbers->format($record->current_revision_total)))
            ->editColumn('quotation_date', fn (Quotation $record): string => $this->plainText($record->quotation_date?->format($dateFormat) ?? ''))
            ->editColumn('valid_until', fn (Quotation $record): string => $this->plainText($record->valid_until?->format($dateFormat) ?? __('common.empty_value')))
            ->editColumn('created_by', fn (Quotation $record): string => $this->ellipsisText($record->created_by_name ?: __('common.empty_value')))
            ->editColumn('updated_by', fn (Quotation $record): string => $this->ellipsisText($record->updated_by_name ?: __('common.empty_value')))
            ->addColumn('actions', fn (Quotation $record): string => view('modules.sales.quotations.partials.actions', ['record' => $record])->render())
            ->addColumn('edit_url', fn (Quotation $record): string => route('admin.sales.quotations.edit', $record->doc_num))
            ->addColumn('can_edit', fn (Quotation $record): bool => ! $record->trashed() && (bool) $request->user()?->can('quotations.edit'))
            ->orderColumn('doc_num', 'quotations.doc_number $1')
            ->orderColumn('customer', 'customers.name $1')
            ->orderColumn('subject_project', 'quotations.subject $1')
            ->orderColumn('quotation_type', 'quotations.quotation_type $1')
            ->orderColumn('current_revision', 'current_revisions.revision_number $1')
            ->orderColumn('status', 'quotations.status $1')
            ->orderColumn('currency', 'currencies.code $1')
            ->orderColumn('total', 'current_revisions.total $1')
            ->orderColumn('quotation_date', 'quotations.quotation_date $1')
            ->orderColumn('valid_until', 'quotations.valid_until $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->removeColumn('id')
            ->removeColumn('company_id')
            ->removeColumn('customer_id')
            ->removeColumn('currency_id')
            ->removeColumn('sales_person_id')
            ->removeColumn('current_revision_id')
            ->removeColumn('deleted_by')
            ->removeColumn('restored_by')
            ->rawColumns(['checkbox', 'doc_num', 'customer', 'subject_project', 'status', 'currency', 'total', 'created_by', 'updated_by', 'actions'])
            ->toJson();
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('quotations.view_trashed')) {
            return 'active';
        }

        return in_array($request->string('trash_filter')->toString(), ['active', 'trashed', 'all'], true)
            ? $request->string('trash_filter')->toString()
            : 'active';
    }

    private function customerLabel(Quotation $record): string
    {
        $label = trim(implode(' / ', array_filter([$record->customer_doc_num, $record->customer_name])));

        return $label !== '' ? $label : __('common.empty_value');
    }
}

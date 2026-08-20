<?php

namespace Modules\Accounting\DataTables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\SettingService;
use Yajra\DataTables\Facades\DataTables;

class JournalEntriesDataTable
{
    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly OperatingContextService $operatingContext,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $context = $this->operatingContext->snapshot($request);
        $query = match (! $request->user()?->can('journal_entries.view_trashed') ? 'active' : $request->string('trash_filter')->toString()) {
            'trashed' => JournalEntry::onlyTrashed(),
            'all' => JournalEntry::withTrashed(),
            default => JournalEntry::query(),
        };
        $query = $query
            ->when(
                $context['company_id'] && $context['financial_period_id'],
                fn ($query) => $query
                    ->where('journal_entries.company_id', $context['company_id'])
                    ->where('journal_entries.financial_period_id', $context['financial_period_id']),
                fn ($query) => $query->whereRaw('1 = 0'),
            )
            ->leftJoin('currencies', 'currencies.id', '=', 'journal_entries.currency_id')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'journal_entries.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'journal_entries.updated_by')
            ->select([
                'journal_entries.*',
                'currencies.code as currency_code',
                'created_users.name as created_by_name',
                'updated_users.name as updated_by_name',
            ])
            ->selectSub(function ($query): void {
                $query->from('journal_entry_lines')
                    ->selectRaw('COALESCE(SUM(debit_amount), 0)')
                    ->whereColumn('journal_entry_lines.journal_entry_id', 'journal_entries.id');
            }, 'total_debit')
            ->selectSub(function ($query): void {
                $query->from('journal_entry_lines')
                    ->selectRaw('COALESCE(SUM(credit_amount), 0)')
                    ->whereColumn('journal_entry_lines.journal_entry_id', 'journal_entries.id');
            }, 'total_credit');
        $dateFormat = app(SettingService::class)->dateFormat();
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->search->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);
                if ($terms !== []) {
                    $this->search->applyMultiTermSearch($query, $terms, ['text' => [
                        'journal_entries.doc_num', 'journal_entries.reference_no', 'journal_entries.description',
                        'journal_entries.source_type', 'journal_entries.source_doc_num', 'journal_entries.status',
                        'currencies.code', 'created_users.name', 'updated_users.name',
                    ]]);
                }
            })
            ->addColumn('checkbox', fn (JournalEntry $entry): string => $entry->status === JournalEntry::StatusDraft && ! $entry->is_system_generated && ! $entry->trashed()
                ? '<input class="form-check-input js-row-checkbox" type="checkbox" value="'.e($entry->doc_num).'">'
                : '')
            ->editColumn('doc_num', function (JournalEntry $entry) use ($request): string {
                $route = $entry->trashed() ? 'admin.accounting.journal-entries.trashed.show' : 'admin.accounting.journal-entries.show';
                $canView = ! $entry->trashed() || (bool) $request->user()?->can('journal_entries.view_trashed');

                return $canView
                    ? '<a class="fw-semibold dt-code-value" dir="ltr" href="'.e(route($route, $entry->doc_num)).'">'.e($entry->doc_num).'</a>'
                    : e($entry->doc_num);
            })
            ->editColumn('entry_date', fn (JournalEntry $entry): string => e($entry->entry_date?->format($dateFormat) ?? ''))
            ->editColumn('status', fn (JournalEntry $entry): string => '<span class="badge rounded-pill badge-subtle-'.$this->statusColor($entry).'">'.e(__("journal_entries.statuses.{$entry->status}")).'</span>')
            ->editColumn('is_system_generated', fn (JournalEntry $entry): string => e($entry->is_system_generated ? __('journal_entries.system_generated') : __('journal_entries.manual')))
            ->editColumn('total_debit', fn (JournalEntry $entry): string => '<span class="dt-number-value" dir="ltr">'.e(app(NumericFormatService::class)->format($entry->getAttribute('total_debit'))).'</span>')
            ->editColumn('total_credit', fn (JournalEntry $entry): string => '<span class="dt-number-value" dir="ltr">'.e(app(NumericFormatService::class)->format($entry->getAttribute('total_credit'))).'</span>')
            ->addColumn('created_by', fn (JournalEntry $entry): string => e((string) $entry->getAttribute('created_by_name')))
            ->editColumn('created_at', fn (JournalEntry $entry): string => e($entry->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('updated_by', fn (JournalEntry $entry): string => e((string) $entry->getAttribute('updated_by_name')))
            ->editColumn('updated_at', fn (JournalEntry $entry): string => e($entry->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (JournalEntry $entry): string => view('modules.accounting.journal-entries.partials.actions', compact('entry'))->render())
            ->orderColumn('doc_num', 'journal_entries.doc_number $1')
            ->orderColumn('entry_date', 'journal_entries.entry_date $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->rawColumns(['checkbox', 'doc_num', 'status', 'total_debit', 'total_credit', 'actions'])
            ->toJson();
    }

    private function statusColor(JournalEntry $entry): string
    {
        if ($entry->trashed()) {
            return 'danger';
        }

        return match ($entry->status) {
            JournalEntry::StatusPosted => 'success',
            JournalEntry::StatusCancelled => 'warning',
            JournalEntry::StatusReversed => 'secondary',
            default => 'info',
        };
    }
}

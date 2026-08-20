<?php

namespace Modules\Accounting\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\DataTables\JournalEntriesDataTable;
use Modules\Accounting\Http\Requests\StoreJournalEntryRequest;
use Modules\Accounting\Http\Requests\UpdateJournalEntryRequest;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\AccountSelect2Service;
use Modules\Accounting\Services\CostCenterSelect2Service;
use Modules\Accounting\Services\ManualJournalEntryService;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingContextService;
use Modules\HR\Services\HrSelect2Service;
use Modules\Purchases\Services\PurchasesSelect2Service;
use Modules\Sales\Services\SalesSelect2Service;

class JournalEntryController extends Controller
{
    public function __construct(
        private readonly ManualJournalEntryService $service,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly OperatingContextService $operatingContext,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function index(): View
    {
        return view('modules.accounting.journal-entries.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.accounting.journal-entries.index'),
        ]);
    }

    public function data(Request $request, JournalEntriesDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(): View
    {
        return $this->form('create');
    }

    public function show(Request $request, string $journalEntry): View
    {
        return $this->form('view', $this->findActive($request, $journalEntry));
    }

    public function showTrashed(Request $request, string $journalEntry): View
    {
        return $this->form('view', $this->findTrashed($request, $journalEntry));
    }

    public function edit(Request $request, string $journalEntry): View
    {
        $record = $this->findActive($request, $journalEntry);
        $this->guardDomain(function () use ($record): null {
            $record->assertManuallyEditable();

            return null;
        });

        return $this->form('edit', $record);
    }

    public function store(StoreJournalEntryRequest $request): JsonResponse
    {
        $record = $this->guardDomain(fn (): JournalEntry => $this->service->create($request->validated()));
        $this->log($request, 'journal_entries.create', $record);

        return response()->json([
            'success' => true,
            'message' => __('journal_entries.messages.created'),
            'redirect' => route('admin.accounting.journal-entries.edit', $record->doc_num),
            'data' => ['doc_num' => $record->doc_num, 'urls' => $this->urls($record)],
        ]);
    }

    public function update(UpdateJournalEntryRequest $request, string $journalEntry): JsonResponse
    {
        $record = $this->findActive($request, $journalEntry);
        $result = $this->guardDomain(fn (): array => $this->service->update($record, $request->validated()));

        if (! $result['changed']) {
            return response()->json(['success' => false, 'type' => 'no_changes', 'message' => __('common.messages.no_changes')]);
        }

        $this->log($request, 'journal_entries.update', $result['record']);

        return response()->json([
            'success' => true,
            'message' => __('journal_entries.messages.updated'),
            'data' => ['doc_num' => $result['record']->doc_num, 'urls' => $this->urls($result['record'])],
        ]);
    }

    public function post(Request $request, string $journalEntry): JsonResponse
    {
        $record = $this->guardDomain(fn (): JournalEntry => $this->service->post($this->findActive($request, $journalEntry)));
        $this->log($request, 'journal_entries.post', $record);

        return response()->json([
            'success' => true,
            'message' => __('journal_entries.messages.posted'),
            'redirect' => route('admin.accounting.journal-entries.show', $record->doc_num),
            'data' => ['doc_num' => $record->doc_num, 'urls' => $this->urls($record)],
        ]);
    }

    public function destroy(Request $request, string $journalEntry): JsonResponse
    {
        $record = $this->findActive($request, $journalEntry);
        $this->guardDomain(function () use ($record): null {
            $this->service->delete($record);

            return null;
        });
        $this->log($request, 'journal_entries.delete', $record);

        return response()->json(['success' => true, 'message' => __('journal_entries.messages.deleted')]);
    }

    public function restore(Request $request, string $journalEntry): JsonResponse
    {
        $record = $this->guardDomain(fn (): JournalEntry => $this->service->restore($this->findTrashed($request, $journalEntry)));
        $this->log($request, 'journal_entries.restore', $record);

        return response()->json(['success' => true, 'message' => __('journal_entries.messages.restored')]);
    }

    public function accounts(Request $request, AccountSelect2Service $select2): JsonResponse
    {
        $this->authorizeSelect2($request);
        $request->merge(['postable' => true]);

        return response()->json($select2->accounts($request));
    }

    public function costCenters(Request $request, CostCenterSelect2Service $select2): JsonResponse
    {
        $this->authorizeSelect2($request);
        $request->merge(['postable' => true]);

        return response()->json($select2->costCenters($request));
    }

    public function customers(Request $request, SalesSelect2Service $select2): JsonResponse
    {
        $this->authorizeSelect2($request);

        return response()->json($select2->customers($request));
    }

    public function suppliers(Request $request, PurchasesSelect2Service $select2): JsonResponse
    {
        $this->authorizeSelect2($request);

        return response()->json($select2->suppliers($request));
    }

    public function employees(Request $request, HrSelect2Service $select2): JsonResponse
    {
        $this->authorizeSelect2($request);

        return response()->json($select2->employees($request));
    }

    private function form(string $mode, ?JournalEntry $record = null): View
    {
        $record?->loadMissing(['financialPeriod', 'branch', 'currency', 'lines.account', 'lines.costCenter', 'lines.customer', 'lines.supplier', 'lines.employee', 'createdBy', 'updatedBy', 'postedBy']);
        $isCreate = $mode === 'create';

        return view('modules.accounting.journal-entries.form', [
            'mode' => $mode,
            'record' => $record,
            'action' => $isCreate ? route('admin.accounting.journal-entries.store') : route('admin.accounting.journal-entries.update', $record?->doc_num),
            'method' => $isCreate ? 'POST' : 'PUT',
            'operatingContext' => $this->operatingContext->current(request()),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.accounting.journal-entries.index', [
                ['label' => $isCreate ? __('journal_entries.create') : (string) $record?->doc_num, 'active' => true],
            ]),
        ]);
    }

    private function findActive(Request $request, string $docNum): JournalEntry
    {
        return $this->contextQuery($request)->where('doc_num', $docNum)->firstOrFail();
    }

    private function findTrashed(Request $request, string $docNum): JournalEntry
    {
        return $this->contextQuery($request, trashed: true)->where('doc_num', $docNum)->firstOrFail();
    }

    private function contextQuery(Request $request, bool $trashed = false): Builder
    {
        $context = $this->operatingContext->snapshot($request);
        $query = $trashed ? JournalEntry::onlyTrashed() : JournalEntry::query();

        return $query
            ->when($context['company_id'], fn ($query, $companyId) => $query->where('company_id', $companyId), fn ($query) => $query->whereRaw('1 = 0'))
            ->when($context['financial_period_id'], fn ($query, $periodId) => $query->where('financial_period_id', $periodId), fn ($query) => $query->whereRaw('1 = 0'));
    }

    /**
     * @return array<string, string>
     */
    private function urls(JournalEntry $record): array
    {
        return [
            'show' => route('admin.accounting.journal-entries.show', $record->doc_num),
            'edit' => route('admin.accounting.journal-entries.edit', $record->doc_num),
            'update' => route('admin.accounting.journal-entries.update', $record->doc_num),
            'post' => route('admin.accounting.journal-entries.post', $record->doc_num),
            'destroy' => route('admin.accounting.journal-entries.destroy', $record->doc_num),
            'index' => route('admin.accounting.journal-entries.index'),
        ];
    }

    private function authorizeSelect2(Request $request): void
    {
        abort_unless(
            (bool) $request->user()?->can('journal_entries.view')
            || (bool) $request->user()?->can('journal_entries.create')
            || (bool) $request->user()?->can('journal_entries.edit')
            || (bool) $request->user()?->can('reports.account_ledger.view')
            || (bool) $request->user()?->can('reports.customer_statement.view')
            || (bool) $request->user()?->can('reports.supplier_statement.view'),
            403,
        );
    }

    private function log(Request $request, string $action, JournalEntry $record): void
    {
        $this->activityLogger->log($request, 'accounting', $action, 'success', [
            'subject' => $record,
            'company_id' => $record->company_id,
            'properties_only' => true,
            'properties' => [
                'doc_num' => $record->doc_num,
                'status' => $record->status,
                'financial_period_id' => $record->financial_period_id,
            ],
        ]);
    }

    private function guardDomain(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['document' => $exception->getMessage()]);
        }
    }
}

<?php

namespace Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Currency;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Production\DataTables\ProductionExecutionDataTable;
use Modules\Production\Http\Requests\StoreProductionExpenseRequest;
use Modules\Production\Models\ProductionExpenseRequest;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionExpenseRequestService;

class ProductionExpenseRequestController extends Controller
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly ProductionExpenseRequestService $service,
        private readonly CompanyPrintIdentityService $printIdentity,
        private readonly ReportPdfService $pdf,
    ) {}

    public function index(Request $request, ProductionExecutionDataTable $dataTable): View|JsonResponse
    {
        $this->requiredContext($request);

        if ($request->expectsJson()) {
            return $dataTable->expenses($request);
        }

        return view('modules.production.expenses.index');
    }

    public function create(Request $request): View
    {
        return $this->form($request);
    }

    public function show(Request $request, ProductionExpenseRequest $productionExpenseRequest): View
    {
        $this->assertProductionExpense($request, $productionExpenseRequest);

        return $this->form($request, $productionExpenseRequest, 'view');
    }

    public function edit(Request $request, ProductionExpenseRequest $productionExpenseRequest): View
    {
        $this->assertProductionExpense($request, $productionExpenseRequest);
        abort_unless($productionExpenseRequest->isEditable(), 422, __('production_execution.messages.expense_submitted_edit_only'));

        return $this->form($request, $productionExpenseRequest, 'edit');
    }

    public function clone(Request $request, ProductionExpenseRequest $productionExpenseRequest): View
    {
        $this->assertProductionExpense($request, $productionExpenseRequest);

        return $this->form($request, $productionExpenseRequest, 'clone');
    }

    public function store(StoreProductionExpenseRequest $request): RedirectResponse
    {
        $data = $request->safe()->except('submit_action');
        $record = $this->guard(fn (): ProductionExpenseRequest => $this->service->create(
            ProductionRun::query()->findOrFail($data['production_run_id']),
            $data,
        ));

        return redirect()->to($this->submitRedirectUrl($request, $record))
            ->with('success', __('production_execution.messages.expense_created'));
    }

    public function update(StoreProductionExpenseRequest $request, ProductionExpenseRequest $productionExpenseRequest): RedirectResponse
    {
        $this->assertProductionExpense($request, $productionExpenseRequest);
        $record = $this->guard(fn (): ProductionExpenseRequest => $this->service->update(
            $productionExpenseRequest,
            $request->safe()->except('submit_action'),
        ));

        return redirect()->to($this->submitRedirectUrl($request, $record))
            ->with('success', __('production_execution.messages.expense_updated'));
    }

    public function destroy(Request $request, ProductionExpenseRequest $productionExpenseRequest): JsonResponse|RedirectResponse
    {
        $this->assertProductionExpense($request, $productionExpenseRequest);
        $this->guard(fn () => $this->service->delete($productionExpenseRequest));

        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => __('production_execution.messages.expense_deleted')])
            : to_route('admin.production.expenses.index')->with('success', __('production_execution.messages.expense_deleted'));
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $context = $this->requiredContext($request);
        $docNums = $request->validate([
            'doc_nums' => ['required', 'array', 'min:1', 'max:100'],
            'doc_nums.*' => ['required', 'string', 'distinct'],
        ])['doc_nums'];

        DB::transaction(function () use ($context, $docNums): void {
            $records = ProductionExpenseRequest::query()
                ->forContext($context['company_id'], $context['financial_period_id'], $context['branch_id'])
                ->whereNotNull('production_run_id')
                ->whereIn('doc_num', $docNums)
                ->lockForUpdate()
                ->get();

            foreach ($records as $record) {
                $this->service->delete($record);
            }
        });

        return response()->json(['success' => true, 'message' => __('production_execution.messages.expenses_bulk_deleted')]);
    }

    public function restore(Request $request, string $productionExpenseRequest): JsonResponse|RedirectResponse
    {
        $context = $this->requiredContext($request);
        $record = ProductionExpenseRequest::onlyTrashed()
            ->forContext($context['company_id'], $context['financial_period_id'], $context['branch_id'])
            ->whereNotNull('production_run_id')
            ->where('doc_num', $productionExpenseRequest)
            ->firstOrFail();
        $record = $this->guard(fn (): ProductionExpenseRequest => $this->service->restore($record));

        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => __('production_execution.messages.expense_restored')])
            : to_route('admin.production.expenses.show', $record)->with('success', __('production_execution.messages.expense_restored'));
    }

    public function print(Request $request, ProductionExpenseRequest $productionExpenseRequest): Response
    {
        $this->assertProductionExpense($request, $productionExpenseRequest);
        $record = $productionExpenseRequest->load([
            'run.order.company', 'run.product', 'run.stageSnapshot', 'currency', 'cashbox', 'bankAccount',
            'expenseAccount', 'cashVoucher',
        ]);

        return $this->pdf->stream('reports.production.expense-request', [
            'title' => __('production_execution.print.expense_request').' — '.$record->doc_num,
            'record' => $record,
            'companyPrintIdentity' => $record->run->order->print_identity_snapshot ?: $this->printIdentity->forCompany($record->run->order->company),
        ], str('production-expense-request-'.$record->doc_num)->slug().'.pdf');
    }

    public function approve(Request $request, ProductionExpenseRequest $productionExpenseRequest): JsonResponse
    {
        $this->assertProductionExpense($request, $productionExpenseRequest);

        return $this->jsonGuard(fn () => $this->service->approve($productionExpenseRequest));
    }

    public function pay(Request $request, ProductionExpenseRequest $productionExpenseRequest): JsonResponse
    {
        $this->assertProductionExpense($request, $productionExpenseRequest);

        return $this->jsonGuard(fn () => $this->service->pay($productionExpenseRequest));
    }

    public function reverse(Request $request, ProductionExpenseRequest $productionExpenseRequest): JsonResponse
    {
        $this->assertProductionExpense($request, $productionExpenseRequest);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->jsonGuard(fn () => $this->service->reverse($productionExpenseRequest, $data['reason']));
    }

    private function form(Request $request, ?ProductionExpenseRequest $record = null, string $mode = 'create'): View
    {
        $context = $this->requiredContext($request);
        $companyId = $context['company_id'];
        $branchId = $context['branch_id'];
        $record?->loadMissing(['run.stageSnapshot', 'currency', 'cashbox', 'bankAccount', 'expenseAccount']);
        $runs = ProductionRun::query()
            ->where('company_id', $companyId)
            ->where('financial_period_id', $context['financial_period_id'])
            ->where('branch_id', $branchId)
            ->when($mode !== 'view', fn ($query) => $query->whereNotIn('status', [ProductionRun::StatusCompleted, ProductionRun::StatusCancelled]))
            ->with('stageSnapshot')
            ->latest()
            ->get();

        if ($record?->run && ! $runs->contains('id', $record->production_run_id)) {
            $runs->prepend($record->run);
        }

        return view('modules.production.expenses.form', [
            'record' => $record,
            'mode' => $mode,
            'runs' => $runs,
            'currencies' => Currency::query()->forCompany($companyId)->active()->get(),
            'cashboxes' => Cashbox::query()->forCompany($companyId)->where('branch_id', $branchId)->active()->get(),
            'bankAccounts' => BankAccount::query()->forCompany($companyId)->active()->get(),
            'expenseAccounts' => Account::query()->forCompany($companyId)->active()->where('account_type', Account::TypeExpense)->where('is_postable', true)->get(),
        ]);
    }

    /** @return array{company_id: int, financial_period_id: int, branch_id: int} */
    private function requiredContext(Request $request): array
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 422, __('production_execution.messages.operating_context_required'));

        return [
            'company_id' => (int) $context['company_id'],
            'financial_period_id' => (int) $context['financial_period_id'],
            'branch_id' => (int) $context['branch_id'],
        ];
    }

    private function assertProductionExpense(Request $request, ProductionExpenseRequest $record): void
    {
        $context = $this->requiredContext($request);
        abort_unless(
            (int) $record->company_id === $context['company_id']
            && (int) $record->financial_period_id === $context['financial_period_id']
            && (int) $record->branch_id === $context['branch_id']
            && $record->production_run_id !== null,
            404,
        );
    }

    private function guard(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['expense' => $exception->getMessage()]);
        }
    }

    private function jsonGuard(callable $callback): JsonResponse
    {
        try {
            $record = $callback();

            return response()->json(['success' => true, 'status' => $record->status, 'message' => __('production_execution.messages.operation_completed')]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    private function submitRedirectUrl(Request $request, ProductionExpenseRequest $record): string
    {
        return match ($request->string('submit_action')->trim()->toString() ?: 'save') {
            'save_view' => route('admin.production.expenses.show', $record),
            'save_back' => route('admin.production.expenses.index'),
            'save_clone' => route('admin.production.expenses.clone', $record),
            'save', 'save_edit' => $request->user()?->can('production.expenses.edit') && $record->isEditable()
                ? route('admin.production.expenses.edit', $record)
                : route('admin.production.expenses.show', $record),
            default => route('admin.production.expenses.show', $record),
        };
    }
}

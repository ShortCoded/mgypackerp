<?php

namespace Modules\Accounting\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Models\OverheadAllocationRule;
use Modules\Accounting\Models\OverheadAllocationRun;
use Modules\Accounting\Services\OverheadAllocationService;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingContextService;

final class OverheadAllocationController extends Controller
{
    public function __construct(
        private readonly OverheadAllocationService $allocations,
        private readonly OperatingContextService $context,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function rules(Request $request): View
    {
        $context = $this->requiredContext($request);

        return view('modules.accounting.overhead-allocations.rules', [
            'rules' => OverheadAllocationRule::query()
                ->forCompany($context['company_id'])
                ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $context['branch_id']))
                ->with(['sourceCostCenter', 'branch'])
                ->latest('id')
                ->paginate(25),
            'accounts' => Account::query()->forCompany($context['company_id'])->eligibleForDirectPosting()
                ->whereHas('costCenters', fn ($query) => $query->where('cost_centers.status', 'active'))
                ->with('costCenters:id,cost_center_code,name')
                ->ordered()
                ->get(),
            'costCenters' => CostCenter::query()->forCompany($context['company_id'])->active()->where('is_group', false)->ordered()->get(),
            'period' => FinancialPeriod::query()->forCompany($context['company_id'])->findOrFail($context['financial_period_id']),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.costing.overhead-allocation-rules.index'),
        ]);
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $context = $this->requiredContext($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'source_cost_center_id' => ['required', 'integer'],
            'source_account_ids' => ['required', 'array', 'min:1'],
            'source_account_ids.*' => ['required', 'integer', 'distinct'],
            'target_cost_center_ids' => ['nullable', 'array'],
            'target_cost_center_ids.*' => ['required', 'integer', 'distinct'],
            'basis' => ['required', Rule::in([
                OverheadAllocationRule::BasisMachineHours,
                OverheadAllocationRule::BasisLaborHours,
                OverheadAllocationRule::BasisDirectMaterialCost,
            ])],
            'fallback_basis' => ['nullable', Rule::in([OverheadAllocationRule::BasisDirectMaterialCost])],
            'cost_behavior' => ['required', Rule::in([OverheadAllocationRule::BehaviorVariable, OverheadAllocationRule::BehaviorFixed])],
            'normal_capacity_hours' => ['nullable', 'numeric', 'gt:0'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->allocations->createRule($data, $context['company_id'], $context['branch_id']);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['allocation' => $exception->getMessage()]);
        }

        return back()->with('success', __('overhead_allocations.messages.rule_created'));
    }

    public function runs(Request $request): View
    {
        $context = $this->requiredContext($request);
        $runs = OverheadAllocationRun::query()
            ->forContext($context['company_id'], $context['financial_period_id'], $context['branch_id'])
            ->with(['rule.sourceCostCenter', 'journalEntry'])
            ->latest('id')
            ->paginate(25);
        $selectedRun = null;

        if ($request->filled('run')) {
            $selectedRun = OverheadAllocationRun::query()
                ->forContext($context['company_id'], $context['financial_period_id'], $context['branch_id'])
                ->where('public_id', $request->string('run')->toString())
                ->with([
                    'rule.sourceCostCenter',
                    'sources.account',
                    'sources.journalEntryLine.journalEntry',
                    'lines.productionRun.product',
                    'lines.productionRun.order',
                    'journalEntry',
                    'reversalJournalEntry',
                ])->firstOrFail();
        }

        return view('modules.accounting.overhead-allocations.runs', [
            'runs' => $runs,
            'selectedRun' => $selectedRun,
            'rules' => OverheadAllocationRule::query()
                ->forCompany($context['company_id'])
                ->where('status', 'active')
                ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $context['branch_id']))
                ->orderBy('name')
                ->get(),
            'period' => FinancialPeriod::query()->forCompany($context['company_id'])->findOrFail($context['financial_period_id']),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.costing.overhead-allocation-run.index'),
        ]);
    }

    public function preview(Request $request): RedirectResponse
    {
        $context = $this->requiredContext($request);
        $data = $request->validate([
            'rule_public_id' => ['required', 'uuid'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
        ]);
        $rule = OverheadAllocationRule::query()->forCompany($context['company_id'])
            ->where('public_id', $data['rule_public_id'])->firstOrFail();
        $period = FinancialPeriod::query()->forCompany($context['company_id'])->findOrFail($context['financial_period_id']);

        try {
            $run = $this->allocations->preview($rule, $period, $context['branch_id'], $data['from_date'], $data['to_date']);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['allocation' => $exception->getMessage()]);
        }

        return redirect()->route('admin.costing.overhead-allocation-run.index', ['run' => $run->public_id])
            ->with('success', __('overhead_allocations.messages.preview_created'));
    }

    public function approve(Request $request, string $allocationRun): RedirectResponse
    {
        $run = $this->scopedRun($request, $allocationRun);

        try {
            $run = $this->allocations->approve($run);
        } catch (DomainException $exception) {
            return back()->withErrors(['allocation' => $exception->getMessage()]);
        }

        return redirect()->route('admin.costing.overhead-allocation-run.index', ['run' => $run->public_id])
            ->with('success', __('overhead_allocations.messages.run_posted'));
    }

    public function reverse(Request $request, string $allocationRun): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $run = $this->scopedRun($request, $allocationRun);

        try {
            $run = $this->allocations->reverse($run, $data['reason']);
        } catch (DomainException $exception) {
            return back()->withErrors(['allocation' => $exception->getMessage()]);
        }

        return redirect()->route('admin.costing.overhead-allocation-run.index', ['run' => $run->public_id])
            ->with('success', __('overhead_allocations.messages.run_reversed'));
    }

    /** @return array{company_id: int, branch_id: int, financial_period_id: int} */
    private function requiredContext(Request $request): array
    {
        $snapshot = $this->context->snapshot($request);
        abort_unless($snapshot['company_id'] && $snapshot['branch_id'] && $snapshot['financial_period_id'], 422, __('overhead_allocations.messages.context_required'));

        return [
            'company_id' => (int) $snapshot['company_id'],
            'branch_id' => (int) $snapshot['branch_id'],
            'financial_period_id' => (int) $snapshot['financial_period_id'],
        ];
    }

    private function scopedRun(Request $request, string $publicId): OverheadAllocationRun
    {
        $context = $this->requiredContext($request);

        return OverheadAllocationRun::query()
            ->forContext($context['company_id'], $context['financial_period_id'], $context['branch_id'])
            ->where('public_id', $publicId)
            ->firstOrFail();
    }
}

<?php

namespace Modules\Inventory\Http\Controllers;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Models\BranchStore;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Inventory\Http\Requests\CancelInventoryCostPolicyTransitionRequest;
use Modules\Inventory\Http\Requests\PrepareInventoryCostPolicyTransitionRequest;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryCostPolicyTransition;
use Modules\Inventory\Services\InventoryCostPolicyTransitionService;

class InventoryCostPolicyTransitionController extends Controller
{
    public function __construct(
        private readonly OperatingCompanyContextService $companies,
        private readonly OperatingScopeAccessService $scope,
        private readonly InventoryCostPolicyTransitionService $transitions,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function prepare(PrepareInventoryCostPolicyTransitionRequest $request): RedirectResponse
    {
        $company = $this->companies->currentCompany($request);
        abort_unless($company !== null, 409);
        $data = $request->validated();
        $branch = filled($data['transition_branch_doc_num'] ?? null)
            ? $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])
                ->where('branches.doc_num', $data['transition_branch_doc_num'])->first()
            : null;
        abort_if(filled($data['transition_branch_doc_num'] ?? null) && $branch === null, 403);
        $store = filled($data['transition_branch_store_uuid'] ?? null)
            ? $this->storeQuery($request)->where('branch_stores.public_uuid', $data['transition_branch_store_uuid'])->first()
            : null;
        abort_if(filled($data['transition_branch_store_uuid'] ?? null) && $store === null, 403);
        abort_if($branch !== null && $store !== null && (int) $store->branch_id !== (int) $branch->getKey(), 403);
        abort_if($branch === null && $store === null && ! $this->scope->hasUnrestrictedBranchAccess($request->user()), 403);

        try {
            $transition = $this->transitions->prepare((int) $company->getKey(), [
                'branch_id' => $branch?->getKey(),
                'branch_store_id' => $store?->getKey(),
                'effective_from' => $data['transition_effective_from'],
                'target_method' => $data['transition_target_method'] ?? InventoryCostPolicy::Fifo,
                'reason' => $data['transition_reason'],
            ], (int) $request->user()->getKey());
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['transition' => $exception->getMessage()]);
        }

        $this->log($request, $transition, 'cost_policy.transition_prepared');

        return back()->with('success', __('inventory_cost_policy.transition_messages.prepared'));
    }

    public function approve(Request $request, InventoryCostPolicyTransition $transition): RedirectResponse
    {
        $this->authorizeTransition($request, $transition);
        try {
            $transition = $this->transitions->approve($transition, (int) $request->user()->getKey());
        } catch (DomainException $exception) {
            return back()->withErrors(['transition' => $exception->getMessage()]);
        }
        $this->log($request, $transition, 'cost_policy.transition_approved');

        return back()->with('success', __('inventory_cost_policy.transition_messages.approved'));
    }

    public function activate(Request $request, InventoryCostPolicyTransition $transition): RedirectResponse
    {
        $this->authorizeTransition($request, $transition);
        try {
            $transition = $this->transitions->activate($transition, (int) $request->user()->getKey());
        } catch (DomainException $exception) {
            return back()->withErrors(['transition' => $exception->getMessage()]);
        }
        $this->log($request, $transition, 'cost_policy.transition_activated');

        return back()->with('success', __('inventory_cost_policy.transition_messages.activated'));
    }

    public function cancel(CancelInventoryCostPolicyTransitionRequest $request, InventoryCostPolicyTransition $transition): RedirectResponse
    {
        $this->authorizeTransition($request, $transition);
        try {
            $transition = $this->transitions->cancel(
                $transition,
                (int) $request->user()->getKey(),
                (string) $request->validated('cancellation_reason'),
            );
        } catch (DomainException $exception) {
            return back()->withErrors(['transition' => $exception->getMessage()]);
        }
        $this->log($request, $transition, 'cost_policy.transition_cancelled');

        return back()->with('success', __('inventory_cost_policy.transition_messages.cancelled'));
    }

    private function authorizeTransition(Request $request, InventoryCostPolicyTransition $transition): void
    {
        $company = $this->companies->currentCompany($request);
        abort_unless($company !== null, 409);
        abort_unless((int) $transition->company_id === (int) $company->getKey(), 404);
        if ($transition->branch_id === null) {
            abort_unless($this->scope->hasUnrestrictedBranchAccess($request->user()), 403);

            return;
        }
        abort_unless($this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])
            ->whereKey($transition->branch_id)->exists(), 403);
    }

    private function storeQuery(Request $request): Builder
    {
        $company = $this->companies->currentCompany($request);
        abort_unless($company !== null, 409);
        $branchIds = $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])
            ->pluck('branches.id')->all();

        return BranchStore::query()->whereIn('branch_id', $branchIds !== [] ? $branchIds : [0]);
    }

    private function log(Request $request, InventoryCostPolicyTransition $transition, string $action): void
    {
        $this->activityLogger->log($request, 'inventory', $action, 'success', [
            'subject' => $transition,
            'company_id' => $transition->company_id,
            'properties_only' => true,
            'properties' => $transition->only([
                'scope_key', 'effective_from', 'status', 'input_fingerprint',
                'total_quantity', 'total_book_value', 'inventory_cost_policy_id',
            ]),
        ]);
    }
}

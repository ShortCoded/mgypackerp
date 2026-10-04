<?php

namespace Modules\Inventory\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Core\Services\Select2ResponseService;
use Modules\Inventory\Http\Requests\StoreInventoryCostPolicyRequest;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryCostPolicyTransition;
use Modules\Inventory\Services\InventoryCostPolicyService;

class InventoryCostPolicyController extends Controller
{
    public function __construct(
        private readonly OperatingCompanyContextService $companies,
        private readonly OperatingScopeAccessService $scope,
        private readonly InventoryCostPolicyService $policies,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function index(Request $request): View
    {
        $company = $this->companies->currentCompany($request);
        abort_unless($company !== null, 409);
        $unrestricted = $this->scope->hasUnrestrictedBranchAccess($request->user());
        $branchIds = $unrestricted ? null : $this->scope
            ->allowedBranchQuery($request->user(), [(string) $company->doc_num])
            ->pluck('branches.id')->all();

        return view('modules.inventory.cost-policies.index', [
            'policies' => InventoryCostPolicy::query()
                ->with(['branch:id,name', 'branchStore:id,name'])
                ->where('company_id', $company->getKey())
                ->when(! $unrestricted, fn ($query) => $query->where(fn ($scope) => $scope
                    ->whereNull('branch_id')->orWhereIn('branch_id', $branchIds !== [] ? $branchIds : [0])))
                ->orderByDesc('effective_from')->orderByDesc('id')->paginate(30),
            'transitions' => InventoryCostPolicyTransition::query()
                ->with(['branch:id,name', 'branchStore:id,name', 'preparedBy:id,name', 'approvedBy:id,name'])
                ->withCount('bases')
                ->where('company_id', $company->getKey())
                ->when(! $unrestricted, fn ($query) => $query->whereNotNull('branch_id')
                    ->whereIn('branch_id', $branchIds !== [] ? $branchIds : [0]))
                ->orderByDesc('id')->limit(30)->get(),
            'canCreateCompanyPolicy' => $unrestricted,
            'selectedBranch' => filled(old('branch_doc_num'))
                ? $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])
                    ->where('branches.doc_num', old('branch_doc_num'))->first()
                : null,
            'selectedStore' => filled(old('branch_store_uuid'))
                ? $this->storeQuery($request)->where('branch_stores.public_uuid', old('branch_store_uuid'))->first()
                : null,
            'selectedTransitionBranch' => filled(old('transition_branch_doc_num'))
                ? $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])
                    ->where('branches.doc_num', old('transition_branch_doc_num'))->first()
                : null,
            'selectedTransitionStore' => filled(old('transition_branch_store_uuid'))
                ? $this->storeQuery($request)->where('branch_stores.public_uuid', old('transition_branch_store_uuid'))->first()
                : null,
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.inventory.cost-policies.index'),
        ]);
    }

    public function branches(Request $request, DataTableSearchService $search, Select2ResponseService $select2): JsonResponse
    {
        $company = $this->companies->currentCompany($request);
        abort_unless($company !== null, 409);
        $query = $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num]);
        $terms = $search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $search->applyMultiTermSearch($query, $terms, ['text' => ['branches.name', 'branches.doc_num']]);
        }

        return response()->json($select2->paginated($query, $request, fn (Branch $branch): array => [
            'id' => (string) $branch->doc_num,
            'text' => trim($branch->doc_num.' — '.$branch->name),
        ]));
    }

    public function stores(Request $request, DataTableSearchService $search, Select2ResponseService $select2): JsonResponse
    {
        $query = $this->storeQuery($request);
        if (filled($request->input('branch_doc_num'))) {
            $query->whereHas('branch', fn ($branch) => $branch->where('doc_num', $request->input('branch_doc_num')));
        }
        $terms = $search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $search->applyMultiTermSearch($query, $terms, ['text' => ['branch_stores.name']]);
        }

        return response()->json($select2->paginated($query, $request, fn (BranchStore $store): array => [
            'id' => (string) $store->public_uuid,
            'text' => trim(($store->branch?->name ?? '').' — '.$store->name, ' —'),
        ]));
    }

    public function store(StoreInventoryCostPolicyRequest $request): RedirectResponse
    {
        $company = $this->companies->currentCompany($request);
        abort_unless($company !== null, 409);
        $data = $request->validated();
        $branch = filled($data['branch_doc_num'] ?? null)
            ? $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])
                ->where('branches.doc_num', $data['branch_doc_num'])->first()
            : null;
        abort_if(filled($data['branch_doc_num'] ?? null) && $branch === null, 403);
        $store = filled($data['branch_store_uuid'] ?? null)
            ? $this->storeQuery($request)->where('branch_stores.public_uuid', $data['branch_store_uuid'])->first()
            : null;
        abort_if(filled($data['branch_store_uuid'] ?? null) && $store === null, 403);
        abort_if($branch !== null && $store !== null && (int) $store->branch_id !== (int) $branch->getKey(), 403);
        abort_if($branch === null && $store === null && ! $this->scope->hasUnrestrictedBranchAccess($request->user()), 403);

        try {
            $policy = $this->policies->createVersion((int) $company->getKey(), [
                'branch_id' => $branch?->getKey(),
                'branch_store_id' => $store?->getKey(),
                'method' => $data['method'],
                'effective_from' => $data['effective_from'],
                'reason' => $data['reason'] ?? null,
            ], $request->user()?->getKey());
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['policy' => $exception->getMessage()]);
        }

        $this->activityLogger->log($request, 'inventory', 'cost_policy.created', 'success', [
            'subject' => $policy,
            'company_id' => $policy->company_id,
            'properties_only' => true,
            'properties' => $policy->only(['scope_key', 'method', 'effective_from', 'reason']),
        ]);

        return back()->with('success', __('inventory_cost_policy.created'));
    }

    private function storeQuery(Request $request): Builder
    {
        $company = $this->companies->currentCompany($request);
        abort_unless($company !== null, 409);
        $allowedBranchIds = $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])
            ->pluck('branches.id')->all();

        return BranchStore::query()
            ->with('branch:id,name,doc_num')
            ->whereIn('branch_id', $allowedBranchIds !== [] ? $allowedBranchIds : [0])
            ->orderBy('branch_stores.name');
    }
}

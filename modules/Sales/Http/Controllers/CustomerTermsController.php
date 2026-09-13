<?php

namespace Modules\Sales\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Sales\Http\Requests\UpdateCustomerTermsRequest;
use Modules\Sales\Models\Customer;
use Modules\Sales\Services\CustomerTermsService;

class CustomerTermsController extends Controller
{
    public function __construct(
        private readonly OperatingCompanyContextService $companies,
        private readonly CustomerTermsService $terms,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function index(Request $request): View
    {
        $companyId = $this->companies->requireCompanyId($request);
        $search = trim((string) $request->query('search'));
        $status = in_array($request->query('status'), ['complete', 'partial', 'empty'], true)
            ? (string) $request->query('status')
            : 'all';
        $baseQuery = Customer::query()->forCompany($companyId);
        $totalCustomers = (clone $baseQuery)->count();
        $completeCustomers = (clone $baseQuery)
            ->where(fn (Builder $query) => $this->requireAllTerms($query))
            ->count();
        $partialCustomers = (clone $baseQuery)
            ->where(fn (Builder $query) => $this->requireAnyTerms($query))
            ->where(fn (Builder $query) => $this->requireAnyMissingTerms($query))
            ->count();

        $customers = $baseQuery
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('doc_num', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")))
            ->when($status === 'complete', fn (Builder $query) => $query->where(fn (Builder $query) => $this->requireAllTerms($query)))
            ->when($status === 'partial', fn (Builder $query) => $query
                ->where(fn (Builder $query) => $this->requireAnyTerms($query))
                ->where(fn (Builder $query) => $this->requireAnyMissingTerms($query)))
            ->when($status === 'empty', fn (Builder $query) => $query->where(fn (Builder $query) => $this->requireAllTermsMissing($query)))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('modules.sales.customer-terms.index', [
            'customers' => $customers,
            'search' => $search,
            'status' => $status,
            'statistics' => [
                'total' => $totalCustomers,
                'complete' => $completeCustomers,
                'partial' => $partialCustomers,
                'empty' => max(0, $totalCustomers - $completeCustomers - $partialCustomers),
            ],
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.sales.customer-terms.index'),
        ]);
    }

    public function edit(Request $request, Customer $customer): View
    {
        $this->ensureCustomerBelongsToCompany($request, $customer);

        return view('modules.sales.customer-terms.edit', [
            'customer' => $customer,
            'breadcrumbs' => [
                ...$this->breadcrumbs->forMenuRoute('admin.sales.customer-terms.index'),
                ['label' => $customer->name, 'active' => true],
            ],
        ]);
    }

    public function update(UpdateCustomerTermsRequest $request, Customer $customer): RedirectResponse
    {
        $this->ensureCustomerBelongsToCompany($request, $customer);
        $this->terms->update($customer, $request->validated());

        return redirect()
            ->route('admin.sales.customer-terms.edit', $customer)
            ->with('success', __('customer_terms.messages.updated'));
    }

    private function requireAllTerms(Builder $query): void
    {
        foreach (CustomerTermsService::Fields as $field) {
            $query->whereNotNull($field)->where($field, '<>', '');
        }
    }

    private function requireAnyTerms(Builder $query): void
    {
        foreach (CustomerTermsService::Fields as $index => $field) {
            $method = $index === 0 ? 'where' : 'orWhere';
            $query->{$method}(fn (Builder $fieldQuery) => $fieldQuery->whereNotNull($field)->where($field, '<>', ''));
        }
    }

    private function requireAnyMissingTerms(Builder $query): void
    {
        foreach (CustomerTermsService::Fields as $index => $field) {
            $method = $index === 0 ? 'where' : 'orWhere';
            $query->{$method}(fn (Builder $fieldQuery) => $fieldQuery->whereNull($field)->orWhere($field, ''));
        }
    }

    private function requireAllTermsMissing(Builder $query): void
    {
        foreach (CustomerTermsService::Fields as $field) {
            $query->where(fn (Builder $fieldQuery) => $fieldQuery->whereNull($field)->orWhere($field, ''));
        }
    }

    private function ensureCustomerBelongsToCompany(Request $request, Customer $customer): void
    {
        abort_unless((int) $customer->company_id === $this->companies->requireCompanyId($request), 404);
    }
}

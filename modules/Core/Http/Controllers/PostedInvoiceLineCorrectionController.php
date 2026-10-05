<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Core\Http\Requests\StorePostedInvoiceLineCorrectionRequest;
use Modules\Core\Services\PostedInvoiceLineCorrectionService;

class PostedInvoiceLineCorrectionController extends Controller
{
    public function __construct(private readonly PostedInvoiceLineCorrectionService $service) {}

    public function index(Request $request): View
    {
        try {
            $plan = $this->service->preview((string) $request->route('kind'), (string) $request->route('invoice'));
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return view('modules.core.posted-invoice-line-corrections', $plan);
    }

    public function store(StorePostedInvoiceLineCorrectionRequest $request): JsonResponse|RedirectResponse
    {
        try {
            $proposal = $this->service->prepare((string) $request->route('kind'), (string) $request->route('invoice'), $request->validated());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return $this->response($request, ['proposal_id' => $proposal->id], 'prepared');
    }

    public function approve(Request $request): JsonResponse|RedirectResponse
    {
        $this->service->authorize((string) $request->route('kind'), true);
        $data = $request->validate(['approval_reason' => ['required', 'string', 'max:3000']]);
        try {
            $proposal = $this->service->approve((string) $request->route('kind'), (string) $request->route('invoice'),
                (int) $request->route('correction'), $data['approval_reason']);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }
        $route = $proposal->kind === 'sales' ? 'admin.sales.sales-invoices.show' : 'admin.purchases.purchase-invoices.show';

        return $this->response($request, ['proposal_id' => $proposal->id, 'replacement_invoice_id' => $proposal->replacement_invoice_id,
            'url' => route($route, $proposal->execution_snapshot['replacement']['doc_num'])], 'approved');
    }

    public function reject(Request $request): JsonResponse|RedirectResponse
    {
        try {
            $this->service->reject((string) $request->route('kind'), (string) $request->route('invoice'), (int) $request->route('correction'));
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return $this->response($request, [], 'rejected');
    }

    /** @param array<string,mixed> $data */
    private function response(Request $request, array $data, string $status): JsonResponse|RedirectResponse
    {
        $prefix = $request->route('kind') === 'sales' ? 'admin.sales.sales-invoices' : 'admin.purchases.purchase-invoices';

        return $request->expectsJson() ? response()->json(['data' => $data, 'message' => __('posted_invoice_correction.'.$status)])
            : redirect()->route($prefix.'.line-corrections.index', $request->route('invoice'))->with('success', __('posted_invoice_correction.'.$status));
    }
}

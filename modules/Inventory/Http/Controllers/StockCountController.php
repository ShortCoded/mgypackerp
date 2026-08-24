<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\StockCount;
use Modules\Inventory\Services\StockCountService;

class StockCountController extends Controller
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly StockCountService $service,
    ) {}

    public function index(Request $request): View
    {
        $context = $this->requiredContext($request);

        return view('modules.inventory.stock-counts.index', [
            'records' => StockCount::query()->where('company_id', $context['company_id'])->with('branchStore')->latest('count_date')->paginate(30),
            'stores' => BranchStore::query()->where('branch_id', $context['branch_id'])->orderBy('position')->get(),
            'products' => Product::query()->forCompany($context['company_id'])->active()->nonService()->orderBy('name')->limit(500)->get(),
            'stockStatuses' => [
                InventoryTransaction::StatusAvailable,
                InventoryTransaction::StatusProductionStaging,
                InventoryTransaction::StatusQcHold,
                InventoryTransaction::StatusQuarantine,
                InventoryTransaction::StatusDamaged,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'branch_store_id' => ['required', 'integer', 'exists:branch_stores,id'],
            'warehouse_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
            'count_date' => ['required', 'date'],
            'stock_status' => ['nullable', 'string', 'max:30'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'notes' => ['nullable', 'string'],
        ]);
        $count = $this->guard(fn (): StockCount => $this->service->createSnapshot([...$this->requiredContext($request), ...$data]));

        $url = route('admin.inventory.stock-counts.show', $count);

        return $this->respond($request, ['doc_num' => $count->doc_num, 'url' => $url], $url, 201);
    }

    public function show(StockCount $stockCount): View
    {
        return view('modules.inventory.stock-counts.show', ['record' => $stockCount->load(['lines.product', 'lines.unit', 'branchStore', 'adjustmentDocument'])]);
    }

    public function record(Request $request, StockCount $stockCount): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['required', 'integer', 'exists:inventory_stock_count_lines,id'],
            'lines.*.physical_quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.variance_reason' => ['nullable', 'string', 'max:100'],
            'lines.*.notes' => ['nullable', 'string'],
        ]);
        $values = collect($data['lines'])->mapWithKeys(fn (array $line): array => [$line['line_id'] => $line])->all();
        $record = $this->guard(fn (): StockCount => $this->service->recordCount($stockCount, $values));

        return $this->respond($request, ['doc_num' => $record->doc_num, 'status' => $record->status], route('admin.inventory.stock-counts.show', $record));
    }

    public function approve(Request $request, StockCount $stockCount): JsonResponse|RedirectResponse
    {
        $documents = $this->guard(fn (): array => $this->service->approve($stockCount));

        return $this->respond($request, ['documents' => collect($documents)->pluck('doc_num')->all()], route('admin.inventory.stock-counts.show', $stockCount));
    }

    public function print(StockCount $stockCount): View
    {
        return view('modules.inventory.stock-counts.print', ['record' => $stockCount->load(['lines.product', 'lines.unit', 'branchStore'])]);
    }

    /** @return array{company_id: int, financial_period_id: int, branch_id: int} */
    private function requiredContext(Request $request): array
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 422, 'Operating context is required.');

        return ['company_id' => $context['company_id'], 'financial_period_id' => $context['financial_period_id'], 'branch_id' => $context['branch_id']];
    }

    private function guard(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['stock_count' => $exception->getMessage()]);
        }
    }

    private function respond(Request $request, array $data, string $redirectUrl, int $status = 200): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['data' => $data], $status);
        }

        return redirect()->to($redirectUrl)->with('success', __('Stock count operation completed.'));
    }
}

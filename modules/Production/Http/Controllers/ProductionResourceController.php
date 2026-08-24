<?php

namespace Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;
use Modules\Production\Models\ProductionMachine;
use Modules\Production\Models\ProductionMold;
use Modules\Production\Models\ProductionShift;

class ProductionResourceController extends Controller
{
    public function __construct(private readonly OperatingContextService $context) {}

    public function index(Request $request): View
    {
        $context = $this->requiredContext($request);

        return view('modules.production.resources.index', [
            'machines' => ProductionMachine::query()->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])->with('molds')->orderBy('code')->get(),
            'molds' => ProductionMold::query()->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])->with(['machines', 'products'])->orderBy('code')->get(),
            'shifts' => ProductionShift::query()->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])->orderBy('starts_at')->get(),
            'products' => Product::query()->forCompany($context['company_id'])->where('item_classification', Product::ClassificationFinishedProduct)->active()->orderBy('name')->get(),
        ]);
    }

    public function storeMachine(Request $request): JsonResponse|RedirectResponse
    {
        $context = $this->requiredContext($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:80', Rule::unique('production_machines')->where('company_id', $context['company_id'])],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in([ProductionMachine::StatusAvailable, ProductionMachine::StatusMaintenance, ProductionMachine::StatusUnavailable])],
            'notes' => ['nullable', 'string'],
        ]);
        $machine = ProductionMachine::query()->create([...$data, 'company_id' => $context['company_id'], 'branch_id' => $context['branch_id'], 'created_by' => auth()->id()]);

        return $this->respond($request, ['public_id' => $machine->public_id]);
    }

    public function storeMold(Request $request): JsonResponse|RedirectResponse
    {
        $context = $this->requiredContext($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:80', Rule::unique('production_molds')->where('company_id', $context['company_id'])],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in([ProductionMold::StatusAvailable, ProductionMold::StatusMaintenance, ProductionMold::StatusUnavailable])],
            'machine_ids' => ['required', 'array', 'min:1'],
            'machine_ids.*' => ['integer', Rule::exists('production_machines', 'id')->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])],
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer', Rule::exists('products', 'id')->where('company_id', $context['company_id'])],
            'notes' => ['nullable', 'string'],
        ]);
        $mold = ProductionMold::query()->create([
            ...collect($data)->except(['machine_ids', 'product_ids'])->all(),
            'company_id' => $context['company_id'],
            'branch_id' => $context['branch_id'],
            'created_by' => auth()->id(),
        ]);
        $mold->machines()->sync($data['machine_ids']);
        $mold->products()->sync($data['product_ids']);

        return $this->respond($request, ['public_id' => $mold->public_id]);
    }

    public function storeShift(Request $request): JsonResponse|RedirectResponse
    {
        $context = $this->requiredContext($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:80', Rule::unique('production_shifts')->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])],
            'name' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'different:starts_at'],
        ]);
        $shift = ProductionShift::query()->create([...$data, 'company_id' => $context['company_id'], 'branch_id' => $context['branch_id']]);

        return $this->respond($request, ['public_id' => $shift->public_id]);
    }

    /** @return array<string, mixed> */
    private function requiredContext(Request $request): array
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['branch_id'], 422, 'Operating context is required.');

        return $context;
    }

    private function respond(Request $request, array $data): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['data' => $data], 201);
        }

        return redirect()->route('admin.production.resources.index')->with('success', __('Production resource created.'));
    }
}

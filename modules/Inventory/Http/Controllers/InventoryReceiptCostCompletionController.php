<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\PostingAccountResolver;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Accounting\Models\Account;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Select2ResponseService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReceiptCostProposal;
use Modules\Inventory\Services\InventoryReceiptCostProposalService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryReceiptCostCompletionController extends Controller
{
    public function __construct(private readonly OperatingContextService $context, private readonly InventoryReceiptCostProposalService $proposals) {}

    public function index(Request $request): View
    {
        $scope = $this->scope($request);
        $records = InventoryDocument::query()->where('company_id', $scope['company_id'])->where('branch_id', $scope['branch_id'])
            ->where('document_type', InventoryDocument::TypeReceipt)->where('status', InventoryDocument::StatusPosted)
            ->whereNull('source_document_type')->whereHas('lines', fn ($query) => $query->whereNull('total_cost'))
            ->with(['costProposals' => fn ($query) => $query->latest('revision')])->latest('document_date')->latest('id')->paginate(25);

        return view('modules.inventory.documents.cost-completions', compact('records'));
    }

    public function show(Request $request, InventoryDocument $inventoryDocument): View
    {
        $this->assertDocument($request, $inventoryDocument);
        $record = $inventoryDocument->load(['lines.product', 'costProposals.preparedBy', 'costProposals.approvedBy', 'costProposals.valueAdjustment.journalEntry']);
        foreach ($record->costProposals as $proposal) {
            if ($proposal->impact_snapshot !== null) {
                $this->proposals->assertImpactBranchAccess($request, $proposal->impact_snapshot);
            }
        }
        $selectedCounterpart = Account::query()->forCompany((int) $record->company_id)->eligibleForDirectPosting()->whereKey(old('counterpart_account_id'))->first();

        return view('modules.inventory.documents.cost-completion', compact('record', 'selectedCounterpart'));
    }

    public function accounts(Request $request, Select2ResponseService $select2): JsonResponse
    {
        $scope = $this->scope($request);
        $query = Account::query()->forCompany($scope['company_id'])->eligibleForDirectPosting()
            ->whereHas('classification', fn ($query) => $query->whereIn('code', [PostingAccountResolver::InventoryAdjustmentGain, PostingAccountResolver::InventoryCostCompletionClearing]))
            ->when($request->filled('q'), fn ($query) => $query->where(function ($query) use ($request): void {
                $term = trim((string) $request->input('q'));
                $query->where('account_code', 'like', '%'.$term.'%')->orWhere('name', 'like', '%'.$term.'%')->orWhere('name_en', 'like', '%'.$term.'%');
            }))->ordered();

        return response()->json($select2->paginated($query, $request, fn (Account $account): array => [
            'id' => (string) $account->id, 'text' => $account->codeNameLabel()]));
    }

    public function prepare(Request $request, InventoryDocument $inventoryDocument, NumericFormatService $numbers, DateFormatService $dates): RedirectResponse
    {
        $this->assertDocument($request, $inventoryDocument);
        $request->merge(['posting_date' => $dates->parseDate((string) $request->input('posting_date'))?->toDateString()]);
        if ($request->hasFile('workbook')) {
            $request->request->remove('unit_costs');
        } else {
            try {
                $request->merge(['unit_costs' => collect($request->input('unit_costs', []))->map(fn ($value) => $numbers->normalizeToScale($value, 8))->all()]);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['unit_costs' => __('inventory.movements.messages.receipt_pricing_precision')]);
            }
        }
        $data = $request->validate([
            'posting_date' => ['required', 'date_format:Y-m-d'], 'counterpart_account_id' => ['required', 'integer', 'min:1'],
            'basis' => ['required', Rule::in([InventoryReceiptCostProposal::BasisDocumented, InventoryReceiptCostProposal::BasisEstimate])],
            'source_reference' => ['nullable', 'string', 'min:5', 'max:255'],
            'basis_note' => ['required_if:basis,estimate', 'nullable', 'string', 'min:5', 'max:2000'],
            'workbook' => ['nullable', 'file', 'mimes:xlsx', 'max:10240'],
            'unit_costs' => [$request->hasFile('workbook') ? 'nullable' : 'required', 'array', 'min:1'],
            'unit_costs.*' => ['required', 'numeric', 'gt:0', 'decimal:0,8'],
        ]);
        $this->guard(fn () => $this->proposals->prepare($request, $inventoryDocument, $data,
            $data['unit_costs'] ?? [], $request->file('workbook')));

        return to_route('admin.inventory.cost-completions.show', $inventoryDocument)->with('success', __('inventory.movements.receipt_completion_prepared'));
    }

    public function template(Request $request, InventoryDocument $inventoryDocument): BinaryFileResponse
    {
        $this->assertDocument($request, $inventoryDocument);
        $path = $this->guard(fn () => $this->proposals->template($request, $inventoryDocument, true));

        return response()->download($path, $inventoryDocument->doc_num.'-receipt-costs.xlsx')->deleteFileAfterSend(true);
    }

    public function source(Request $request, InventoryDocument $inventoryDocument, InventoryReceiptCostProposal $proposal): StreamedResponse
    {
        $this->assertDocument($request, $inventoryDocument);
        abort_unless((int) $proposal->inventory_document_id === (int) $inventoryDocument->id
            && (int) $proposal->company_id === (int) $inventoryDocument->company_id && $proposal->source_file_path !== null
            && Storage::disk('local')->exists($proposal->source_file_path), 404);
        abort_unless(hash_file('sha256', Storage::disk('local')->path($proposal->source_file_path)) === $proposal->source_file_sha256, 409);

        return Storage::disk('local')->download($proposal->source_file_path, $proposal->source_file_name ?: 'receipt-cost-source.xlsx');
    }

    public function approve(Request $request, InventoryDocument $inventoryDocument, InventoryReceiptCostProposal $proposal): RedirectResponse
    {
        $this->assertDocument($request, $inventoryDocument);
        $data = $request->validate(['source_reference' => ['required', 'string', 'min:5', 'max:255'],
            'approval_reference' => ['required', 'string', 'min:5', 'max:255']]);
        $this->guard(fn () => $this->proposals->approve($request, $inventoryDocument, $proposal, $data['source_reference'], $data['approval_reference']));

        return to_route('admin.inventory.cost-completions.show', $inventoryDocument)->with('success', __('inventory.movements.receipt_completion_approved'));
    }

    public function reject(Request $request, InventoryDocument $inventoryDocument, InventoryReceiptCostProposal $proposal): RedirectResponse
    {
        $this->assertDocument($request, $inventoryDocument);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $this->guard(fn () => $this->proposals->reject($request, $inventoryDocument, $proposal, $data['reason']));

        return to_route('admin.inventory.cost-completions.show', $inventoryDocument)->with('success', __('inventory.movements.messages.receipt_pricing_rejected'));
    }

    /** @return array{company_id: int, branch_id: int, financial_period_id: int} */
    private function scope(Request $request): array
    {
        abort_unless($request->user()?->canAny(['inventory.documents.propose_receipt_cost', 'inventory.documents.approve_receipt_cost']), 403);
        $scope = $this->context->snapshot($request);
        abort_unless($scope['company_id'] && $scope['branch_id'] && $scope['financial_period_id'], 422);

        return $scope;
    }

    private function assertDocument(Request $request, InventoryDocument $document): void
    {
        $scope = $this->scope($request);
        abort_unless((int) $document->company_id === $scope['company_id'] && (int) $document->branch_id === $scope['branch_id']
            && $document->document_type === InventoryDocument::TypeReceipt, 404);
    }

    private function guard(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['receipt_cost' => $exception->getMessage()]);
        }
    }
}

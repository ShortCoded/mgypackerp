<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\ExcelImportService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryDocumentLine;
use Modules\Inventory\Models\InventoryReceiptCostProposal;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Throwable;

class InventoryReceiptCostProposalService
{
    private const Headers = ['receipt_line_public_id', 'product_code', 'quantity', 'unit_cost'];

    public function __construct(
        private readonly PostedInventoryReceiptPricingService $pricing,
        private readonly InventoryReceiptCostCompletionService $completion,
        private readonly InventoryValueAdjustmentService $adjustments,
        private readonly OperatingContextService $context,
        private readonly ExcelImportService $excelImports,
        private readonly NumericFormatService $numbers,
        private readonly ActivityLogger $activity,
    ) {}

    public function template(Request $request, InventoryDocument $document, bool $completion = false): string
    {
        $this->assertContext($request, $document, $completion);
        $this->assertEligible($document, $completion);
        $lines = $document->lines()->with('product')->orderBy('id')->get();
        if ($lines->count() > (int) config('excel_imports.max_rows')) {
            throw new DomainException(__('inventory.movements.messages.receipt_pricing_lines_changed'));
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Receipt costs');
        foreach (self::Headers as $index => $header) {
            $sheet->setCellValueExplicit([$index + 1, 1], $header, DataType::TYPE_STRING);
            $sheet->getColumnDimension(chr(65 + $index))->setWidth($index === 0 ? 42 : 22);
        }
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        foreach ($lines as $index => $line) {
            $row = $index + 2;
            $sheet->setCellValueExplicit([1, $row], (string) $line->public_id, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([2, $row], (string) $line->product?->doc_num, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([3, $row], (string) $line->quantity, DataType::TYPE_STRING);
            $sheet->getStyle('D'.$row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }

        $path = tempnam(storage_path('app'), 'receipt-cost-template-');
        if ($path === false) {
            throw new DomainException(__('inventory.movements.messages.receipt_pricing_import_invalid'));
        }
        try {
            (new XlsxWriter($spreadsheet))->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return $path;
    }

    /**
     * @param  array{basis: string, source_reference?: ?string, basis_note?: ?string}  $data
     * @param  array<int, string>  $unitCosts
     */
    public function prepare(Request $request, InventoryDocument $document, array $data, array $unitCosts, ?UploadedFile $workbook): InventoryReceiptCostProposal
    {
        $completion = ! empty($data['posting_date']);
        if ($completion) {
            Gate::forUser($request->user())->authorize('inventory.documents.propose_receipt_cost');
        }
        $this->assertContext($request, $document, $completion);
        if ($workbook !== null) {
            $this->excelImports->assertSafeUpload($workbook);
        }

        $storedPath = null;
        try {
            return DB::transaction(function () use ($request, $document, $data, $unitCosts, $workbook, $completion, &$storedPath): InventoryReceiptCostProposal {
                Company::query()->whereKey($document->company_id)->lockForUpdate()->firstOrFail();
                $locked = InventoryDocument::query()->lockForUpdate()->findOrFail($document->getKey());
                $this->assertContext($request, $locked, $completion);
                $this->assertEligible($locked, $completion);
                if ($workbook !== null) {
                    $unitCosts = $this->readWorkbook($workbook, $locked);
                }
                if (InventoryReceiptCostProposal::query()->where('inventory_document_id', $locked->getKey())
                    ->where('status', InventoryReceiptCostProposal::StatusPending)->exists()) {
                    throw new DomainException(__('inventory.movements.messages.receipt_pricing_pending'));
                }
                $lines = $locked->lines()->with('product')->orderBy('id')->lockForUpdate()->get();
                if ($lines->isEmpty() || count($unitCosts) !== $lines->count()
                    || array_diff($lines->modelKeys(), array_keys($unitCosts)) !== []) {
                    throw new DomainException(__('inventory.movements.messages.receipt_pricing_lines_changed'));
                }
                $snapshot = [];
                foreach ($lines as $line) {
                    $cost = (string) $unitCosts[$line->getKey()];
                    if ($line->unit_cost !== null || $line->total_cost !== null
                        || ! preg_match('/^\d{1,11}(?:\.\d{1,8})?$/D', $cost)
                        || bccomp($cost, '0', 8) <= 0) {
                        throw new DomainException(__('inventory.movements.messages.receipt_pricing_lines_changed'));
                    }
                    $snapshot[] = [
                        'line_id' => (int) $line->getKey(),
                        'line_public_id' => (string) $line->public_id,
                        'product_id' => (int) $line->product_id,
                        'product_code' => (string) $line->product?->doc_num,
                        'quantity' => (string) $line->quantity,
                        'unit_cost' => $cost,
                    ];
                }
                $revision = (int) InventoryReceiptCostProposal::query()
                    ->where('inventory_document_id', $locked->getKey())->max('revision') + 1;
                if ($workbook !== null) {
                    $storedPath = Storage::disk('local')->putFileAs('inventory-receipt-cost-sources', $workbook, Str::uuid().'.xlsx');
                    if (! is_string($storedPath)) {
                        throw new DomainException(__('inventory.movements.messages.receipt_pricing_import_invalid'));
                    }
                }
                $sourceChecksum = $storedPath !== null ? hash_file('sha256', Storage::disk('local')->path($storedPath)) : null;
                if ($storedPath !== null && ! is_string($sourceChecksum)) {
                    throw new DomainException(__('inventory.movements.messages.receipt_pricing_import_invalid'));
                }
                $impact = $completion ? $this->completion->plan($locked, $unitCosts, $data['posting_date'],
                    (int) $this->context->snapshot($request)['financial_period_id'], (int) ($data['counterpart_account_id'] ?? 0)) : null;
                if ($impact !== null) {
                    $this->assertImpactBranchAccess($request, $impact);
                }
                $proposal = InventoryReceiptCostProposal::query()->create([
                    'posting_date' => $completion ? $data['posting_date'] : null,
                    'posting_period_id' => $completion ? $this->context->snapshot($request)['financial_period_id'] : null,
                    'counterpart_account_id' => $completion ? $data['counterpart_account_id'] : null,
                    'impact_snapshot' => $impact,
                    'impact_sha256' => $impact === null ? null : hash('sha256', json_encode($impact, JSON_THROW_ON_ERROR)),
                    'inventory_document_id' => $locked->getKey(),
                    'company_id' => $locked->company_id,
                    'financial_period_id' => $locked->financial_period_id,
                    'branch_id' => $locked->branch_id,
                    'revision' => $revision,
                    'status' => InventoryReceiptCostProposal::StatusPending,
                    'basis' => $data['basis'],
                    'source_reference' => trim((string) ($data['source_reference'] ?? '')) ?: null,
                    'basis_note' => trim((string) ($data['basis_note'] ?? '')) ?: null,
                    'line_snapshot' => $snapshot,
                    'source_file_path' => $storedPath,
                    'source_file_name' => $workbook ? substr((string) preg_replace('/[^A-Za-z0-9._-]+/u', '-', $workbook->getClientOriginalName()), 0, 180) : null,
                    'source_file_sha256' => $sourceChecksum,
                    'prepared_by' => $request->user()->getKey(),
                ]);
                $this->activity->log($request, 'inventory', 'receipt_cost_proposal', 'success', [
                    'subject' => $proposal,
                    'properties_only' => true,
                    'properties' => ['document' => $locked->doc_num, 'revision' => $revision, 'basis' => $proposal->basis, 'source_sha256' => $proposal->source_file_sha256],
                ]);

                return $proposal;
            });
        } catch (Throwable $exception) {
            if (is_string($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }
    }

    public function approve(Request $request, InventoryDocument $document, InventoryReceiptCostProposal $proposal, string $sourceReference, string $approvalReference): InventoryDocument
    {
        return DB::transaction(function () use ($request, $document, $proposal, $sourceReference, $approvalReference): InventoryDocument {
            Company::query()->whereKey($document->company_id)->lockForUpdate()->firstOrFail();
            $locked = InventoryDocument::query()->lockForUpdate()->findOrFail($document->getKey());
            $candidate = InventoryReceiptCostProposal::query()->lockForUpdate()->findOrFail($proposal->getKey());
            if ($candidate->posting_date !== null) {
                Gate::forUser($request->user())->authorize('inventory.documents.approve_receipt_cost');
            }
            $this->assertContext($request, $locked, $candidate->posting_date !== null);
            if ((int) $candidate->inventory_document_id !== (int) $locked->getKey()
                || (int) $candidate->company_id !== (int) $locked->company_id
                || (int) $candidate->financial_period_id !== (int) $locked->financial_period_id
                || (int) $candidate->branch_id !== (int) $locked->branch_id
                || $candidate->status !== InventoryReceiptCostProposal::StatusPending
                || (int) $candidate->prepared_by === (int) $request->user()?->getKey()) {
                throw new DomainException(__('inventory.movements.messages.receipt_pricing_approval_unavailable'));
            }
            $this->assertEligible($locked, $candidate->posting_date !== null);
            $sourceReference = trim($sourceReference);
            if ($candidate->source_reference !== null && $candidate->source_reference !== $sourceReference) {
                throw new DomainException(__('inventory.movements.messages.receipt_pricing_source_changed'));
            }
            if ($candidate->source_file_path !== null
                && (! Storage::disk('local')->exists($candidate->source_file_path)
                    || hash_file('sha256', Storage::disk('local')->path($candidate->source_file_path)) !== $candidate->source_file_sha256)) {
                throw new DomainException(__('inventory.movements.messages.receipt_pricing_source_changed'));
            }
            $lines = $locked->lines()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $snapshot = $candidate->line_snapshot;
            if (! is_array($snapshot) || count($snapshot) !== $lines->count()) {
                throw new DomainException(__('inventory.movements.messages.receipt_pricing_lines_changed'));
            }
            $costs = [];
            foreach ($snapshot as $entry) {
                if (! is_array($entry) || ! isset($entry['line_id'], $entry['line_public_id'], $entry['product_id'], $entry['quantity'], $entry['unit_cost'])) {
                    throw new DomainException(__('inventory.movements.messages.receipt_pricing_lines_changed'));
                }
                $line = $lines->get((int) $entry['line_id']);
                if (! $line instanceof InventoryDocumentLine
                    || (string) $line->public_id !== (string) $entry['line_public_id']
                    || (int) $line->product_id !== (int) $entry['product_id']
                    || bccomp((string) $line->quantity, (string) $entry['quantity'], 8) !== 0
                    || isset($costs[$line->getKey()])) {
                    throw new DomainException(__('inventory.movements.messages.receipt_pricing_lines_changed'));
                }
                $cost = (string) $entry['unit_cost'];
                if (! preg_match('/^\d{1,11}(?:\.\d{1,8})?$/D', $cost)
                    || bccomp($cost, '0', 8) <= 0) {
                    throw new DomainException(__('inventory.movements.messages.receipt_pricing_lines_changed'));
                }
                $costs[$line->getKey()] = $cost;
            }

            if ($candidate->posting_date !== null) {
                if ((int) $candidate->posting_period_id !== (int) $this->context->snapshot($request)['financial_period_id']) {
                    throw new DomainException(__('inventory.movements.messages.context_mismatch'));
                }
                $plan = $this->completion->plan($locked, $costs, $candidate->posting_date->toDateString(),
                    (int) $candidate->posting_period_id, (int) $candidate->counterpart_account_id);
                $this->assertImpactBranchAccess($request, $plan);
                if ($candidate->impact_sha256 !== hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR))) {
                    throw new DomainException(__('inventory.movements.messages.receipt_pricing_source_changed'));
                }
                $candidate->forceFill(['approval_reference' => trim($approvalReference)])->save();
                $adjustment = $this->adjustments->postReceiptCompletion($candidate, $plan, $request);
                $this->completion->persistBases($adjustment, $plan);
                $priced = $locked->refresh();
            } else {
                $priced = $this->pricing->price($locked, $costs, $sourceReference, false, $request, $candidate, trim($approvalReference));
            }
            $candidate->forceFill([
                'status' => InventoryReceiptCostProposal::StatusApproved,
                'source_reference' => $candidate->source_reference ?? $sourceReference,
                'approval_reference' => trim($approvalReference),
                'approved_by' => $request->user()->getKey(),
                'approved_at' => now(),
            ])->save();
            $this->activity->log($request, 'inventory', 'receipt_cost_proposal_approved', 'success', [
                'subject' => $candidate,
                'properties_only' => true,
                'properties' => ['document' => $locked->doc_num, 'journal_entry_id' => isset($adjustment) ? $adjustment->journal_entry_id : $priced->journal_entry_id,
                    'value_adjustment_id' => isset($adjustment) ? $adjustment->id : null,
                    'source_reference' => $sourceReference, 'approval_reference' => $approvalReference],
            ]);

            return $priced;
        });
    }

    public function reject(Request $request, InventoryDocument $document, InventoryReceiptCostProposal $proposal, string $reason): InventoryReceiptCostProposal
    {
        return DB::transaction(function () use ($request, $document, $proposal, $reason): InventoryReceiptCostProposal {
            Company::query()->whereKey($document->company_id)->lockForUpdate()->firstOrFail();
            $locked = InventoryDocument::query()->lockForUpdate()->findOrFail($document->getKey());
            $candidate = InventoryReceiptCostProposal::query()->lockForUpdate()->findOrFail($proposal->getKey());
            if ($candidate->posting_date !== null) {
                Gate::forUser($request->user())->authorize('inventory.documents.approve_receipt_cost');
            }
            $this->assertContext($request, $locked, $candidate->posting_date !== null);
            if ((int) $candidate->inventory_document_id !== (int) $locked->getKey()
                || $candidate->status !== InventoryReceiptCostProposal::StatusPending) {
                throw new DomainException(__('inventory.movements.messages.receipt_pricing_approval_unavailable'));
            }
            $candidate->forceFill([
                'status' => InventoryReceiptCostProposal::StatusRejected,
                'rejection_reason' => trim($reason),
                'rejected_by' => $request->user()->getKey(),
                'rejected_at' => now(),
            ])->save();
            $this->activity->log($request, 'inventory', 'receipt_cost_proposal_rejected', 'success', [
                'subject' => $candidate,
                'properties_only' => true,
                'properties' => ['document' => $locked->doc_num, 'reason' => trim($reason)],
            ]);

            return $candidate;
        });
    }

    /** @param array<string, mixed> $plan */
    public function assertImpactBranchAccess(Request $request, array $plan): void
    {
        $currentBranchId = (int) $this->context->snapshot($request)['branch_id'];
        $otherBranchIds = collect($plan['effects'])->pluck('branch_id')->unique()->reject(fn ($id): bool => (int) $id === $currentBranchId)->values()->all();
        if ($otherBranchIds !== [] && $this->context->allowedBranchQueryForCurrentCompany($request)->whereIn('branches.id', $otherBranchIds)->count() !== count($otherBranchIds)) {
            throw new AuthorizationException(__('inventory.movements.messages.receipt_completion_branch_access'));
        }
    }

    private function assertContext(Request $request, InventoryDocument $document, bool $completion = false): void
    {
        $scope = $this->context->snapshot($request);
        if ((int) $document->company_id !== (int) $scope['company_id']
            || (! $completion && (int) $document->financial_period_id !== (int) $scope['financial_period_id'])
            || (int) $document->branch_id !== (int) $scope['branch_id']) {
            throw new DomainException(__('inventory.movements.messages.context_mismatch'));
        }
    }

    private function assertEligible(InventoryDocument $document, bool $completion = false): void
    {
        if ($document->trashed() || $document->status !== InventoryDocument::StatusPosted
            || $document->document_type !== InventoryDocument::TypeReceipt
            || $document->source_document_type !== null || $document->source_document_id !== null
            || $document->source_doc_num !== null || $document->production_order_id !== null
            || $document->production_run_id !== null || $document->production_run_batch_id !== null
            || $document->journal_entry_id !== null || $document->reversal_journal_entry_id !== null) {
            throw new DomainException(__('inventory.movements.messages.receipt_pricing_unavailable'));
        }
        $period = FinancialPeriod::query()->find($document->financial_period_id);
        if (! $period || (! $completion && $period->is_closed)) {
            throw new DomainException(__('inventory.movements.messages.receipt_pricing_period_closed'));
        }
        $store = BranchStore::query()->with('branch')->find($document->branch_store_id);
        if (! $store || (int) $store->branch_id !== (int) $document->branch_id
            || (int) $store->branch?->company_id !== (int) $document->company_id) {
            throw new DomainException(__('inventory.movements.messages.context_mismatch'));
        }
    }

    /** @return array<int, string> */
    private function readWorkbook(UploadedFile $workbook, InventoryDocument $document): array
    {
        $reader = new XlsxReader;
        $reader->setReadDataOnly(false);
        try {
            $spreadsheet = $reader->load($workbook->getRealPath());
        } catch (Throwable) {
            throw new DomainException(__('inventory.movements.messages.receipt_pricing_import_invalid'));
        }
        try {
            $sheet = $spreadsheet->getSheet(0);
            $lastRow = $sheet->getHighestDataRow();
            if ($spreadsheet->getSheetCount() !== 1 || $lastRow > (int) config('excel_imports.max_rows') + 1
                || $sheet->getHighestDataColumn() !== 'D') {
                throw new DomainException(__('inventory.movements.messages.receipt_pricing_import_invalid'));
            }
            foreach (self::Headers as $index => $header) {
                if (trim((string) $sheet->getCell([$index + 1, 1])->getValue()) !== $header) {
                    throw new DomainException(__('inventory.movements.messages.receipt_pricing_import_invalid'));
                }
            }
            $lines = $document->lines()->with('product')->get()->keyBy('public_id');
            $costs = [];
            for ($row = 2; $row <= $lastRow; $row++) {
                $cells = [];
                for ($column = 1; $column <= 4; $column++) {
                    $cell = $sheet->getCell([$column, $row]);
                    if ($cell->isFormula()) {
                        throw new DomainException(__('inventory.movements.messages.receipt_pricing_import_row_invalid', ['row' => $row]));
                    }
                    $value = $cell->getValue();
                    if ($column === 4 && (is_int($value) || is_float($value))) {
                        try {
                            $value = $this->numbers->normalizeScientificNotation((string) $value);
                        } catch (\InvalidArgumentException) {
                            throw new DomainException(__('inventory.movements.messages.receipt_pricing_import_row_invalid', ['row' => $row]));
                        }
                        if (strlen(ltrim(str_replace('.', '', (string) $value), '0')) > 15) {
                            throw new DomainException(__('inventory.movements.messages.receipt_pricing_import_row_invalid', ['row' => $row]));
                        }
                    }
                    $cells[] = trim((string) $value);
                }
                if (implode('', $cells) === '') {
                    continue;
                }
                [$lineId, $productCode, $quantity, $cost] = $cells;
                $line = $lines->get($lineId);
                if (! $line instanceof InventoryDocumentLine
                    || isset($costs[$line->getKey()])
                    || $productCode !== (string) $line->product?->doc_num
                    || ! preg_match('/^\d{1,11}(?:\.\d{1,8})?$/D', $quantity)
                    || bccomp($quantity, (string) $line->quantity, 8) !== 0
                    || ! preg_match('/^\d{1,11}(?:\.\d{1,8})?$/D', $cost)
                    || bccomp($cost, '0', 8) <= 0) {
                    throw new DomainException(__('inventory.movements.messages.receipt_pricing_import_row_invalid', ['row' => $row]));
                }
                $costs[$line->getKey()] = $cost;
            }
            if (count($costs) !== $lines->count()) {
                throw new DomainException(__('inventory.movements.messages.receipt_pricing_import_invalid'));
            }

            return $costs;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }
}

<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Currency;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\Quotation;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Models\SalesRequestLine;

class SalesRequestService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly FinancialPeriodService $periods,
        private readonly SalesUnitConversionService $units,
        private readonly SalesCycleAuditService $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function save(array $data, ?SalesRequest $request = null): SalesRequest
    {
        return DB::transaction(function () use ($data, $request): SalesRequest {
            $record = $request ? SalesRequest::query()->lockForUpdate()->findOrFail($request->id) : new SalesRequest;
            if ($record->exists && ! in_array($record->status, ['draft', 'rejected'], true)) {
                throw new DomainException(__('Only draft or rejected sales requests can be edited.'));
            }
            $companyId = $record->company_id ?? (int) $data['company_id'];
            if ($record->exists) {
                $this->periods->resolveOpenForPostingDate($companyId, $record->request_date, (int) $record->financial_period_id, lockForUpdate: true);
            }
            $period = $this->periods->resolveOpenForPostingDate($companyId, $data['request_date'], lockForUpdate: true);
            $values = collect($data)->only(['branch_id', 'branch_store_id', 'customer_id', 'currency_id', 'business_employee_id', 'request_date', 'required_delivery_date', 'priority', 'customer_reference', 'exchange_rate', 'notes'])->all();
            if (! empty($values['customer_id'])) {
                Customer::query()->forCompany($companyId)->active()->findOrFail($values['customer_id']);
            }
            if (! empty($values['currency_id'])) {
                Currency::query()->where('company_id', $companyId)->findOrFail($values['currency_id']);
            }
            if (! empty($values['branch_store_id'])) {
                BranchStore::query()->where('branch_id', $values['branch_id'])->findOrFail($values['branch_store_id']);
            }
            if (empty($data['lines'])) {
                throw new DomainException(__('A sales request requires at least one line.'));
            }
            $lines = [];
            foreach ($data['lines'] as $index => $input) {
                $product = Product::query()->forCompany($companyId)->active()->findOrFail($input['product_id']);
                if (! $product->isSalesEligible() || bccomp((string) $input['quantity'], '0', 8) <= 0) {
                    throw new DomainException(__('Choose a saleable item and a positive requested quantity.'));
                }
                $lines[] = [...collect($input)->only(['product_id', 'description', 'quantity', 'unit_price', 'specifications', 'notes'])->all(),
                    ...collect($this->units->snapshot($product, $input['unit_id'] ?? null, $input['quantity']))->except('base_unit_id')->all(), 'line_number' => $index + 1];
            }
            $record->fill([...$values, 'company_id' => $companyId, 'financial_period_id' => $period->id]);
            $existingLines = $record->exists ? $record->lines()->get() : collect();
            $sameLines = $existingLines->count() === count($lines) && $existingLines->values()->every(function (SalesRequestLine $line, int $index) use ($lines): bool {
                return ! (clone $line)->fill($lines[$index])->isDirty();
            });
            if ($record->exists && ! $record->isDirty() && $sameLines) {
                return $record->load('lines.product', 'lines.unit');
            }
            if (! $record->exists) {
                $record->fill([...$this->documents->nextForCompany('sales_requests', SalesRequest::class, $companyId), 'created_by' => auth()->id()]);
            } else {
                $record->updated_by = auth()->id();
            }
            $record->save();
            if (! $sameLines) {
                $record->lines()->delete();
                $record->lines()->createMany($lines);
            }
            $this->audit->record($record, 'sales_request.saved');

            return $record->refresh()->load('lines.product', 'lines.unit');
        });
    }

    public function delete(SalesRequest $request): void
    {
        DB::transaction(function () use ($request): void {
            $record = SalesRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($record->status !== 'draft' || $record->quotations()->withTrashed()->exists() || $record->orders()->withTrashed()->exists()) {
                throw new DomainException(__('Only unused drafts can be deleted.'));
            }
            $record->delete();
            $this->audit->record($record, 'sales_request.deleted');
        });
    }

    public function restore(SalesRequest $request): SalesRequest
    {
        return DB::transaction(function () use ($request): SalesRequest {
            $record = SalesRequest::onlyTrashed()->lockForUpdate()->findOrFail($request->id);
            if ($record->status !== 'draft') {
                throw new DomainException(__('Only unused drafts can be restored.'));
            }
            $record->restore();
            $this->audit->record($record, 'sales_request.restored');

            return $record;
        });
    }

    public function transition(SalesRequest $request, string $status, ?string $reason = null): SalesRequest
    {
        return DB::transaction(function () use ($request, $status, $reason): SalesRequest {
            $record = SalesRequest::query()->lockForUpdate()->findOrFail($request->id);
            $this->periods->resolveOpenForPostingDate((int) $record->company_id, $record->request_date, (int) $record->financial_period_id, lockForUpdate: true);
            $allowed = ['submitted' => ['draft', 'rejected'], 'approved' => ['submitted'], 'rejected' => ['submitted'], 'cancelled' => ['draft', 'submitted', 'approved', 'rejected'], 'closed' => ['approved', 'partially_converted', 'converted']];
            if (! in_array($record->status, $allowed[$status] ?? [], true)) {
                throw new DomainException(__('This sales request status transition is not allowed.'));
            }
            if (in_array($status, ['rejected', 'cancelled', 'closed'], true) && blank($reason)) {
                throw new DomainException(__('A reason is required for this action.'));
            }
            $record->update(['status' => $status, $status.'_by' => auth()->id(), $status.'_at' => now(), 'status_reason' => $reason,
                'status_history' => [...($record->status_history ?? []), ['from' => $record->status, 'to' => $status, 'at' => now()->toIso8601String(), 'by' => auth()->id(), 'reason' => $reason]]]);
            $this->audit->record($record, 'sales_request.'.$status);

            return $record->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function convertToOrder(SalesRequest $request, array $data): SalesOrder
    {
        return DB::transaction(function () use ($request, $data): SalesOrder {
            $record = SalesRequest::query()->with(['lines.product', 'lines.unit'])->lockForUpdate()->findOrFail($request->getKey());
            if (! in_array($record->status, ['approved', 'partially_converted'], true) || ! $record->customer_id || ! $record->currency_id) {
                throw new DomainException(__('Conversion requires an approved request with a customer and currency.'));
            }

            $preparedLines = [];
            foreach ($data['lines'] ?? [] as $input) {
                $sourceLine = $record->lines->firstWhere('public_id', $input['source_request_line_public_id'] ?? null);
                if (! $sourceLine
                    || (int) $sourceLine->product_id !== (int) ($input['product_id'] ?? 0)
                    || (int) $sourceLine->unit_id !== (int) ($input['unit_id'] ?? 0)) {
                    throw new DomainException(__('Each order line must keep its selected sales request product and unit.'));
                }

                $quantity = (string) ($input['quantity'] ?? '0');
                if (bccomp($quantity, '0', 8) <= 0 || bccomp($quantity, $sourceLine->remainingQuantity(), 8) > 0) {
                    throw new DomainException(__('Converted quantity exceeds the remaining request quantity.'));
                }
                $preparedLines[] = [
                    ...collect($input)->except('source_request_line_public_id')->all(),
                    'sales_request_line_id' => $sourceLine->getKey(),
                ];
            }
            if ($preparedLines === []) {
                throw new DomainException(__('Select at least one sales request line.'));
            }

            $order = app(SalesOrderService::class)->create([
                ...collect($data)->except(['source_request_doc_num', 'lines', 'customer_id', 'currency_id', 'branch_store_id', 'business_employee_id'])->all(),
                'customer_id' => $record->customer_id,
                'currency_id' => $record->currency_id,
                'exchange_rate' => $record->exchange_rate,
                'branch_store_id' => null,
                'business_employee_id' => $record->business_employee_id ?: ($data['business_employee_id'] ?? null),
                'sales_request_id' => $record->getKey(),
                'lines' => $preparedLines,
            ]);

            foreach ($preparedLines as $line) {
                SalesRequestLine::query()->lockForUpdate()->findOrFail($line['sales_request_line_id'])->increment('converted_quantity', $line['quantity']);
            }
            $record->update(['status' => $record->lines()->whereColumn('converted_quantity', '<', 'quantity')->exists() ? 'partially_converted' : 'converted']);
            $this->audit->record($record, 'sales_request.converted', ['target' => 'order', 'document' => $order->doc_num]);

            return $order;
        });
    }

    /** @param array<string, mixed> $data */
    public function convertToQuotation(SalesRequest $request, array $data): Quotation
    {
        return DB::transaction(function () use ($request, $data): Quotation {
            $record = SalesRequest::query()->with(['customer', 'currency', 'lines.product', 'lines.unit'])->lockForUpdate()->findOrFail($request->getKey());
            if (! in_array($record->status, ['approved', 'partially_converted'], true) || ! $record->customer_id || ! $record->currency_id) {
                throw new DomainException(__('Conversion requires an approved request with a customer and currency.'));
            }

            $preparedLines = [];
            $sourceLineIds = [];
            foreach ($data['lines'] ?? [] as $input) {
                $sourceLine = $record->lines->firstWhere('public_id', $input['source_request_line_public_id'] ?? null);
                if (! $sourceLine || in_array($sourceLine->getKey(), $sourceLineIds, true)
                    || $sourceLine->product?->doc_num !== ($input['product_doc_num'] ?? null)
                    || $sourceLine->unit?->doc_num !== ($input['unit_doc_num'] ?? null)) {
                    throw new DomainException(__('Each quotation line must keep its selected sales request product and unit.'));
                }

                $quantity = (string) ($input['quantity'] ?? '0');
                if (bccomp($quantity, '0', 8) <= 0 || bccomp($quantity, $sourceLine->remainingQuantity(), 8) > 0) {
                    throw new DomainException(__('Converted quantity exceeds the remaining request quantity.'));
                }
                $sourceLineIds[] = $sourceLine->getKey();
                $preparedLines[] = [
                    ...collect($input)->except('source_request_line_public_id')->all(),
                    'sales_request_line_id' => $sourceLine->getKey(),
                ];
            }
            if ($preparedLines === []) {
                throw new DomainException(__('Select at least one sales request line.'));
            }

            $quotation = app(QuotationService::class)->create([
                ...collect($data)->except(['source_request_doc_num', 'sales_person_doc_num', 'customer_doc_num', 'currency_doc_num', 'lines'])->all(),
                'sales_request_id' => $record->getKey(),
                'business_employee_id' => $record->business_employee_id,
                'customer_doc_num' => $record->customer->doc_num,
                'currency_doc_num' => $record->currency->doc_num,
                'exchange_rate' => $record->exchange_rate,
                'lines' => $preparedLines,
            ])['record'];

            foreach ($preparedLines as $line) {
                SalesRequestLine::query()->lockForUpdate()->findOrFail($line['sales_request_line_id'])->increment('converted_quantity', $line['quantity']);
            }
            $record->update(['status' => $record->lines()->whereColumn('converted_quantity', '<', 'quantity')->exists() ? 'partially_converted' : 'converted']);
            $this->audit->record($record, 'sales_request.converted', ['target' => 'quotation', 'document' => $quotation->doc_num]);

            return $quotation;
        });
    }

    /** @param list<array{public_id: string, quantity: string}> $selection */
    public function convert(SalesRequest $request, string $target, array $selection, array $conversionContext = []): Quotation|SalesOrder
    {
        return DB::transaction(function () use ($request, $target, $selection, $conversionContext): Quotation|SalesOrder {
            $record = SalesRequest::query()->with(['customer', 'currency', 'lines.product', 'lines.unit'])->lockForUpdate()->findOrFail($request->id);
            if ($record->status === 'approved') {
                if (! $record->customer_id && ! empty($conversionContext['customer_doc_num'])) {
                    $record->customer_id = Customer::query()->forCompany($record->company_id)->active()->where('doc_num', $conversionContext['customer_doc_num'])->valueOrFail('id');
                }
                if (! $record->currency_id && ! empty($conversionContext['currency_doc_num'])) {
                    $record->currency_id = Currency::query()->forCompany($record->company_id)->active()->where('doc_num', $conversionContext['currency_doc_num'])->valueOrFail('id');
                    $record->exchange_rate = $conversionContext['exchange_rate'] ?? '1';
                }
                if (! $record->branch_store_id && ! empty($conversionContext['branch_store_uuid'])) {
                    $record->branch_store_id = BranchStore::query()->where('branch_id', $record->branch_id)->where('public_uuid', $conversionContext['branch_store_uuid'])->valueOrFail('id');
                }
                if ($record->isDirty()) {
                    $record->updated_by = auth()->id();
                    $record->save();
                    $record->load('customer', 'currency');
                    $this->audit->record($record, 'sales_request.customer_assigned_for_conversion');
                }
            }
            if (! in_array($record->status, ['approved', 'partially_converted'], true) || ! $record->customer_id || ! $record->currency_id) {
                throw new DomainException(__('Conversion requires an approved request with a customer and currency.'));
            }
            if ($selection === [] || count(array_unique(array_column($selection, 'public_id'))) !== count($selection)) {
                throw new DomainException(__('Select each source line once.'));
            }
            $lines = [];
            foreach ($selection as $input) {
                $line = $record->lines->firstWhere('public_id', $input['public_id']);
                if (! $line || bccomp((string) $input['quantity'], '0', 8) <= 0 || bccomp((string) $input['quantity'], $line->remainingQuantity(), 8) > 0) {
                    throw new DomainException(__('Converted quantity exceeds the remaining request quantity.'));
                }
                $lines[] = ['sales_request_line_id' => $line->id, 'product_id' => $line->product_id, 'product_doc_num' => $line->product->doc_num,
                    'unit_id' => $line->unit_id, 'unit_doc_num' => $line->unit->doc_num, 'description' => $line->description ?: $line->product->name,
                    'quantity' => $input['quantity'], 'unit_price' => $line->unit_price ?? '0', 'specifications' => $line->specifications, 'notes' => $line->notes];
                $line->increment('converted_quantity', $input['quantity']);
            }
            $period = $this->periods->resolveOpenForPostingDate((int) $record->company_id, now()->toDateString(), lockForUpdate: true);
            if ($target === 'quotation') {
                $document = app(QuotationService::class)->create(['branch_id' => $record->branch_id, 'sales_request_id' => $record->id, 'business_employee_id' => $record->business_employee_id,
                    'customer_doc_num' => $record->customer->doc_num, 'currency_doc_num' => $record->currency->doc_num,
                    'quotation_date' => now()->toDateString(), 'valid_until' => now()->addMonth()->toDateString(), 'exchange_rate' => $record->exchange_rate,
                    'customer_reference' => $record->customer_reference, 'notes' => $record->notes, 'lines' => $lines])['record'];
            } elseif ($target === 'order') {
                $document = app(SalesOrderService::class)->create(['company_id' => $record->company_id, 'financial_period_id' => $period->id,
                    'branch_id' => $record->branch_id, 'branch_store_id' => $record->branch_store_id, 'customer_id' => $record->customer_id,
                    'sales_request_id' => $record->id, 'business_employee_id' => $record->business_employee_id, 'currency_id' => $record->currency_id,
                    'order_date' => now()->toDateString(), 'expected_delivery_date' => $record->required_delivery_date?->toDateString() ?? now()->toDateString(),
                    'customer_reference' => $record->customer_reference, 'exchange_rate' => $record->exchange_rate, 'notes' => $record->notes,
                    'lines' => array_map(fn (array $line): array => collect($line)->except(['product_doc_num', 'unit_doc_num', 'notes'])->all(), $lines)]);
            } else {
                throw new DomainException(__('Choose quotation or sales order as the conversion target.'));
            }
            $record->update(['status' => $record->lines()->whereColumn('converted_quantity', '<', 'quantity')->exists() ? 'partially_converted' : 'converted']);
            $this->audit->record($record, 'sales_request.converted', ['target' => $target, 'document' => $document->doc_num]);

            return $document;
        });
    }
}

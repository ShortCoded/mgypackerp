@extends('layouts.app')

@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $title = __(str($kind)->replace('_', ' ')->title()->toString());
    $sourceSalesOrder = $record->relationLoaded('salesOrder') ? $record->salesOrder : null;
    $sourceOrder = $record->relationLoaded('order') ? $record->order : null;
    $salesEmployee = ($record->relationLoaded('salesEmployee') ? $record->salesEmployee : null)
        ?? $sourceSalesOrder?->salesEmployee
        ?? $sourceOrder?->salesEmployee;
    $lines = $record->relationLoaded('lines') ? $record->lines : collect();
    $printRoutes = [
        'sales_order' => 'admin.sales.sales-orders.print', 'invoice' => 'admin.sales.sales-invoices.print',
        'credit_note' => 'admin.sales.sales-invoices.print', 'customer_receipt' => 'admin.sales.customer-receipts.print',
        'sales_return' => 'admin.sales.sales-returns.print', 'sales_delivery' => 'admin.sales.delivery-notes.print',
        'production_request' => request()->routeIs('admin.production.*') ? 'admin.production.work-orders.print' : 'admin.sales.production-requests.print',
        'payment_schedule' => 'admin.sales.sales-invoices.payment-schedule.print',
        'quality_disposition' => 'admin.sales.sales-returns.quality-disposition.print',
    ];
    $indexRoutes = [
        'sales_order' => 'admin.sales.sales-orders.index', 'invoice' => 'admin.sales.sales-invoices.index',
        'credit_note' => 'admin.sales.sales-invoices.index', 'customer_receipt' => 'admin.sales.customer-receipts.index',
        'sales_return' => 'admin.sales.sales-returns.index', 'sales_delivery' => 'admin.sales.delivery-notes.index',
        'production_request' => 'admin.sales.sales-orders.index', 'payment_schedule' => 'admin.sales.sales-invoices.index',
        'quality_disposition' => 'admin.sales.sales-returns.index',
    ];
    $editRoute = match (true) {
        $kind === 'sales_order' && $record->isEditable() => ['admin.sales.sales-orders.edit', 'sales_orders.edit'],
        $kind === 'invoice' && $record->isEditable() => ['admin.sales.sales-invoices.edit', 'customer_invoices.edit'],
        default => null,
    };
    $visibleLineSpecifications = fn ($line) => collect($line->specifications ?? [])
        ->when($kind !== 'production_request', fn ($values) => $values->except(['packaging', 'packing', 'units_per_package']))
        ->filter();
    $quotationReference = fn ($quotation, $revision) => trim(implode(' · ', array_filter([
        $quotation?->doc_num,
        $revision?->revision_number ? sprintf('R%02d', $revision->revision_number) : null,
    ])));
    $lineItemLabel = function ($line): string {
        $parts = collect([$line->product?->doc_num, $line->product?->name, $line->description])
            ->filter(fn ($value) => filled($value))
            ->unique(fn ($value) => mb_strtolower(trim((string) $value)));

        return $parts->join(' / ');
    };
    $qualityDispositionLabel = static fn (?string $value): string => collect(explode(',', (string) $value))
        ->filter()
        ->map(fn (string $bucket): string => __(str($bucket)->replace('_', ' ')->title()->toString()))
        ->join(app()->isLocale('ar') ? '، ' : ', ');
    $hasInvoiceLineage = in_array($kind, ['invoice', 'credit_note'], true) && (
        $record->order
        || $record->originalInvoice
        || $record->deliveries->isNotEmpty()
        || $record->allocations->isNotEmpty()
        || $record->returns->isNotEmpty()
        || $record->creditNotes->isNotEmpty()
        || $record->journalEntry
        || $record->reversalJournalEntry
    );
@endphp

@section('title', $title.' '.$record->doc_num)

@section('content')
@if($kind === 'sales_order' && $record->salesRequest) @can('sales_requests.view')<div class="alert alert-info"><a href="{{ route('admin.sales.customer-requests.show', $record->salesRequest) }}">{{ __('Source Sales Request') }}: {{ $record->salesRequest->doc_num }}</a></div>@endcan @endif
    <div class="alert alert-danger d-none js-sales-form-alert"></div>
    <div class="card mb-3">
        <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div><h5 class="mb-1">{{ $title }}</h5><div class="text-600">{{ $record->doc_num }}</div></div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                @include('modules.sales.quotations.partials.status', ['status' => $record->status])
                <a class="btn btn-falcon-default btn-sm" href="{{ route($indexRoutes[$kind]) }}" data-shortcut-action="form.back">{{ __('Back') }}</a>
                @if($editRoute) @can($editRoute[1])<a class="btn btn-primary btn-sm" href="{{ route($editRoute[0], $record) }}" data-shortcut-action="form.edit">{{ __('Edit') }}</a>@endcan @endif
                <a class="btn btn-falcon-primary btn-sm" href="{{ route($printRoutes[$kind], $record) }}" target="_blank">{{ __('Print') }}</a>
                @if(in_array($kind, ['invoice', 'credit_note']))<a class="btn btn-falcon-default btn-sm" href="{{ route($printRoutes[$kind], [$record, 'copy' => 'legal']) }}" target="_blank">{{ __('Legal copy') }}</a>@endif
                @if($kind === 'sales_return' && in_array($record->status, ['received', 'inspected', 'closed'], true))<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.sales-returns.quality-disposition.print', $record) }}" target="_blank">{{ __('Print Quality Disposition') }}</a>@endif
            </div>
        </div>
        <div class="card-body py-2">
            <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-5 g-2">
                @if($record->customer ?? null)<div class="col"><strong>{{ __('Customer') }}</strong><div>{{ $record->customer->doc_num }} / {{ $record->customer->name }}</div></div>@endif
                @if($record->expected_delivery_date ?? null)<div class="col"><strong>{{ __('Required date') }}</strong><div>{{ $dates->formatDate($record->expected_delivery_date, '') }}</div></div>@endif
                @if($salesEmployee)<div class="col"><strong>{{ __('Sales representative') }}</strong><div>{{ $salesEmployee->doc_num }} / {{ $salesEmployee->full_name ?: $salesEmployee->name }}</div></div>@endif
                @if($record->quotation ?? null)<div class="col"><strong>{{ __('Source Quotation') }}</strong><div>@can('quotations.view')<a href="{{ route('admin.sales.quotations.show', $record->quotation) }}">{{ $quotationReference($record->quotation, $record->quotationRevision) }}</a>@else{{ $quotationReference($record->quotation, $record->quotationRevision) }}@endcan</div></div>@endif
                @if($sourceSalesOrder ?? $sourceOrder)<div class="col"><strong>{{ __('Sales Order') }}</strong><div>{{ ($sourceSalesOrder ?? $sourceOrder)->doc_num }}</div></div>@endif
                @if($kind === 'sales_delivery' && !($sourceSalesOrder ?? $sourceOrder) && filled($record->source_doc_num))<div class="col"><strong>{{ __('Sales Invoice') }}</strong><div>{{ $record->source_doc_num }}</div></div>@endif
                @if($record->invoice ?? null)<div class="col"><strong>{{ __('Original Invoice') }}</strong><div>{{ $record->invoice->doc_num }}</div></div>@endif
                @if($record->relationLoaded('customerInvoices') && $record->customerInvoices->isNotEmpty())<div class="col"><strong>{{ __('Sales Invoice') }}</strong><div>@foreach($record->customerInvoices as $invoice)<a class="d-block" href="{{ route('admin.sales.sales-invoices.show', $invoice) }}">{{ $invoice->doc_num }}</a>@endforeach</div></div>@endif
                @if($record->relationLoaded('deliveries') && $record->deliveries->isNotEmpty())<div class="col"><strong>{{ __('Deliveries') }}</strong><div>{{ $record->deliveries->pluck('doc_num')->join(' / ') }}</div></div>
                @elseif($record->delivery ?? null)<div class="col"><strong>{{ __('Delivery') }}</strong><div>{{ $record->delivery->doc_num }}</div></div>@endif
                @if($record->creditNote ?? null)<div class="col"><strong>{{ __('Credit Note') }}</strong><div>{{ $record->creditNote->doc_num }}</div></div>@endif
                @if($showPrices && isset($record->total_amount))<div class="col"><strong>{{ __('Total') }}</strong><div dir="ltr">{{ $numbers->format($record->total_amount) }}</div></div>@endif
                @if($showPrices && isset($record->remaining_amount))<div class="col"><strong>{{ __('Outstanding') }}</strong><div dir="ltr">{{ $numbers->format($record->remaining_amount) }}</div></div>@endif
            </div>
        </div>
    </div>

    @include('modules.sales.cycle.partials.workflow-actions')

    @if($kind === 'customer_receipt')
        <div class="card mb-3" data-customer-receipt-details>
            <div class="card-header"><h6 class="mb-0">{{ __('sales_ui.collection_details') }}</h6></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6 col-lg-4"><strong>{{ __('Receipt date') }}</strong><div>{{ $dates->formatDate($record->receipt_date, '—') }}</div></div>
                    <div class="col-sm-6 col-lg-4"><strong>{{ __('sales_ui.received_by_employee') }}</strong><div>@if($record->receivedByEmployee){{ $record->receivedByEmployee->doc_num }} / {{ $record->receivedByEmployee->full_name ?: $record->receivedByEmployee->name }}@else<span class="text-500">{{ __('sales_ui.legacy_receiver_unresolved') }}</span>@endif</div></div>
                    <div class="col-sm-6 col-lg-4"><strong>{{ __('Receipt amount') }}</strong><div dir="ltr">{{ $numbers->format($record->amount) }} {{ $record->currency?->code }}</div></div>
                    <div class="col-sm-6 col-lg-4"><strong>{{ __('Payment method') }}</strong><div>{{ __(str($record->payment_method)->replace('_', ' ')->title()->toString()) }}</div></div>
                    @if($record->cashbox)<div class="col-sm-6 col-lg-4"><strong>{{ __('Cashbox') }}</strong><div>{{ $record->cashbox->doc_num }} / {{ $record->cashbox->name }}</div></div>@endif
                    @if($record->bankAccount)<div class="col-sm-6 col-lg-4"><strong>{{ __('Bank account') }}</strong><div>{{ $record->bankAccount->doc_num }} / {{ $record->bankAccount->account_name }}</div></div>@endif
                    @if($record->reference_no)<div class="col-sm-6 col-lg-4"><strong>{{ $record->payment_method === 'cheque' ? __('Cheque number') : __('sales_ui.bank_reference') }}</strong><div dir="ltr">{{ $record->reference_no }}</div></div>@endif
                    @if($record->payment_method === 'cheque' && $record->external_bank_name)<div class="col-sm-6 col-lg-4"><strong>{{ __('Drawer bank') }}</strong><div>{{ $record->external_bank_name }}</div></div>@endif
                    @if($record->payment_method === 'cheque' && $record->cheque_due_date)<div class="col-sm-6 col-lg-4"><strong>{{ __('Cheque due date') }}</strong><div>{{ $dates->formatDate($record->cheque_due_date, '—') }}</div></div>@endif
                    @if($record->notes)<div class="col-12"><strong>{{ __('Notes') }}</strong><div>{{ $record->notes }}</div></div>@endif
                </div>
            </div>
            @if($record->allocations->isNotEmpty())
                <div class="table-responsive border-top">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead><tr><th>{{ __('Invoice') }}</th><th>{{ __('Installment due') }}</th><th class="text-end">{{ __('Allocated') }}</th></tr></thead>
                        <tbody>@foreach($record->allocations as $allocation)<tr><td>{{ $allocation->invoice?->doc_num }}</td><td>{{ $dates->formatDate($allocation->invoiceSchedule?->due_date, '—') }}</td><td class="text-end" dir="ltr">{{ $numbers->format($allocation->allocated_amount) }} {{ $record->currency?->code }}</td></tr>@endforeach</tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    @if($kind === 'sales_delivery')
        <div class="card mb-3" data-sales-delivery-details>
            <div class="card-header"><h6 class="mb-0">{{ __('Delivery details') }}</h6></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6 col-lg-4"><strong>{{ __('Date') }}</strong><div>{{ $dates->formatDate($record->document_date, '—') }}</div></div>
                    @if($record->branchStore)<div class="col-sm-6 col-lg-4"><strong>{{ __('Delivery warehouse') }}</strong><div>{{ $record->branchStore->name }}</div></div>@endif
                    @if($record->recipient_name)<div class="col-sm-6 col-lg-4"><strong>{{ __('Recipient') }}</strong><div>{{ $record->recipient_name }}</div></div>@endif
                    @if($record->recipient_phone)<div class="col-sm-6 col-lg-4"><strong>{{ __('Recipient phone') }}</strong><div dir="ltr">{{ $record->recipient_phone }}</div></div>@endif
                    @if($record->vehicle_number)<div class="col-sm-6 col-lg-4"><strong>{{ __('Vehicle') }}</strong><div>{{ $record->vehicle_number }}</div></div>@endif
                    @if($record->driver_name)<div class="col-sm-6 col-lg-4"><strong>{{ __('Driver') }}</strong><div>{{ $record->driver_name }}</div></div>@endif
                    @if($record->notes)<div class="col-12"><strong>{{ __('Notes') }}</strong><div>{{ $record->notes }}</div></div>@endif
                </div>
            </div>
        </div>
    @endif

    @isset($relatedDocuments)<x-related-documents :documents="$relatedDocuments" />@endisset

    @if($lines->isNotEmpty())
        <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Lines and source traceability') }}</h6></div><div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-0"><thead><tr><th>#</th><th>{{ __('Item') }}</th><th>{{ __('Source line') }}</th><th class="text-end">{{ __('Quantity') }}</th><th class="text-end">{{ __('Delivered') }}</th><th class="text-end">{{ __('Invoiced') }}</th><th>{{ __('Quality disposition') }}</th></tr></thead><tbody>
            @foreach($lines as $line)<tr><td>{{ $line->line_number }}</td><td>{{ $lineItemLabel($line) }}@if($visibleLineSpecifications($line)->isNotEmpty())<br><small>{{ $visibleLineSpecifications($line)->map(fn ($value, $key) => __(str($key)->replace('_', ' ')->title()->toString()).': '.$value)->join(' · ') }}</small>@endif @if($kind === 'production_request' && ($line->warehouse_notes ?? null))<br><small>{{ __('Warehouse') }}: {{ $line->warehouse_notes }}</small>@endif @if($kind === 'production_request' && ($line->production_notes ?? null))<br><small>{{ __('Production') }}: {{ $line->production_notes }}</small>@endif</td><td><small>@if(method_exists($line, 'orderLine') && $line->orderLine){{ $line->orderLine->order?->doc_num }} · {{ __('Line') }} {{ $line->orderLine->line_number }}@elseif(method_exists($line, 'quotationRevisionLine') && $line->quotationRevisionLine){{ $record->quotation?->doc_num }} · {{ __('Line') }} {{ $line->quotationRevisionLine->line_number }}@else—@endif</small></td><td class="text-end" dir="ltr">{{ $numbers->format($line->quantity) }}</td><td class="text-end" dir="ltr">{{ isset($line->delivered_quantity) ? $numbers->format($line->delivered_quantity) : '—' }}</td><td class="text-end" dir="ltr">{{ isset($line->invoiced_quantity) ? $numbers->format($line->invoiced_quantity) : '—' }}</td><td>{{ $line->quality_disposition ? $qualityDispositionLabel($line->quality_disposition) : '—' }}</td></tr>@endforeach
        </tbody></table></div></div>
    @endif

    @if($kind === 'sales_return')
        <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Inventory and accounting lineage') }}</h6></div><div class="card-body"><div class="row g-3">
            <div class="col-md-4"><strong>{{ __('Quarantine Inventory Document') }}</strong><div>{{ $record->returnInventoryDocument?->doc_num ?? '—' }}</div></div>
            <div class="col-md-4"><strong>{{ __('Quarantine Journal') }}</strong><div>{{ $record->quarantineJournalEntry?->doc_num ?? '—' }}</div></div>
            <div class="col-md-4"><strong>{{ __('Disposition Journal') }}</strong><div>{{ $record->dispositionJournalEntry?->doc_num ?? __('No cross-account disposition required') }}</div></div>
        </div></div></div>
    @endif

    @if($kind === 'production_request')
        <div class="card mb-3"><div class="card-header d-flex justify-content-between"><h6 class="mb-0">{{ __('BOM snapshots and production runs') }}</h6><div class="d-flex gap-2">@can('production.orders.print')<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.production.work-orders.requirement.print', $record) }}">{{ __('Print requirement') }}</a>@endcan @if(in_array($record->status, ['draft', 'planned']))<form method="POST" action="{{ route('admin.production.work-orders.release', $record) }}">@csrf<button class="btn btn-primary btn-sm">{{ __('Release and snapshot BOM') }}</button></form>@endif</div></div><div class="card-body">
            @foreach($record->lines as $line)
                <div class="mb-3"><strong>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</strong><div class="text-500">{{ __('Target base quantity') }}: {{ $line->base_quantity }} · {{ __('Received') }}: {{ $line->received_base_quantity }}</div>
                    @if(is_array($line->bom_snapshot))<ul class="mb-0">@foreach($line->bom_snapshot['components'] ?? [] as $component)<li>{{ $component['product_doc_num'] ?? '' }} — {{ $component['product_name'] ?? '' }}: {{ $component['base_quantity_per_output'] ?? '' }} / {{ __('base output unit') }}</li>@endforeach</ul>@endif
                </div>
            @endforeach
            <div class="row g-2">@forelse($record->runs as $run)<div class="col-md-4"><a class="d-block border rounded p-2" href="{{ route('admin.production.runs.show', $run) }}"><strong>{{ $run->run_number }}</strong><br><span>{{ __(str($run->status)->replace('_', ' ')->title()->toString()) }} · {{ $run->planned_base_quantity }} / {{ $run->good_base_quantity }} / {{ $run->received_base_quantity }}</span></a></div>@empty<div class="text-500">{{ __('No production runs planned.') }}</div>@endforelse</div>
        </div></div>
    @endif

    @if($showPrices && $record->relationLoaded('paymentSchedules') && $record->paymentSchedules->isNotEmpty())
        <div class="card mb-3"><div class="card-header py-2"><h6 class="mb-0">{{ __('Payment Schedule') }}</h6></div><div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>#</th><th>{{ __('Due date') }}</th><th class="text-end">{{ __('Amount') }}</th><th class="text-end">{{ __('Collected') }}</th><th class="text-end">{{ __('Outstanding') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>@foreach($record->paymentSchedules as $schedule)<tr><td>{{ $schedule->sequence ?? $schedule->line_number }}</td><td>{{ $dates->formatDate($schedule->due_date, '') }}</td><td class="text-end">{{ $numbers->format($schedule->amount) }}</td><td class="text-end">{{ $numbers->format($schedule->collected_amount) }}</td><td class="text-end">{{ $numbers->format($schedule->outstanding_amount ?? $schedule->remaining_amount) }}</td><td>{{ __(str($schedule->payment_status ?? $schedule->status)->replace('_', ' ')->title()->toString()) }}</td></tr>@endforeach</tbody></table></div></div>
    @endif

    @if($kind === 'sales_order')
        <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Complete document chain') }}</h6></div><div class="card-body"><div class="row g-3">
            <div class="col-md-4"><strong>{{ __('Quotation') }}</strong><div>@if($record->quotation) @can('quotations.view')<a href="{{ route('admin.sales.quotations.show', $record->quotation) }}">{{ $quotationReference($record->quotation, $record->quotationRevision) }}</a>@else{{ $quotationReference($record->quotation, $record->quotationRevision) }}@endcan @else<span class="text-500">{{ __('Direct order') }}</span>@endif</div></div>
            <div class="col-md-4"><strong>{{ __('Credit Overrides') }}</strong><div>@forelse($record->creditOverrides as $document)<span class="d-block">{{ $document->overridden_at }} · {{ $document->reason }}</span>@empty<span class="text-500">—</span>@endforelse</div></div>
            <div class="col-md-4"><strong>{{ __('Reservations') }}</strong><div>@forelse($record->lines->flatMap->reservations as $document)<span class="d-block">{{ $numbers->format($document->remaining_quantity) }} · {{ __(str($document->status)->replace('_', ' ')->title()->toString()) }}</span>@empty<span class="text-500">—</span>@endforelse</div></div>
            <div class="col-md-4"><strong>{{ __('Production Requests') }}</strong><div>@forelse($record->productionOrders as $document) @can('sales_orders.production')<a class="d-block" href="{{ route('admin.sales.production-requests.show', $document) }}">{{ $document->doc_num }} · {{ __(str($document->status)->replace('_', ' ')->title()->toString()) }}</a>@else<span class="d-block">{{ $document->doc_num }}</span>@endcan @empty<span class="text-500">—</span>@endforelse</div></div>
            <div class="col-md-4"><strong>{{ __('Deliveries') }}</strong><div>@forelse($record->deliveries as $document) @can('sales_deliveries.view')<a class="d-block" href="{{ route('admin.sales.delivery-notes.show', $document) }}">{{ $document->doc_num }} · {{ __(str($document->status)->replace('_', ' ')->title()->toString()) }}</a>@else<span class="d-block">{{ $document->doc_num }}</span>@endcan @empty<span class="text-500">—</span>@endforelse</div></div>
            <div class="col-md-4"><strong>{{ __('Invoices / Credit Notes') }}</strong><div>@forelse($record->invoices as $document) @can('customer_invoices.view')<a class="d-block" href="{{ route('admin.sales.sales-invoices.show', $document) }}">{{ $document->doc_num }} · {{ __(str($document->document_type)->replace('_', ' ')->title()->toString()) }}</a>@else<span class="d-block">{{ $document->doc_num }}</span>@endcan @empty<span class="text-500">—</span>@endforelse</div></div>
            <div class="col-md-4"><strong>{{ __('Receipts / Advances') }}</strong><div>@forelse($record->receipts as $document) @can('customer_receipts.view')<a class="d-block" href="{{ route('admin.sales.customer-receipts.show', $document) }}">{{ $document->doc_num }} · {{ __(str($document->receipt_type)->replace('_', ' ')->title()->toString()) }}</a>@else<span class="d-block">{{ $document->doc_num }}</span>@endcan @empty<span class="text-500">—</span>@endforelse</div></div>
            <div class="col-md-4"><strong>{{ __('Returns / Credit Notes') }}</strong><div>@forelse($record->returns as $document) @can('sales_returns.view')<a class="d-block" href="{{ route('admin.sales.sales-returns.show', $document) }}">{{ $document->doc_num }}@if($document->creditNote) / {{ $document->creditNote->doc_num }}@endif</a>@else<span class="d-block">{{ $document->doc_num }}</span>@endcan @empty<span class="text-500">—</span>@endforelse</div></div>
        </div></div></div>
    @endif

    @if($hasInvoiceLineage)
        <div class="card mb-3"><div class="card-header py-2"><h6 class="mb-0">{{ __('Related documents and accounting lineage') }}</h6></div><div class="card-body py-3"><div class="row g-3">
            @if($record->order)<div class="col-md-4"><strong>{{ __('Source Sales Order') }}</strong><div>@can('sales_orders.view')<a href="{{ route('admin.sales.sales-orders.show', $record->order) }}">{{ $record->order->doc_num }}</a>@else{{ $record->order->doc_num }}@endcan</div></div>@endif
            @if($record->originalInvoice)<div class="col-md-4"><strong>{{ __('Original Invoice') }}</strong><div><a href="{{ route('admin.sales.sales-invoices.show', $record->originalInvoice) }}">{{ $record->originalInvoice->doc_num }}</a></div></div>@endif
            @if($record->deliveries->isNotEmpty())<div class="col-md-4"><strong>{{ __('Deliveries') }}</strong><div>@foreach($record->deliveries as $document) @can('sales_deliveries.view')<a class="d-block" href="{{ route('admin.sales.delivery-notes.show', $document) }}">{{ $document->doc_num }}</a>@else<span class="d-block">{{ $document->doc_num }}</span>@endcan @endforeach</div></div>@endif
            @if($record->allocations->isNotEmpty())<div class="col-md-4"><strong>{{ __('Receipts / Allocations') }}</strong><div>@foreach($record->allocations->pluck('receipt')->filter()->unique('id') as $document) @can('customer_receipts.view')<a class="d-block" href="{{ route('admin.sales.customer-receipts.show', $document) }}">{{ $document->doc_num }}</a>@else<span class="d-block">{{ $document->doc_num }}</span>@endcan @endforeach</div></div>@endif
            @if($record->returns->isNotEmpty())<div class="col-md-4"><strong>{{ __('Returns') }}</strong><div>@foreach($record->returns as $document) @can('sales_returns.view')<a class="d-block" href="{{ route('admin.sales.sales-returns.show', $document) }}">{{ $document->doc_num }}</a>@else<span class="d-block">{{ $document->doc_num }}</span>@endcan @endforeach</div></div>@endif
            @if($record->creditNotes->isNotEmpty())<div class="col-md-4"><strong>{{ __('Credit Notes') }}</strong><div>@foreach($record->creditNotes as $document)<a class="d-block" href="{{ route('admin.sales.sales-invoices.show', $document) }}">{{ $document->doc_num }}</a>@endforeach</div></div>@endif
            @if($record->journalEntry)<div class="col-md-4"><strong>{{ __('Journal Entry') }}</strong><div>@can('journal_entries.view')<a href="{{ route('admin.accounting.journal-entries.show', $record->journalEntry) }}">{{ $record->journalEntry->doc_num }}</a>@else{{ $record->journalEntry->doc_num }}@endcan</div></div>@endif
            @if($record->reversalJournalEntry)<div class="col-md-4"><strong>{{ __('Reversal Journal') }}</strong><div>@can('journal_entries.view')<a href="{{ route('admin.accounting.journal-entries.show', $record->reversalJournalEntry) }}">{{ $record->reversalJournalEntry->doc_num }}</a>@else{{ $record->reversalJournalEntry->doc_num }}@endcan</div></div>@endif
        </div></div></div>
    @endif

    @if($kind === 'customer_receipt' && ($record->cashVoucher || $record->cheque))
        <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Canonical Finance document') }}</h6></div><div class="card-body">
            @if($record->cashVoucher)<a href="{{ route('admin.finance.cash-receipt-vouchers.show', $record->cashVoucher) }}">{{ __('Cash Receipt Voucher') }} {{ $record->cashVoucher->doc_num }}</a> @can('cash_receipt_vouchers.print')<a class="btn btn-falcon-default btn-sm ms-2" href="{{ route('admin.finance.cash-receipt-vouchers.print', $record->cashVoucher) }}">{{ __('Print canonical voucher') }}</a>@endcan @endif
            @if($record->cheque)<a href="{{ route('admin.finance.cheques.show', $record->cheque) }}">{{ __('Received Cheque') }} {{ $record->cheque->doc_num }} / {{ $record->cheque->cheque_number }}</a><span class="badge bg-info ms-2">{{ __('cheques.statuses.'.$record->cheque->status) }}</span> @if(!$record->journal_entry_id && $record->status === 'approved')<p class="mt-2 mb-0">{{ __('Invoice settlement will be applied when Finance collects this cheque.') }}</p>@endif @can('cheques.print')<a class="btn btn-falcon-default btn-sm ms-2" href="{{ route('admin.finance.cheques.print', $record->cheque) }}">{{ __('Print canonical cheque') }}</a>@endcan @endif
        </div></div>
    @endif

    @if($kind === 'credit_note' || ($kind === 'invoice' && $record->appliedCredits->isNotEmpty()))
        <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Customer Credit Allocation and Refund') }}</h6></div><div class="card-body">
            @if($kind === 'credit_note')
                <div class="row g-3 mb-3"><div class="col-md-4"><strong>{{ __('Credit created') }}</strong><div dir="ltr">{{ $numbers->format($record->total_amount) }}</div></div><div class="col-md-4"><strong>{{ __('Available Customer Credit') }}</strong><div dir="ltr">{{ $numbers->format($record->credit_available_amount) }}</div></div><div class="col-md-4"><strong>{{ __('Allocated / refunded') }}</strong><div dir="ltr">{{ $numbers->format($record->credit_allocated_amount) }} / {{ $numbers->format($record->credit_refunded_amount) }}</div></div></div>
                @if((float) $record->credit_available_amount > 0)
                    <div class="row g-3">
                        @can('customer_credits.allocate')<div class="col-xl-6"><form class="js-sales-cycle-action border rounded p-3" action="{{ route('admin.sales.sales-invoices.credit-allocations.store', $record) }}" method="POST">@csrf<h6>{{ __('Apply to Future Invoice') }}</h6><div class="row g-2"><div class="col-md-6"><label class="form-label">{{ __('Target Invoice') }}</label><select class="form-select js-select2-ajax" name="target_invoice_doc_num" data-url="{{ route('admin.sales.select2.credit-target-invoices') }}" data-extra-params='@json(["customer_doc_num" => $record->customer?->doc_num])' data-placeholder="{{ __('Target Invoice') }}" required></select></div><div class="col-md-3"><label class="form-label">{{ __('Amount') }}</label><input class="form-control" name="amount" type="number" min="0.0001" step="0.0001" required></div><div class="col-md-3"><label class="form-label">{{ __('Date') }}</label><input class="form-control js-date-picker" name="allocation_date" value="{{ $dates->formatDate(today()) }}" required></div><div class="col-12"><button class="btn btn-primary btn-sm" type="submit">{{ __('Apply Credit') }}</button></div></div></form></div>@endcan
                        @can('customer_credits.refund')<div class="col-xl-6"><form class="js-sales-cycle-action border rounded p-3" action="{{ route('admin.sales.sales-invoices.credit-refunds.store', $record) }}" method="POST">@csrf<h6>{{ __('Refund Remaining Credit') }}</h6><div class="row g-2"><div class="col-md-4"><label class="form-label">{{ __('Amount') }}</label><input class="form-control" name="amount" type="number" min="0.0001" step="0.0001" required></div><div class="col-md-4"><label class="form-label">{{ __('Date') }}</label><input class="form-control js-date-picker" name="refund_date" value="{{ $dates->formatDate(today()) }}" required></div><div class="col-md-4"><label class="form-label">{{ __('Method') }}</label><select class="form-select" name="payment_method" required><option value="cash">{{ __('Cash') }}</option><option value="bank">{{ __('Bank') }}</option></select></div><div class="col-md-6"><label class="form-label">{{ __('Cashbox (for cash)') }}</label><select class="form-select js-select2-ajax" data-url="{{ route('admin.sales.select2.cashboxes') }}" name="cashbox_doc_num"><option value="">—</option></select></div><div class="col-md-6"><label class="form-label">{{ __('Bank account (for bank)') }}</label><select class="form-select js-select2-ajax" data-url="{{ route('admin.sales.select2.bank-accounts') }}" name="bank_account_doc_num"><option value="">—</option></select></div><div class="col-12"><button class="btn btn-warning btn-sm" type="submit">{{ __('Post Refund') }}</button></div></div></form></div>@endcan
                    </div>
                @endif
                <div class="table-responsive mt-3"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Event') }}</th><th>{{ __('Date') }}</th><th>{{ __('Document / Target') }}</th><th class="text-end">{{ __('Amount') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>@foreach($record->creditAllocations as $allocation)<tr><td>{{ __('Credit Allocation') }}</td><td>{{ $dates->formatDate($allocation->allocation_date, '') }}</td><td><a href="{{ route('admin.sales.sales-invoices.show', $allocation->targetInvoice) }}">{{ $allocation->targetInvoice?->doc_num }}</a></td><td class="text-end">{{ $numbers->format($allocation->amount) }}</td><td>{{ __(str($allocation->status)->replace('_', ' ')->title()->toString()) }}</td></tr>@endforeach @foreach($record->creditRefunds as $refund)<tr><td>{{ __('Customer Refund') }}</td><td>{{ $dates->formatDate($refund->refund_date, '') }}</td><td><a target="_blank" href="{{ route('admin.sales.customer-credit-refunds.print', $refund) }}">{{ $refund->doc_num }}</a></td><td class="text-end">{{ $numbers->format($refund->amount) }}</td><td>{{ __(str($refund->status)->replace('_', ' ')->title()->toString()) }}</td></tr>@endforeach @if($record->creditAllocations->isEmpty() && $record->creditRefunds->isEmpty())<tr><td colspan="5" class="text-center text-muted">{{ __('No credit allocations or refunds.') }}</td></tr>@endif</tbody></table></div>
            @else
                <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Credit Source') }}</th><th>{{ __('Date') }}</th><th class="text-end">{{ __('Applied Amount') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>@forelse($record->appliedCredits as $allocation)<tr><td><a href="{{ route('admin.sales.sales-invoices.show', $allocation->creditNote) }}">{{ $allocation->creditNote?->doc_num }}</a></td><td>{{ $dates->formatDate($allocation->allocation_date, '') }}</td><td class="text-end">{{ $numbers->format($allocation->amount) }}</td><td>{{ __(str($allocation->status)->replace('_', ' ')->title()->toString()) }}</td></tr>@empty<tr><td colspan="4" class="text-center text-muted">{{ __('No Customer Credit applied.') }}</td></tr>@endforelse</tbody></table></div>
            @endif
        </div></div>
    @endif

    @if(in_array($kind, ['invoice', 'credit_note'], true))
        @php($canQueueElectronicInvoice = config('e_invoice.enabled') && $record->status === 'posted' && blank($record->electronic_invoice_uuid) && in_array($record->electronic_invoice_status, ['not_configured', 'draft', 'rejected', 'submission_failed'], true))
        <div class="card mb-3" data-electronic-invoice-summary>
            <div class="card-header py-2 d-flex flex-wrap align-items-center justify-content-between gap-2">
                <h6 class="mb-0">{{ __('Tax / Electronic Invoice Status') }}</h6>
                @if($canQueueElectronicInvoice) @can('customer_invoices.electronic_invoice.submit')<form class="js-sales-cycle-action" method="POST" action="{{ route('admin.sales.sales-invoices.electronic-invoice.submit', $record) }}">@csrf<button class="btn btn-primary btn-sm" type="submit">{{ __('Queue Electronic Invoice Submission') }}</button></form>@endcan @endif
            </div>
            <div class="card-body py-2">
                <div class="row g-2"><div class="col-sm-6 col-lg-4"><strong>{{ __('Output tax') }}</strong><div dir="ltr">{{ $showPrices ? $numbers->format($record->tax_amount) : '—' }}</div></div><div class="col-sm-6 col-lg-4"><strong>{{ __('Electronic invoice') }}</strong><div><span class="badge bg-{{ $record->electronic_invoice_status === 'accepted' ? 'success' : 'secondary' }}">{{ __(str($record->electronic_invoice_status)->replace('_', ' ')->title()->toString()) }}</span></div></div>@if($record->electronic_invoice_uuid)<div class="col-sm-6 col-lg-4"><strong>{{ __('Authority UUID') }}</strong><div dir="ltr" class="text-break">{{ $record->electronic_invoice_uuid }}</div></div>@endif</div>
                @if($record->electronic_invoice_status === 'not_configured')<div class="alert alert-info py-2 px-3 mt-2 mb-0">{{ __('No tax-authority/e-invoice connector is configured in this installation. Tax is posted to the canonical output-tax account; submission is intentionally not represented as complete.') }}</div>@endif
            </div>
        </div>
        @if($record->electronicInvoiceSubmissions->isNotEmpty())<div class="card mb-3"><div class="card-header py-2"><h6 class="mb-0">{{ __('Electronic Invoice Submission Outbox') }}</h6></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Provider / Environment') }}</th><th>{{ __('Payload version / hash') }}</th><th>{{ __('Status') }}</th><th>{{ __('Attempts') }}</th><th>{{ __('Provider Reference') }}</th><th>{{ __('Last Result') }}</th></tr></thead><tbody>@foreach($record->electronicInvoiceSubmissions as $submission)<tr><td>{{ $submission->provider }} / {{ $submission->environment }}</td><td>{{ $submission->payload_version }} / <span dir="ltr">{{ str($submission->payload_hash)->limit(16) }}</span></td><td>{{ __(str($submission->status)->replace('_', ' ')->title()->toString()) }}</td><td>{{ $submission->attempt_count }}</td><td dir="ltr">{{ $submission->provider_reference }}</td><td>{{ $submission->error_message ?: $dates->formatDateTime($submission->accepted_at ?? $submission->submitted_at, '') }}</td></tr>@endforeach</tbody></table></div></div>@endif
    @endif
@if(in_array($kind, ['sales_order', 'sales_delivery', 'invoice', 'credit_note', 'customer_receipt', 'sales_return', 'sales_request']))
@include('modules.sales.cycle.partials.attachments', ['attachmentRecord' => $record, 'attachmentKind' => $kind, 'attachmentsReadonly' => !auth()->user()?->can(match($kind) { 'sales_request' => 'sales_requests.edit', 'sales_order' => 'sales_orders.edit', 'sales_delivery' => 'sales_deliveries.create', 'customer_receipt' => 'customer_receipts.create', 'sales_return' => 'sales_returns.create', default => 'customer_invoices.edit' })])
@endif
@endsection

@push('scripts')
@include('modules.sales.cycle.partials.scripts')
@endpush

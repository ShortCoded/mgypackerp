@extends('layouts.app')

@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $title = str($kind)->replace('_', ' ')->title();
    $lines = $record->relationLoaded('lines') ? $record->lines : collect();
    $printRoutes = [
        'sales_order' => 'admin.sales.sales-orders.print', 'invoice' => 'admin.sales.sales-invoices.print',
        'credit_note' => 'admin.sales.sales-invoices.print', 'customer_receipt' => 'admin.sales.customer-receipts.print',
        'sales_return' => 'admin.sales.sales-returns.print', 'sales_delivery' => 'admin.sales.delivery-notes.print',
        'production_request' => request()->routeIs('admin.production.*') ? 'admin.production.work-orders.print' : 'admin.sales.production-requests.print',
        'payment_schedule' => 'admin.sales.sales-invoices.payment-schedule.print',
        'quality_disposition' => 'admin.sales.sales-returns.quality-disposition.print',
    ];
@endphp

@section('title', $title.' '.$record->doc_num)

@section('content')
    <div class="alert alert-danger d-none js-sales-form-alert"></div>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div><h5 class="mb-1">{{ $title }}</h5><div class="text-600">{{ $record->doc_num }}</div></div>
            <div class="d-flex gap-2 align-items-center"><span class="badge bg-secondary">{{ str($record->status)->replace('_', ' ')->title() }}</span><a class="btn btn-falcon-primary btn-sm" href="{{ route($printRoutes[$kind], $record) }}">{{ __('Print') }}</a></div>
        </div>
        <div class="card-body">
            <div class="row g-3">
                @if($record->customer ?? null)<div class="col-md-4"><strong>{{ __('Customer') }}</strong><div>{{ $record->customer->doc_num }} / {{ $record->customer->name }}</div></div>@endif
                @if($record->customer_reference ?? null)<div class="col-md-4"><strong>{{ __('Customer reference / PO') }}</strong><div>{{ $record->customer_reference }}</div></div>@endif
                @if($record->expected_delivery_date ?? null)<div class="col-md-4"><strong>{{ __('Required date') }}</strong><div>{{ $dates->formatDate($record->expected_delivery_date, '') }}</div></div>@endif
                @if($record->salesEmployee ?? null)<div class="col-md-4"><strong>{{ __('Sales representative') }}</strong><div>{{ $record->salesEmployee->doc_num }} / {{ $record->salesEmployee->name }}</div></div>@endif
                @if($record->quotation ?? null)<div class="col-md-4"><strong>{{ __('Source Quotation') }}</strong><div>@can('quotations.view')<a href="{{ route('admin.sales.quotations.show', $record->quotation) }}">{{ $record->quotation->doc_num }} / {{ $record->quotationRevision?->revision_code }}</a>@else{{ $record->quotation->doc_num }}@endcan</div></div>@endif
                @if($record->salesOrder ?? $record->order ?? null)<div class="col-md-4"><strong>{{ __('Sales Order') }}</strong><div>{{ ($record->salesOrder ?? $record->order)->doc_num }}</div></div>@endif
                @if($record->invoice ?? null)<div class="col-md-4"><strong>{{ __('Original Invoice') }}</strong><div>{{ $record->invoice->doc_num }}</div></div>@endif
                @if($record->relationLoaded('deliveries') && $record->deliveries->isNotEmpty())<div class="col-md-4"><strong>{{ __('Deliveries') }}</strong><div>{{ $record->deliveries->pluck('doc_num')->join(' / ') }}</div></div>
                @elseif($record->delivery ?? null)<div class="col-md-4"><strong>{{ __('Delivery') }}</strong><div>{{ $record->delivery->doc_num }}</div></div>@endif
                @if($record->creditNote ?? null)<div class="col-md-4"><strong>{{ __('Credit Note') }}</strong><div>{{ $record->creditNote->doc_num }}</div></div>@endif
                @if($showPrices && isset($record->total_amount))<div class="col-md-4"><strong>{{ __('Total') }}</strong><div dir="ltr">{{ $numbers->format($record->total_amount) }}</div></div>@endif
                @if($showPrices && isset($record->remaining_amount))<div class="col-md-4"><strong>{{ __('Outstanding') }}</strong><div dir="ltr">{{ $numbers->format($record->remaining_amount) }}</div></div>@endif
            </div>
        </div>
    </div>

    @include('modules.sales.cycle.partials.workflow-actions')

    @isset($relatedDocuments)<x-related-documents :documents="$relatedDocuments" />@endisset

    @if($lines->isNotEmpty())
        <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Lines and source traceability') }}</h6></div><div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-0" style="min-width: 980px"><thead><tr><th>#</th><th>{{ __('Item') }}</th><th>{{ __('Source line') }}</th><th class="text-end">{{ __('Quantity') }}</th><th class="text-end">{{ __('Delivered') }}</th><th class="text-end">{{ __('Invoiced') }}</th><th>{{ __('Quality disposition') }}</th></tr></thead><tbody>
            @foreach($lines as $line)<tr><td>{{ $line->line_number }}</td><td>{{ trim(implode(' / ', array_filter([$line->product?->doc_num, $line->product?->name, $line->description]))) }}@if(collect($line->specifications ?? [])->filter()->isNotEmpty())<br><small>{{ collect($line->specifications)->filter()->map(fn ($value, $key) => str($key)->replace('_', ' ')->title().': '.$value)->join(' · ') }}</small>@endif @if($line->warehouse_notes ?? null)<br><small>{{ __('Warehouse') }}: {{ $line->warehouse_notes }}</small>@endif @if($line->production_notes ?? null)<br><small>{{ __('Production') }}: {{ $line->production_notes }}</small>@endif</td><td><small>{{ $line->public_id }}@if($line->sales_order_line_id)<br>SO line: {{ $line->sales_order_line_id }}@endif @if($line->delivery_line_id)<br>Delivery line: {{ $line->delivery_line_id }}@endif</small></td><td class="text-end" dir="ltr">{{ $numbers->format($line->quantity) }}</td><td class="text-end" dir="ltr">{{ isset($line->delivered_quantity) ? $numbers->format($line->delivered_quantity) : '—' }}</td><td class="text-end" dir="ltr">{{ isset($line->invoiced_quantity) ? $numbers->format($line->invoiced_quantity) : '—' }}</td><td>{{ $line->quality_disposition ?? '—' }}</td></tr>@endforeach
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
            <div class="row g-2">@forelse($record->runs as $run)<div class="col-md-4"><a class="d-block border rounded p-2" href="{{ route('admin.production.runs.show', $run) }}"><strong>{{ $run->run_number }}</strong><br><span>{{ $run->status }} · {{ $run->planned_base_quantity }} / {{ $run->good_base_quantity }} / {{ $run->received_base_quantity }}</span></a></div>@empty<div class="text-500">{{ __('No production runs planned.') }}</div>@endforelse</div>
        </div></div>
    @endif

    @if($showPrices && $record->relationLoaded('paymentSchedules') && $record->paymentSchedules->isNotEmpty())
        <div class="card"><div class="card-header"><h6 class="mb-0">{{ __('Payment Schedule') }}</h6></div><div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>#</th><th>{{ __('Due date') }}</th><th class="text-end">{{ __('Amount') }}</th><th class="text-end">{{ __('Collected') }}</th><th class="text-end">{{ __('Outstanding') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>@foreach($record->paymentSchedules as $schedule)<tr><td>{{ $schedule->sequence ?? $schedule->line_number }}</td><td>{{ $dates->formatDate($schedule->due_date, '') }}</td><td class="text-end">{{ $numbers->format($schedule->amount) }}</td><td class="text-end">{{ $numbers->format($schedule->collected_amount) }}</td><td class="text-end">{{ $numbers->format($schedule->outstanding_amount ?? $schedule->remaining_amount) }}</td><td>{{ $schedule->payment_status ?? $schedule->status }}</td></tr>@endforeach</tbody></table></div></div>
    @endif

    @if($kind === 'sales_order')
        <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Complete document chain') }}</h6></div><div class="card-body"><div class="row g-3">
            <div class="col-md-4"><strong>{{ __('Quotation') }}</strong><div>@if($record->quotation) @can('quotations.view')<a href="{{ route('admin.sales.quotations.show', $record->quotation) }}">{{ $record->quotation->doc_num }} / {{ $record->quotationRevision?->revision_code }}</a>@else{{ $record->quotation->doc_num }}@endcan @else<span class="text-500">{{ __('Direct order') }}</span>@endif</div></div>
            <div class="col-md-4"><strong>{{ __('Credit Overrides') }}</strong><div>@forelse($record->creditOverrides as $document)<span class="d-block">{{ $document->overridden_at }} · {{ $document->reason }}</span>@empty<span class="text-500">—</span>@endforelse</div></div>
            <div class="col-md-4"><strong>{{ __('Reservations') }}</strong><div>@forelse($record->lines->flatMap->reservations as $document)<span class="d-block">{{ str($document->public_id)->limit(12) }} · {{ $document->status }}</span>@empty<span class="text-500">—</span>@endforelse</div></div>
            <div class="col-md-4"><strong>{{ __('Production Requests') }}</strong><div>@forelse($record->productionOrders as $document) @can('sales_orders.production')<a class="d-block" href="{{ route('admin.sales.production-requests.show', $document) }}">{{ $document->doc_num }} · {{ str($document->status)->replace('_', ' ')->title() }}</a>@else<span class="d-block">{{ $document->doc_num }}</span>@endcan @empty<span class="text-500">—</span>@endforelse</div></div>
            <div class="col-md-4"><strong>{{ __('Deliveries') }}</strong><div>@forelse($record->deliveries as $document) @can('sales_deliveries.view')<a class="d-block" href="{{ route('admin.sales.delivery-notes.show', $document) }}">{{ $document->doc_num }} · {{ $document->status }}</a>@else<span class="d-block">{{ $document->doc_num }}</span>@endcan @empty<span class="text-500">—</span>@endforelse</div></div>
            <div class="col-md-4"><strong>{{ __('Invoices / Credit Notes') }}</strong><div>@forelse($record->invoices as $document) @can('customer_invoices.view')<a class="d-block" href="{{ route('admin.sales.sales-invoices.show', $document) }}">{{ $document->doc_num }} · {{ str($document->document_type)->replace('_', ' ')->title() }}</a>@else<span class="d-block">{{ $document->doc_num }}</span>@endcan @empty<span class="text-500">—</span>@endforelse</div></div>
            <div class="col-md-4"><strong>{{ __('Receipts / Advances') }}</strong><div>@forelse($record->receipts as $document) @can('customer_receipts.view')<a class="d-block" href="{{ route('admin.sales.customer-receipts.show', $document) }}">{{ $document->doc_num }} · {{ str($document->receipt_type)->replace('_', ' ')->title() }}</a>@else<span class="d-block">{{ $document->doc_num }}</span>@endcan @empty<span class="text-500">—</span>@endforelse</div></div>
            <div class="col-md-4"><strong>{{ __('Returns / Credit Notes') }}</strong><div>@forelse($record->returns as $document) @can('sales_returns.view')<a class="d-block" href="{{ route('admin.sales.sales-returns.show', $document) }}">{{ $document->doc_num }}@if($document->creditNote) / {{ $document->creditNote->doc_num }}@endif</a>@else<span class="d-block">{{ $document->doc_num }}</span>@endcan @empty<span class="text-500">—</span>@endforelse</div></div>
        </div></div></div>
    @endif

    @if(in_array($kind, ['invoice', 'credit_note'], true))
        <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Related documents and accounting lineage') }}</h6></div><div class="card-body"><div class="row g-3">
            <div class="col-md-4"><strong>{{ __('Source Sales Order') }}</strong><div>@if($record->order) @can('sales_orders.view')<a href="{{ route('admin.sales.sales-orders.show', $record->order) }}">{{ $record->order->doc_num }}</a>@else{{ $record->order->doc_num }}@endcan @else—@endif</div></div>
            @if($record->originalInvoice)<div class="col-md-4"><strong>{{ __('Original Invoice') }}</strong><div><a href="{{ route('admin.sales.sales-invoices.show', $record->originalInvoice) }}">{{ $record->originalInvoice->doc_num }}</a></div></div>@endif
            <div class="col-md-4"><strong>{{ __('Deliveries') }}</strong><div>@forelse($record->deliveries as $document) @can('sales_deliveries.view')<a class="d-block" href="{{ route('admin.sales.delivery-notes.show', $document) }}">{{ $document->doc_num }}</a>@else<span class="d-block">{{ $document->doc_num }}</span>@endcan @empty<span class="text-500">—</span>@endforelse</div></div>
            <div class="col-md-4"><strong>{{ __('Receipts / Allocations') }}</strong><div>@forelse($record->allocations->pluck('receipt')->filter()->unique('id') as $document) @can('customer_receipts.view')<a class="d-block" href="{{ route('admin.sales.customer-receipts.show', $document) }}">{{ $document->doc_num }}</a>@else<span class="d-block">{{ $document->doc_num }}</span>@endcan @empty<span class="text-500">—</span>@endforelse</div></div>
            <div class="col-md-4"><strong>{{ __('Returns') }}</strong><div>@forelse($record->returns as $document) @can('sales_returns.view')<a class="d-block" href="{{ route('admin.sales.sales-returns.show', $document) }}">{{ $document->doc_num }}</a>@else<span class="d-block">{{ $document->doc_num }}</span>@endcan @empty<span class="text-500">—</span>@endforelse</div></div>
            <div class="col-md-4"><strong>{{ __('Credit Notes') }}</strong><div>@forelse($record->creditNotes as $document)<a class="d-block" href="{{ route('admin.sales.sales-invoices.show', $document) }}">{{ $document->doc_num }}</a>@empty<span class="text-500">—</span>@endforelse</div></div>
            <div class="col-md-4"><strong>{{ __('Journal Entry') }}</strong><div>@if($record->journalEntry) @can('journal_entries.view')<a href="{{ route('admin.accounting.journal-entries.show', $record->journalEntry) }}">{{ $record->journalEntry->doc_num }}</a>@else{{ $record->journalEntry->doc_num }}@endcan @else—@endif</div></div>
            @if($record->reversalJournalEntry)<div class="col-md-4"><strong>{{ __('Reversal Journal') }}</strong><div>@can('journal_entries.view')<a href="{{ route('admin.accounting.journal-entries.show', $record->reversalJournalEntry) }}">{{ $record->reversalJournalEntry->doc_num }}</a>@else{{ $record->reversalJournalEntry->doc_num }}@endcan</div></div>@endif
        </div></div></div>
    @endif

    @if($kind === 'customer_receipt' && ($record->cashVoucher || $record->cheque))
        <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Canonical Finance document') }}</h6></div><div class="card-body">
            @if($record->cashVoucher)<a href="{{ route('admin.finance.cash-receipt-vouchers.show', $record->cashVoucher) }}">{{ __('Cash Receipt Voucher') }} {{ $record->cashVoucher->doc_num }}</a> @can('cash_receipt_vouchers.print')<a class="btn btn-falcon-default btn-sm ms-2" href="{{ route('admin.finance.cash-receipt-vouchers.print', $record->cashVoucher) }}">{{ __('Print canonical voucher') }}</a>@endcan @endif
            @if($record->cheque)<a href="{{ route('admin.finance.cheques.show', $record->cheque) }}">{{ __('Received Cheque') }} {{ $record->cheque->doc_num }} / {{ $record->cheque->cheque_number }}</a> @can('cheques.print')<a class="btn btn-falcon-default btn-sm ms-2" href="{{ route('admin.finance.cheques.print', $record->cheque) }}">{{ __('Print canonical cheque') }}</a>@endcan @endif
        </div></div>
    @endif

    @if(in_array($kind, ['invoice', 'credit_note'], true))
        <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Customer Credit Allocation and Refund') }}</h6></div><div class="card-body">
            @if($kind === 'credit_note')
                <div class="row g-3 mb-3"><div class="col-md-4"><strong>{{ __('Credit created') }}</strong><div dir="ltr">{{ $numbers->format($record->total_amount) }}</div></div><div class="col-md-4"><strong>{{ __('Available Customer Credit') }}</strong><div dir="ltr">{{ $numbers->format($record->credit_available_amount) }}</div></div><div class="col-md-4"><strong>{{ __('Allocated / refunded') }}</strong><div dir="ltr">{{ $numbers->format($record->credit_allocated_amount) }} / {{ $numbers->format($record->credit_refunded_amount) }}</div></div></div>
                @if((float) $record->credit_available_amount > 0)
                    <div class="row g-3">
                        @can('customer_credits.allocate')<div class="col-xl-6"><form class="js-sales-cycle-action border rounded p-3" action="{{ route('admin.sales.sales-invoices.credit-allocations.store', $record) }}" method="POST">@csrf<h6>{{ __('Apply to Future Invoice') }}</h6><div class="row g-2"><div class="col-md-6"><label class="form-label">{{ __('Target Invoice') }}</label><select class="form-select" name="target_invoice_doc_num" required>@foreach($creditTargetInvoices as $invoice)<option value="{{ $invoice->doc_num }}">{{ $invoice->doc_num }} — {{ $invoice->invoice_date?->toDateString() }} — {{ $numbers->format($invoice->remaining_amount) }}</option>@endforeach</select></div><div class="col-md-3"><label class="form-label">{{ __('Amount') }}</label><input class="form-control" name="amount" type="number" min="0.0001" step="0.0001" required></div><div class="col-md-3"><label class="form-label">{{ __('Date') }}</label><input class="form-control" name="allocation_date" type="date" value="{{ today()->toDateString() }}" required></div><div class="col-12"><button class="btn btn-primary btn-sm" type="submit" @disabled($creditTargetInvoices->isEmpty())>{{ __('Apply Credit') }}</button></div></div></form></div>@endcan
                        @can('customer_credits.refund')<div class="col-xl-6"><form class="js-sales-cycle-action border rounded p-3" action="{{ route('admin.sales.sales-invoices.credit-refunds.store', $record) }}" method="POST">@csrf<h6>{{ __('Refund Remaining Credit') }}</h6><div class="row g-2"><div class="col-md-4"><label class="form-label">{{ __('Amount') }}</label><input class="form-control" name="amount" type="number" min="0.0001" step="0.0001" required></div><div class="col-md-4"><label class="form-label">{{ __('Date') }}</label><input class="form-control" name="refund_date" type="date" value="{{ today()->toDateString() }}" required></div><div class="col-md-4"><label class="form-label">{{ __('Method') }}</label><select class="form-select" name="payment_method" required><option value="cash">{{ __('Cash') }}</option><option value="bank">{{ __('Bank') }}</option></select></div><div class="col-md-6"><label class="form-label">{{ __('Cashbox (for cash)') }}</label><select class="form-select" name="cashbox_doc_num"><option value="">—</option>@foreach($creditCashboxes as $cashbox)<option value="{{ $cashbox->doc_num }}">{{ $cashbox->doc_num }} — {{ $cashbox->name }}</option>@endforeach</select></div><div class="col-md-6"><label class="form-label">{{ __('Bank account (for bank)') }}</label><select class="form-select" name="bank_account_doc_num"><option value="">—</option>@foreach($creditBankAccounts as $bank)<option value="{{ $bank->doc_num }}">{{ $bank->doc_num }} — {{ $bank->account_name }}</option>@endforeach</select></div><div class="col-12"><button class="btn btn-warning btn-sm" type="submit">{{ __('Post Refund') }}</button></div></div></form></div>@endcan
                    </div>
                @endif
                <div class="table-responsive mt-3"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Event') }}</th><th>{{ __('Date') }}</th><th>{{ __('Document / Target') }}</th><th class="text-end">{{ __('Amount') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>@foreach($record->creditAllocations as $allocation)<tr><td>{{ __('Credit Allocation') }}</td><td>{{ $allocation->allocation_date?->toDateString() }}</td><td><a href="{{ route('admin.sales.sales-invoices.show', $allocation->targetInvoice) }}">{{ $allocation->targetInvoice?->doc_num }}</a></td><td class="text-end">{{ $numbers->format($allocation->amount) }}</td><td>{{ $allocation->status }}</td></tr>@endforeach @foreach($record->creditRefunds as $refund)<tr><td>{{ __('Customer Refund') }}</td><td>{{ $refund->refund_date?->toDateString() }}</td><td><a target="_blank" href="{{ route('admin.sales.customer-credit-refunds.print', $refund) }}">{{ $refund->doc_num }}</a></td><td class="text-end">{{ $numbers->format($refund->amount) }}</td><td>{{ $refund->status }}</td></tr>@endforeach @if($record->creditAllocations->isEmpty() && $record->creditRefunds->isEmpty())<tr><td colspan="5" class="text-center text-muted">{{ __('No credit allocations or refunds.') }}</td></tr>@endif</tbody></table></div>
            @else
                <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Credit Source') }}</th><th>{{ __('Date') }}</th><th class="text-end">{{ __('Applied Amount') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>@forelse($record->appliedCredits as $allocation)<tr><td><a href="{{ route('admin.sales.sales-invoices.show', $allocation->creditNote) }}">{{ $allocation->creditNote?->doc_num }}</a></td><td>{{ $allocation->allocation_date?->toDateString() }}</td><td class="text-end">{{ $numbers->format($allocation->amount) }}</td><td>{{ $allocation->status }}</td></tr>@empty<tr><td colspan="4" class="text-center text-muted">{{ __('No Customer Credit applied.') }}</td></tr>@endforelse</tbody></table></div>
            @endif
        </div>

        <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Tax / Electronic Invoice Status') }}</h6></div><div class="card-body"><div class="row g-3"><div class="col-md-4"><strong>{{ __('Output tax') }}</strong><div dir="ltr">{{ $showPrices ? $numbers->format($record->tax_amount) : '—' }}</div></div><div class="col-md-4"><strong>{{ __('Electronic invoice') }}</strong><div><span class="badge bg-{{ $record->electronic_invoice_status === 'accepted' ? 'success' : 'secondary' }}">{{ str($record->electronic_invoice_status)->replace('_', ' ')->title() }}</span></div></div><div class="col-md-4"><strong>{{ __('Authority UUID') }}</strong><div dir="ltr">{{ $record->electronic_invoice_uuid ?: '—' }}</div></div></div>@if($record->electronic_invoice_status === 'not_configured')<div class="alert alert-info mt-3 mb-0">{{ __('No tax-authority/e-invoice connector is configured in this installation. Tax is posted to the canonical output-tax account; submission is intentionally not represented as complete.') }}</div>@endif</div></div>
        @can('customer_invoices.electronic_invoice.submit')<form class="js-sales-cycle-action mb-3" method="POST" action="{{ route('admin.sales.sales-invoices.electronic-invoice.submit', $record) }}">@csrf<button class="btn btn-primary btn-sm" type="submit">{{ __('Queue Electronic Invoice Submission') }}</button></form>@endcan
        @if($record->electronicInvoiceSubmissions->isNotEmpty())<div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Electronic Invoice Submission Outbox') }}</h6></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Provider / Environment') }}</th><th>{{ __('Payload version / hash') }}</th><th>{{ __('Status') }}</th><th>{{ __('Attempts') }}</th><th>{{ __('Provider Reference') }}</th><th>{{ __('Last Result') }}</th></tr></thead><tbody>@foreach($record->electronicInvoiceSubmissions as $submission)<tr><td>{{ $submission->provider }} / {{ $submission->environment }}</td><td>{{ $submission->payload_version }} / <span dir="ltr">{{ str($submission->payload_hash)->limit(16) }}</span></td><td>{{ str($submission->status)->replace('_', ' ')->title() }}</td><td>{{ $submission->attempt_count }}</td><td dir="ltr">{{ $submission->provider_reference }}</td><td>{{ $submission->error_message ?: ($submission->accepted_at?->toDateTimeString() ?? $submission->submitted_at?->toDateTimeString()) }}</td></tr>@endforeach</tbody></table></div></div>@endif
    @endif
@endsection

@push('scripts')
<script src="{{ asset('assets/js/modules/Sales/sales-cycle.js') }}"></script>
@endpush

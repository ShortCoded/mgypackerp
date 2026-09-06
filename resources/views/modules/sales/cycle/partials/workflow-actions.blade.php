@php $today = now()->toDateString(); @endphp

@if($kind === 'sales_order')
    @if(($creditControl ?? null) !== null)
        <div class="card mb-3 border-{{ $creditControl['blocked'] ? 'danger' : 'success' }}">
            <div class="card-header"><h6 class="mb-0">{{ __('Credit Control') }}</h6></div>
            <div class="card-body">
                <div class="row g-3">
                    @foreach([
                        __('Credit limit') => 'credit_limit',
                        __('Outstanding receivables') => 'outstanding_receivables',
                        __('Open order exposure') => 'open_order_exposure',
                        __('Projected exposure') => 'projected_exposure',
                        __('Required advance') => 'required_advance',
                        __('Received canonical advance') => 'approved_advance',
                    ] as $label => $field)
                        <div class="col-md-4 col-xl-2"><div class="text-600 small">{{ $label }}</div><div class="fw-semibold" dir="ltr">{{ app(\Modules\Core\Services\NumericFormatService::class)->format($creditControl[$field]) }}</div></div>
                    @endforeach
                </div>
                <div class="alert alert-{{ $creditControl['blocked'] ? 'danger' : 'success' }} mt-3 mb-0">
                    {{ $creditControl['blocked'] ? __('Order is held by credit policy.') : __('Credit policy is clear for release.') }}
                    @if($creditControl['credit_exceeded']) {{ __('Projected exposure exceeds the applicable credit limit.') }} @endif
                    @if($creditControl['advance_missing']) {{ __('The required posted customer advance has not been received.') }} @endif
                    @if($creditControl['advance_missing']) @can('customer_receipts.create')<a class="btn btn-sm btn-light ms-2" href="{{ route('admin.sales.customer-receipts.create', ['order' => $record->doc_num]) }}">{{ __('Record canonical advance') }}</a>@endcan @endif
                </div>
            </div>
        </div>
    @endif

    @if(($stockStatus ?? collect())->isNotEmpty())
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0">{{ __('Stock, reservation, and production status') }}</h6></div>
            <div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-0" style="min-width:1050px"><thead><tr><th>{{ __('Product') }}</th><th>{{ __('Unit') }}</th><th class="text-end">{{ __('Ordered') }}</th><th class="text-end">{{ __('On hand') }}</th><th class="text-end">{{ __('Available to this order') }}</th><th class="text-end">{{ __('Reserved') }}</th><th class="text-end">{{ __('Shortage') }}</th><th class="text-end">{{ __('Production requested') }}</th><th class="text-end">{{ __('Produced') }}</th><th class="text-end">{{ __('Delivered') }}</th><th class="text-end">{{ __('Remaining') }}</th></tr></thead><tbody>
                @foreach($record->lines->reject->isService() as $line)
                    @php $stock = $stockStatus->get($line->getKey()); @endphp
                    <tr><td>{{ $line->product?->doc_num }} / {{ $line->product?->name }}</td><td>{{ $line->unit?->name }}</td><td class="text-end">{{ $numbers->format($line->quantity) }}</td><td class="text-end">{{ $numbers->format($stock['on_hand']) }}</td><td class="text-end">{{ $numbers->format($stock['available']) }}</td><td class="text-end">{{ $numbers->format($stock['reserved']) }}</td><td class="text-end">{{ $numbers->format($stock['shortage']) }}</td><td class="text-end">{{ $numbers->format($line->production_requested_quantity) }}</td><td class="text-end">{{ $numbers->format($line->produced_quantity) }}</td><td class="text-end">{{ $numbers->format($line->delivered_quantity) }}</td><td class="text-end">{{ $numbers->format($line->remainingDeliveryQuantity()) }}</td></tr>
                @endforeach
            </tbody></table></div>
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-header"><h6 class="mb-0">{{ __('Order actions') }}</h6></div>
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2 mb-3">
                @if($record->isEditable()) @can('sales_orders.edit')<a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.sales.sales-orders.edit', $record) }}">{{ __('Edit Draft') }}</a>@endcan @endif
                @if(in_array($record->status, ['draft', 'reopened'], true)) @can('sales_orders.edit')<form data-sales-ui class="js-sales-cycle-action" action="{{ route('admin.sales.sales-orders.submit', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><button class="btn btn-primary btn-sm" type="submit">{{ __('Submit for Approval') }}</button></form>@endcan @endif
                @if(in_array($record->status, ['draft', 'pending_approval', 'held_credit'], true)) @can('sales_orders.approve')<form data-sales-ui class="js-sales-cycle-action" action="{{ route('admin.sales.sales-orders.approve', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><button class="btn btn-success btn-sm" type="submit">{{ __('Approve / Evaluate Credit') }}</button></form>@endcan @endif
            </div>
            <div class="row g-3">
                @if($record->status === 'held_credit') @can('sales_orders.credit_override')<div class="col-lg-4"><form data-sales-ui class="js-sales-cycle-action border rounded p-3" action="{{ route('admin.sales.sales-orders.credit-override', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><label class="form-label">{{ __('Credit override reason') }}</label><textarea class="form-control form-control-sm mb-2" name="reason" required></textarea><button class="btn btn-warning btn-sm" type="submit">{{ __('Override Hold') }}</button></form></div>@endcan @endif
                @if(in_array($record->status, ['draft', 'pending_approval', 'held_credit'], true)) @can('sales_orders.reject')<div class="col-lg-4"><form data-sales-ui class="js-sales-cycle-action border rounded p-3" action="{{ route('admin.sales.sales-orders.reject', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><label class="form-label">{{ __('Rejection reason') }}</label><textarea class="form-control form-control-sm mb-2" name="reason" required></textarea><button class="btn btn-danger btn-sm" type="submit">{{ __('Reject') }}</button></form></div>@endcan @endif
                @if(in_array($record->status, ['approved', 'rejected', 'closed'], true)) @can('sales_orders.reopen')<div class="col-lg-4"><form data-sales-ui class="js-sales-cycle-action border rounded p-3" action="{{ route('admin.sales.sales-orders.reopen', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><label class="form-label">{{ __('Reopen reason') }}</label><textarea class="form-control form-control-sm mb-2" name="reason" required></textarea><button class="btn btn-falcon-warning btn-sm" type="submit">{{ __('Reopen for Amendment') }}</button></form></div>@endcan @endif
                @if(! in_array($record->status, ['cancelled', 'closed'], true)) @can('sales_orders.cancel')<div class="col-lg-4"><form data-sales-ui class="js-sales-cycle-action border rounded p-3" action="{{ route('admin.sales.sales-orders.cancel', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><label class="form-label">{{ __('Cancellation reason') }}</label><textarea class="form-control form-control-sm mb-2" name="reason" required></textarea><button class="btn btn-outline-danger btn-sm" type="submit">{{ __('Cancel') }}</button></form></div>@endcan @endif
            </div>
        </div>
    </div>

    @if($record->isApprovedForFulfillment() || $record->status === 'fulfilled')
        @if($record->status !== 'fulfilled')
        <div class="row g-3 mb-3">
            @can('sales_orders.reserve')
            <div class="col-xl-4"><div class="card h-100"><div class="card-header"><h6 class="mb-0">{{ __('Reserve available stock') }}</h6></div><div class="card-body">
                @foreach($record->lines->reject->isService() as $line)<form data-sales-ui class="js-sales-cycle-action border-bottom pb-2 mb-2" action="{{ route('admin.sales.sales-orders.reservations.store', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><input type="hidden" name="sales_order_line_public_id" value="{{ $line->public_id }}"><div class="small fw-semibold mb-1">{{ $line->product?->name }} · {{ __('Remaining') }} {{ $numbers->format($line->remainingDeliveryQuantity()) }} {{ $line->unit?->name }}</div><div class="input-group input-group-sm"><input class="form-control text-end" name="quantity" inputmode="decimal" required><button class="btn btn-falcon-primary" type="submit">{{ __('Reserve') }}</button></div></form>@endforeach
                @foreach($record->lines->flatMap->reservations->where('status', 'active') as $reservation)<form data-sales-ui class="js-sales-cycle-action border rounded p-2 mb-2" action="{{ route('admin.sales.sales-orders.reservations.release', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><input type="hidden" name="reservation_public_id" value="{{ $reservation->public_id }}"><div class="small mb-1">{{ __('Active reservation') }}: {{ bcdiv($reservation->remaining_quantity, $reservation->conversion_factor, 8) }} {{ $reservation->transactionUnit?->name }}</div><div class="input-group input-group-sm"><input class="form-control" name="reason" placeholder="{{ __('Release reason') }}" required><button class="btn btn-outline-warning" type="submit">{{ __('Release reservation') }}</button></div></form>@endforeach
            </div></div></div>
            @endcan
            @can('sales_orders.production')
            <div class="col-xl-4"><div class="card h-100"><div class="card-header"><h6 class="mb-0">{{ __('Create Production Requirement') }}</h6></div><div class="card-body"><form data-sales-ui class="js-sales-cycle-action" action="{{ route('admin.sales.sales-orders.production-requests.store', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" />
                @foreach($record->lines->where('product_classification_snapshot', 'finished_product') as $index => $line)<div class="mb-2"><input type="hidden" name="lines[{{ $index }}][sales_order_line_public_id]" value="{{ $line->public_id }}"><label class="form-label small">{{ $line->product?->name }} · {{ __('Unplanned') }} {{ max(0, bcsub($stockStatus->get($line->id)['shortage'] ?? '0', bcsub($line->production_requested_quantity, $line->produced_quantity, 8), 8)) }}</label><input class="form-control form-control-sm text-end" name="lines[{{ $index }}][quantity]" inputmode="decimal" required><small class="text-600">{{ collect($line->specifications ?? [])->filter()->map(fn($value, $key) => __(str($key)->replace('_', ' ')->title()->toString()).': '.$value)->join(' · ') }}</small></div>@endforeach
                <button class="btn btn-falcon-primary btn-sm" type="submit">{{ __('Create Work Order') }}</button>
            </form></div></div></div>
            @endcan
            @if(auth()->user()?->can('sales_orders.deliver') && auth()->user()?->can('sales_deliveries.create'))
            <div class="col-xl-4"><div class="card h-100"><div class="card-header"><h6 class="mb-0">{{ __('Create Partial Delivery') }}</h6></div><div class="card-body"><form data-sales-ui class="js-sales-cycle-action" action="{{ route('admin.sales.sales-orders.deliveries.store', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" />
                <label class="form-label">{{ __('Date') }}</label><input class="form-control js-date-picker mb-2" name="document_date" value="{{ $today }}"><div class="row g-2 mb-2"><div class="col-6"><input class="form-control form-control-sm" name="recipient_name" placeholder="{{ __('Recipient') }}"></div><div class="col-6"><input class="form-control form-control-sm" name="vehicle_number" placeholder="{{ __('Vehicle') }}"></div></div>
                @foreach($record->lines->reject->isService() as $index => $line)<div class="mb-2"><input type="hidden" name="lines[{{ $index }}][sales_order_line_public_id]" value="{{ $line->public_id }}"><label class="form-label small">{{ $line->product?->name }} · {{ __('Remaining') }} {{ $numbers->format($line->remainingDeliveryQuantity()) }} {{ $line->unit?->name }}</label><input class="form-control form-control-sm text-end" name="lines[{{ $index }}][quantity]" inputmode="decimal" required></div>@endforeach
                <button class="btn btn-falcon-primary btn-sm" type="submit">{{ __('Post Delivery') }}</button>
            </form></div></div></div>
            @endif
        </div>
        @endif

        @if(auth()->user()?->can('sales_orders.invoice') && auth()->user()?->can('customer_invoices.create'))
        <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Create Sales Invoice from delivered quantities / services') }}</h6></div><div class="card-body"><form data-sales-ui class="js-sales-cycle-action" action="{{ route('admin.sales.sales-orders.invoices.store', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" />
            <div class="table-responsive mb-3"><table class="table table-sm table-bordered align-middle mb-0" style="min-width:900px"><thead><tr><th>{{ __('Source') }}</th><th>{{ __('Product') }}</th><th>{{ __('Eligible quantity') }}</th><th>{{ __('Invoice quantity') }}</th></tr></thead><tbody>
                @php $invoiceIndex = 0; @endphp
                @foreach($record->deliveries->where('status', 'posted') as $delivery) @foreach($delivery->lines as $deliveryLine)
                    @php
                        $orderLine = $record->lines->firstWhere('id', $deliveryLine->source_line_id);
                        $alreadyInvoiced = $record->invoices->flatMap->lines->where('delivery_line_id', $deliveryLine->id)->sum('quantity');
                        $eligibleQuantity = bcsub((string) $deliveryLine->transaction_quantity, (string) $alreadyInvoiced, 8);
                    @endphp
                    @if($orderLine && bccomp($eligibleQuantity, '0', 8) > 0)<tr><td>{{ $delivery->doc_num }}<input type="hidden" name="lines[{{ $invoiceIndex }}][delivery_line_public_id]" value="{{ $deliveryLine->public_id }}"><input type="hidden" name="lines[{{ $invoiceIndex }}][sales_order_line_public_id]" value="{{ $orderLine->public_id }}"></td><td>{{ $orderLine->product?->name }}</td><td>{{ $eligibleQuantity }} {{ $orderLine->unit?->name }}</td><td><input class="form-control form-control-sm text-end" name="lines[{{ $invoiceIndex }}][quantity]" value="{{ $eligibleQuantity }}" inputmode="decimal" required></td></tr>@php $invoiceIndex++; @endphp @endif
                @endforeach @endforeach
                @foreach($record->lines->filter->isService() as $serviceLine) @if(bccomp($serviceLine->remainingInvoiceQuantity(), '0', 8) > 0)<tr><td>{{ __('Service') }}<input type="hidden" name="lines[{{ $invoiceIndex }}][sales_order_line_public_id]" value="{{ $serviceLine->public_id }}"></td><td>{{ $serviceLine->product?->name }}</td><td>{{ $serviceLine->remainingInvoiceQuantity() }}</td><td><input class="form-control form-control-sm text-end" name="lines[{{ $invoiceIndex }}][quantity]" value="{{ $serviceLine->remainingInvoiceQuantity() }}" inputmode="decimal" required></td></tr>@php $invoiceIndex++; @endphp @endif @endforeach
            </tbody></table></div>
            <label class="form-label">{{ __('Invoice date') }}</label><input class="form-control js-date-picker mb-3" name="invoice_date" value="{{ $today }}"><h6>{{ __('Invoice installments') }}</h6><div class="row g-2 mb-3">@for($i=0;$i<3;$i++)<div class="col-md-4"><div class="input-group input-group-sm"><input class="form-control" name="payment_schedules[{{ $i }}][due_date]" value="{{ now()->addMonths($i)->toDateString() }}" required><input class="form-control text-end" name="payment_schedules[{{ $i }}][amount]" placeholder="{{ __('Amount') }}" inputmode="decimal" required></div></div>@endfor</div>
            <button class="btn btn-primary btn-sm" type="submit">{{ __('Create Draft Invoice') }}</button>
        </form></div></div>
        @endif
    @endif
@elseif($kind === 'production_request')
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">{{ __('Canonical manufacturing execution') }}</h6>
            @can('production.work_orders.view')
                <a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.production.runs.index', ['production_order' => $record->doc_num]) }}">{{ __('Plan or review runs') }}</a>
            @endcan
        </div>
        <div class="card-body">
            <p class="text-700 mb-3">{{ __('Finished goods can only be received from a production run after material accountability, WIP costing, and a passed final quality inspection.') }}</p>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead><tr><th>{{ __('Run') }}</th><th>{{ __('Product') }}</th><th>{{ __('Status') }}</th><th class="text-end">{{ __('Planned') }}</th><th class="text-end">{{ __('Received') }}</th></tr></thead>
                    <tbody>
                        @forelse($record->runs as $run)
                            <tr><td><a href="{{ route('admin.production.runs.show', $run) }}">{{ $run->doc_num }}</a></td><td>{{ $run->product?->name }}</td><td>{{ __(str($run->status)->replace('_', ' ')->title()->toString()) }}</td><td class="text-end">{{ $run->planned_base_quantity }}</td><td class="text-end">{{ $run->received_base_quantity }}</td></tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-600">{{ __('No production runs have been planned yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@elseif(in_array($kind, ['invoice', 'credit_note'], true))
    <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Invoice actions') }}</h6></div><div class="card-body"><div class="d-flex flex-wrap gap-2 mb-3">
        @if($kind === 'invoice' && $record->isEditable()) @can('customer_invoices.edit')<a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.sales.sales-invoices.edit', $record) }}">{{ __('Edit Correction') }}</a>@endcan @endif
        @if($kind === 'invoice' && $record->posting_status !== 'posted') @can('customer_invoices.post')<form data-sales-ui class="js-sales-cycle-action" action="{{ route('admin.sales.sales-invoices.post', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><button class="btn btn-success btn-sm" type="submit">{{ __('Post Invoice') }}</button></form>@endcan @endif
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.sales-invoices.payment-schedule.print', $record) }}">{{ __('Print Payment Schedule') }}</a>
        @if($kind === 'invoice' && $record->posting_status === 'posted') @can('customer_receipts.create')<a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.sales.customer-receipts.create', ['invoice' => $record->doc_num]) }}">{{ __('Record Collection') }}</a>@endcan @endif
    </div>
    @if($kind === 'invoice' && $record->posting_status === 'posted')
        @can('customer_invoices.reopen')<form data-sales-ui class="js-sales-cycle-action border rounded p-3 mb-3" action="{{ route('admin.sales.sales-invoices.reopen', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><label class="form-label">{{ __('Correction reason') }}</label><textarea class="form-control form-control-sm mb-2" name="reason" required></textarea><button class="btn btn-warning btn-sm" type="submit">{{ __('Reverse and Reopen') }}</button><small class="d-block text-600 mt-2">{{ __('Allowed only while the invoice has no receipts or credit notes. The original journal is reversed before amendment.') }}</small></form>@endcan
        @can('sales_returns.create')<form data-sales-ui class="js-sales-cycle-action border rounded p-3" action="{{ route('admin.sales.sales-returns.store', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><div class="row g-2 mb-2"><div class="col-md-4"><label class="form-label">{{ __('Return reason') }}</label><select class="form-select form-select-sm" name="reason_code"><option value="customer_rejection">{{ __('Customer rejection') }}</option><option value="manufacturing_defect">{{ __('Manufacturing defect') }}</option><option value="damaged_goods">{{ __('Damaged goods') }}</option><option value="wrong_specification">{{ __('Wrong specification') }}</option><option value="other">{{ __('Other') }}</option></select></div><div class="col-md-8"><label class="form-label">{{ __('Details') }}</label><input class="form-control form-control-sm" name="reason_details"></div></div>
            @foreach($record->lines as $index => $line)
                @php
                    $previouslyReturned = $line->returnLines->reject(fn($returnLine) => $returnLine->salesReturn?->status === 'cancelled')->sum('quantity');
                    $returnable = bcsub((string) $line->quantity, (string) $previouslyReturned, 8);
                @endphp
                @if(bccomp($returnable, '0', 8) > 0)<div class="row g-2 align-items-center mb-2" data-sales-return-line><div class="col-auto"><input class="form-check-input" type="checkbox" data-sales-return-toggle></div><div class="col-md-7">{{ $line->product?->name }} · {{ __('Sold') }} {{ $numbers->format($line->quantity) }} · {{ __('Previously returned') }} {{ $numbers->format($previouslyReturned) }} · {{ __('Returnable') }} {{ $numbers->format($returnable) }} {{ $line->unit?->name }}@if($line->deliveryLine?->document)<br><small>{{ __('Delivery') }}: {{ $line->deliveryLine->document->doc_num }}</small>@endif<input type="hidden" name="lines[{{ $index }}][invoice_line_public_id]" value="{{ $line->public_id }}" disabled></div><div class="col-md-4"><input class="form-control form-control-sm text-end" name="lines[{{ $index }}][quantity]" inputmode="decimal" max="{{ $returnable }}" disabled required></div></div>@endif
            @endforeach
            <button class="btn btn-falcon-primary btn-sm" type="submit">{{ __('Create Return Request') }}</button>
        </form>@endcan
    @endif
    </div></div>
@elseif($kind === 'sales_return')
    <div class="card mb-3"><div class="card-header d-flex justify-content-between"><h6 class="mb-0">{{ __('Return and quality actions') }}</h6><a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.sales-returns.quality-disposition.print', $record) }}">{{ __('Print Quality Disposition') }}</a></div><div class="card-body">
        <div class="d-flex flex-wrap gap-2 mb-3">
            @if($record->status === 'pending_authorization') @can('sales_returns.authorize')<form data-sales-ui class="js-sales-cycle-action" action="{{ route('admin.sales.sales-returns.authorize', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><button class="btn btn-success btn-sm" type="submit">{{ __('Authorize Return') }}</button></form>@endcan @endif
            @if($record->status === 'authorized') @can('sales_returns.receive')<form data-sales-ui class="js-sales-cycle-action" action="{{ route('admin.sales.sales-returns.receive', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><button class="btn btn-primary btn-sm" type="submit">{{ __('Receive for Quality Inspection') }}</button></form>@endcan @endif
            @if($record->status === 'inspected' || ($record->status === 'authorized' && $record->lines->every->is_service)) @can('sales_returns.close')<form data-sales-ui class="js-sales-cycle-action" action="{{ route('admin.sales.sales-returns.close', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><button class="btn btn-falcon-primary btn-sm" type="submit">{{ __('Close and Post Credit Note') }}</button></form>@endcan @endif
        </div>
        @if($record->status === 'received') @can('sales_returns.inspect')<form data-sales-ui class="js-sales-cycle-action" action="{{ route('admin.sales.sales-returns.inspect', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><div class="table-responsive"><table class="table table-sm table-bordered align-middle" style="min-width:1000px"><thead><tr><th>{{ __('Product') }}</th><th>{{ __('Returned') }}</th><th>{{ __('Saleable') }}</th><th>{{ __('Quarantine') }}</th><th>{{ __('Rework') }}</th><th>{{ __('Scrap') }}</th><th>{{ __('Notes') }}</th></tr></thead><tbody>@foreach($record->lines->where('is_service', false) as $index => $line)<tr><td>{{ $line->product?->name }}<input type="hidden" name="results[{{ $index }}][sales_return_line_public_id]" value="{{ $line->public_id }}"></td><td>{{ $numbers->format($line->quantity) }} {{ $line->unit?->name }}</td>@foreach(['saleable','quarantine','rework','scrap'] as $bucket)<td><input class="form-control form-control-sm text-end" name="results[{{ $index }}][{{ $bucket }}_quantity]" value="0" inputmode="decimal"></td>@endforeach<td><input class="form-control form-control-sm" name="results[{{ $index }}][notes]"></td></tr>@endforeach</tbody></table></div><button class="btn btn-success btn-sm" type="submit">{{ __('Post Quality Disposition') }}</button></form>@endcan @endif
    </div></div>
@endif

@if($kind === 'sales_delivery' && $record->status === 'posted')
@can('sales_returns.create')
<div class="card mb-3"><div class="card-header"><h6>{{ __('Return unbilled delivery quantities') }}</h6></div><div class="card-body">
<form method="POST" action="{{ route('admin.sales.delivery-notes.returns.store', $record) }}" class="js-sales-cycle-action">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" />
<div class="row g-3 mb-3"><div class="col-md-6"><label class="form-label">{{ __('Return Reason') }}</label><select name="reason_code" class="form-select" required>@foreach(['excess_quantity', 'order_entry_mistake', 'wrong_item', 'wrong_specification', 'manufacturing_defect', 'damaged_goods', 'production_defect', 'customer_rejection', 'other'] as $reason)<option value="{{ $reason }}">{{ __(str($reason)->replace('_', ' ')->title()->toString()) }}</option>@endforeach</select></div><div class="col-md-6"><label class="form-label">{{ __('Details') }}</label><textarea name="reason_details" class="form-control"></textarea></div></div>
<table class="table"><thead><tr><th>{{ __('Item') }}</th><th>{{ __('Quantity') }}</th></tr></thead><tbody>
@foreach($record->lines as $line)<tr><td>{{ $line->product?->name }}<input type="hidden" name="lines[{{ $loop->index }}][delivery_line_public_id]" value="{{ $line->public_id }}"></td><td><input type="number" step="any" min="0" max="{{ $line->transaction_quantity }}" value="0" class="form-control" name="lines[{{ $loop->index }}][quantity]"></td></tr>@endforeach
</tbody></table><button type="submit" class="btn btn-primary">{{ __('Create Return') }}</button></form></div></div>
@endcan
@endif

@if($kind === 'customer_receipt' && $record->status === 'approved' && (!$record->cheque || in_array($record->cheque->status, ['received', 'deposited'], true)))
    @can('customer_receipts.cancel')
        <form data-sales-ui class="js-sales-cycle-action border rounded p-3 my-3" action="{{ route('admin.sales.customer-receipts.reverse', $record) }}" method="POST">
            @csrf
            <label class="form-label">{{ __('Reversal reason') }}</label>
            <textarea class="form-control mb-2" name="reason" required></textarea>
            <button class="btn btn-warning btn-sm" type="submit">{{ __('Reverse Collection') }}</button>
        </form>
    @endcan
@endif

@if($kind === 'sales_return' && in_array($record->status, ['pending_authorization', 'authorized'], true))
@can('sales_returns.cancel')<form data-sales-ui class="js-sales-cycle-action border rounded p-3 my-3" method="POST" action="{{ route('admin.sales.sales-returns.cancel', $record) }}">@csrf<label class="form-label">{{ __('Cancellation reason') }}</label><textarea class="form-control mb-2" name="reason" required></textarea><button class="btn btn-warning btn-sm" type="submit">{{ __('Cancel Return') }}</button></form>@endcan
@endif

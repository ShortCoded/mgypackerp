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

    @php
        $canReopenOrder = $record->canReopenSafely();
        $canCancelOrder = $record->canCancelSafely();
        $hasOrderActions = ($record->isEditable() && auth()->user()?->can('sales_orders.edit'))
            || (in_array($record->status, ['draft', 'pending_approval', 'held_credit'], true) && auth()->user()?->can('sales_orders.approve'))
            || ($record->status === 'held_credit' && auth()->user()?->can('sales_orders.credit_override'))
            || (in_array($record->status, ['draft', 'pending_approval', 'held_credit'], true) && auth()->user()?->can('sales_orders.reject'))
            || ($canReopenOrder && auth()->user()?->can('sales_orders.reopen'))
            || ($canCancelOrder && auth()->user()?->can('sales_orders.cancel'));
    @endphp
    @if($hasOrderActions)
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
                @if($canReopenOrder) @can('sales_orders.reopen')<div class="col-lg-4"><form data-sales-ui class="js-sales-cycle-action border rounded p-3" action="{{ route('admin.sales.sales-orders.reopen', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><label class="form-label">{{ __('Reopen reason') }}</label><textarea class="form-control form-control-sm mb-2" name="reason" required></textarea><button class="btn btn-falcon-warning btn-sm" type="submit">{{ __('Reopen for Amendment') }}</button></form></div>@endcan @endif
                @if($canCancelOrder) @can('sales_orders.cancel')<div class="col-lg-4"><form data-sales-ui class="js-sales-cycle-action border rounded p-3" action="{{ route('admin.sales.sales-orders.cancel', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><label class="form-label">{{ __('Cancellation reason') }}</label><textarea class="form-control form-control-sm mb-2" name="reason" required></textarea><button class="btn btn-outline-danger btn-sm" type="submit">{{ __('Cancel') }}</button></form></div>@endcan @endif
            </div>
        </div>
    </div>
    @endif

    @if($record->isApprovedForFulfillment() || $record->status === 'fulfilled')
        @php
            $invoiceableLines = $record->lines->filter(fn ($line) => bccomp($line->remainingInvoiceQuantity(), '0', 8) > 0);
            $invoiceableTotal = $invoiceableLines->reduce(
                fn (string $total, $line): string => bcadd($total, bcmul((string) $line->line_total, bcdiv($line->remainingInvoiceQuantity(), (string) $line->quantity, 12), 4), 4),
                '0.0000',
            );
        @endphp
        @if($invoiceableLines->isNotEmpty() && auth()->user()?->can('sales_orders.invoice') && auth()->user()?->can('customer_invoices.create'))
        <div class="card mb-3" id="sales-order-invoice"><div class="card-header"><h6 class="mb-0">{{ __('Create Sales Invoice from approved order quantities') }}</h6></div><div class="card-body"><form data-sales-ui data-sales-invoice-from-order class="js-sales-cycle-action" action="{{ route('admin.sales.sales-orders.invoices.store', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" />
            <div class="table-responsive mb-3"><table class="table table-sm table-bordered align-middle mb-0"><thead><tr><th>{{ __('Product') }}</th><th>{{ __('Ordered') }}</th><th>{{ __('Previously invoiced') }}</th><th>{{ __('Invoice quantity') }}</th></tr></thead><tbody>
                @foreach($invoiceableLines->values() as $invoiceIndex => $orderLine)
                    <tr data-invoice-source-line data-source-quantity="{{ $orderLine->quantity }}" data-source-total="{{ $orderLine->line_total }}"><td>{{ $orderLine->product?->name }}<input type="hidden" name="lines[{{ $invoiceIndex }}][sales_order_line_public_id]" value="{{ $orderLine->public_id }}"></td><td>{{ $numbers->format($orderLine->quantity) }} {{ $orderLine->unit?->name }}</td><td>{{ $numbers->format($orderLine->invoiced_quantity) }}</td><td><input class="form-control form-control-sm text-end js-invoice-quantity" name="lines[{{ $invoiceIndex }}][quantity]" value="{{ $numbers->formatForInput($orderLine->remainingInvoiceQuantity()) }}" inputmode="decimal" max="{{ $orderLine->remainingInvoiceQuantity() }}" required></td></tr>
                @endforeach
            </tbody></table></div>
            <div class="row g-3 mb-3"><div class="col-md-4"><label class="form-label">{{ __('Invoice date') }}</label><input class="form-control js-date-picker" name="invoice_date" value="{{ $dates->formatDate($today, '') }}" required></div><div class="col-md-4"><label class="form-label">{{ __('Due date') }}</label><input class="form-control js-date-picker" name="payment_schedules[0][due_date]" value="{{ $dates->formatDate($today, '') }}" required></div><div class="col-md-4"><label class="form-label">{{ __('Invoice total') }}</label><input class="form-control text-end" data-invoice-schedule-total name="payment_schedules[0][amount]" value="{{ $numbers->formatForInput($invoiceableTotal) }}" inputmode="decimal" required readonly></div></div>
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
    @if($kind === 'invoice')
    <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Invoice actions') }}</h6></div><div class="card-body"><div class="d-flex flex-wrap gap-2 mb-3">
        @if($kind === 'invoice' && $record->isEditable()) @can('customer_invoices.edit')<a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.sales.sales-invoices.edit', $record) }}">{{ __('Edit Correction') }}</a>@endcan @endif
        @if($kind === 'invoice' && $record->posting_status !== 'posted') @can('customer_invoices.post')<form data-sales-ui class="js-sales-cycle-action" action="{{ route('admin.sales.sales-invoices.post', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><button class="btn btn-success btn-sm" type="submit">{{ __('Post Invoice') }}</button></form>@endcan @endif
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.sales-invoices.payment-schedule.print', $record) }}">{{ __('Print Payment Schedule') }}</a>
        @if($record->posting_status === 'posted' && bccomp((string) $record->remaining_amount, '0', 4) > 0) @can('customer_receipts.create')<a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.sales.customer-receipts.create', ['invoice' => $record->doc_num]) }}">{{ __('Record Collection') }}</a>@endcan @endif
    </div>
    @if($kind === 'invoice' && $record->posting_status === 'posted')
        @php
            $invoiceDeliveryLines = $record->lines->reject(fn ($line) => $line->is_service)->map(function ($line) use ($record) {
                $deliverySourceType = $line->sales_order_line_id ? \Modules\Sales\Models\SalesOrderLine::class : \Modules\Sales\Models\CustomerInvoiceLine::class;
                $deliverySourceId = $line->sales_order_line_id ?: $line->getKey();
                $delivered = $record->deliveries->flatMap->lines
                    ->where('source_line_type', $deliverySourceType)
                    ->where('source_line_id', $deliverySourceId)
                    ->sum('transaction_quantity');
                $remaining = bcsub((string) $line->quantity, (string) $delivered, 8);

                return ['line' => $line, 'delivered' => $delivered, 'remaining' => bccomp($remaining, '0', 8) > 0 ? $remaining : '0.00000000'];
            })->filter(fn ($row) => bccomp($row['remaining'], '0', 8) > 0);
        @endphp
        @if($invoiceDeliveryLines->isNotEmpty() && auth()->user()?->can('sales_deliveries.create'))
            <form id="sales-invoice-delivery" data-sales-ui class="js-sales-cycle-action border rounded p-3 mb-3" action="{{ route('admin.sales.sales-invoices.deliveries.store', $record) }}" method="POST">
                @csrf
                <x-forms.line-item-cards :line-label="__('sales_ui.line')" />
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h6 class="mb-1">{{ __('sales_ui.create_delivery') }}</h6><small class="text-600">{{ __('sales_ui.delivery_from_invoice_help') }}</small></div></div>
                <div class="row g-2 mb-3">
                    <div class="col-md-4"><x-forms.label for="delivery_branch_store_uuid" :label="__('Delivery warehouse')" required /><select class="form-select js-select2-ajax" id="delivery_branch_store_uuid" name="branch_store_uuid" data-url="{{ route('admin.sales.select2.stores') }}" data-placeholder="{{ __('Select store') }}" required></select></div>
                    <div class="col-md-2"><x-forms.label for="delivery_document_date" :label="__('Date')" required /><input class="form-control js-date-picker" id="delivery_document_date" name="document_date" value="{{ $dates->formatDate($today, '') }}" required></div>
                    <div class="col-md-3"><x-forms.label for="delivery_recipient_name" :label="__('Recipient')" /><input class="form-control" id="delivery_recipient_name" name="recipient_name"></div>
                    <div class="col-md-3"><x-forms.label for="delivery_recipient_phone" :label="__('Recipient phone')" /><input class="form-control" id="delivery_recipient_phone" name="recipient_phone"></div>
                    <div class="col-md-3"><x-forms.label for="delivery_vehicle_number" :label="__('Vehicle')" /><input class="form-control" id="delivery_vehicle_number" name="vehicle_number"></div>
                    <div class="col-md-3"><x-forms.label for="delivery_driver_name" :label="__('Driver')" /><input class="form-control" id="delivery_driver_name" name="driver_name"></div>
                    <div class="col-md-6"><x-forms.label for="delivery_notes" :label="__('Notes')" /><input class="form-control" id="delivery_notes" name="notes"></div>
                </div>
                <div class="table-responsive mb-3"><table class="table table-sm table-bordered align-middle mb-0"><thead><tr><th>{{ __('Product') }}</th><th class="text-end">{{ __('Invoiced') }}</th><th class="text-end">{{ __('Previously delivered') }}</th><th>{{ __('Deliver now') }}</th></tr></thead><tbody>
                    @foreach($invoiceDeliveryLines as $index => $row)<tr><td>{{ $row['line']->product?->doc_num }} / {{ $row['line']->product?->name }}<input type="hidden" name="lines[{{ $index }}][invoice_line_public_id]" value="{{ $row['line']->public_id }}"></td><td class="text-end">{{ $numbers->format($row['line']->quantity) }} {{ $row['line']->unit?->name }}</td><td class="text-end">{{ $numbers->format($row['delivered']) }}</td><td><input class="form-control form-control-sm text-end" name="lines[{{ $index }}][quantity]" value="{{ $numbers->formatForInput($row['remaining']) }}" max="{{ $row['remaining'] }}" inputmode="decimal" required></td></tr>@endforeach
                </tbody></table></div>
                <button class="btn btn-falcon-primary btn-sm" type="submit">{{ __('Post Delivery') }}</button>
            </form>
        @endif
        @if($record->canReopenSafely()) @can('customer_invoices.reopen')<form data-sales-ui class="js-sales-cycle-action border rounded p-3 mb-3" action="{{ route('admin.sales.sales-invoices.reopen', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><label class="form-label">{{ __('Correction reason') }}</label><textarea class="form-control form-control-sm mb-2" name="reason" required></textarea><button class="btn btn-warning btn-sm" type="submit">{{ __('Reverse and Reopen') }}</button><small class="d-block text-600 mt-2">{{ __('Allowed only while the invoice has no receipts or credit notes. The original journal is reversed before amendment.') }}</small></form>@endcan @endif
        @can('sales_returns.create')<form id="sales-invoice-return" data-sales-ui class="js-sales-cycle-action border rounded p-3" action="{{ route('admin.sales.sales-returns.store', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><h6 class="mb-3">{{ __('sales_ui.create_return_from_invoice') }}</h6><div class="row g-2 mb-2"><div class="col-md-4"><label class="form-label">{{ __('Return reason') }}</label><select class="form-select form-select-sm" name="reason_code"><option value="customer_rejection">{{ __('Customer rejection') }}</option><option value="manufacturing_defect">{{ __('Manufacturing defect') }}</option><option value="damaged_goods">{{ __('Damaged goods') }}</option><option value="wrong_specification">{{ __('Wrong specification') }}</option><option value="other">{{ __('Other') }}</option></select></div><div class="col-md-8"><label class="form-label">{{ __('Details') }}</label><input class="form-control form-control-sm" name="reason_details"></div></div>
            @if($record->lines->contains(fn ($line) => ! $line->is_service))<div class="mb-3"><x-forms.label for="return_branch_store_uuid" :label="__('Return warehouse')" required /><select class="form-select js-select2-ajax" id="return_branch_store_uuid" name="branch_store_uuid" data-url="{{ route('admin.sales.select2.stores') }}" data-placeholder="{{ __('Select store') }}" required></select></div>@endif
            @foreach($record->lines as $index => $line)
                @php
                    $previouslyReturned = $line->returnLines->reject(fn($returnLine) => $returnLine->salesReturn?->status === 'cancelled')->sum('quantity');
                    $deliverySourceType = $line->sales_order_line_id ? \Modules\Sales\Models\SalesOrderLine::class : \Modules\Sales\Models\CustomerInvoiceLine::class;
                    $deliverySourceId = $line->sales_order_line_id ?: $line->getKey();
                    $delivered = $line->is_service ? $line->quantity : $record->deliveries->flatMap->lines
                        ->where('source_line_type', $deliverySourceType)
                        ->where('source_line_id', $deliverySourceId)
                        ->sum('transaction_quantity');
                    $returnable = bcsub(bccomp((string) $delivered, (string) $line->quantity, 8) > 0 ? (string) $line->quantity : (string) $delivered, (string) $previouslyReturned, 8);
                @endphp
                @if(bccomp($returnable, '0', 8) > 0)<div class="row g-2 align-items-center mb-2" data-sales-return-line><div class="col-auto"><input class="form-check-input" type="checkbox" data-sales-return-toggle></div><div class="col-md-7">{{ $line->product?->name }} · {{ __('Sold') }} {{ $numbers->format($line->quantity) }} · {{ __('Previously returned') }} {{ $numbers->format($previouslyReturned) }} · {{ __('Returnable') }} {{ $numbers->format($returnable) }} {{ $line->unit?->name }}@if($line->deliveryLine?->document)<br><small>{{ __('Delivery') }}: {{ $line->deliveryLine->document->doc_num }}</small>@endif<input type="hidden" name="lines[{{ $index }}][invoice_line_public_id]" value="{{ $line->public_id }}" disabled></div><div class="col-md-4"><input class="form-control form-control-sm text-end" name="lines[{{ $index }}][quantity]" inputmode="decimal" max="{{ $returnable }}" disabled required></div></div>@endif
            @endforeach
            <button class="btn btn-falcon-primary btn-sm" type="submit">{{ __('Create Return Request') }}</button>
        </form>@endcan
    @endif
    </div></div>
    @endif
@elseif($kind === 'sales_return')
    @if(in_array($record->status, ['pending_authorization', 'authorized', 'received', 'inspected'], true))
    <div class="card mb-3"><div class="card-header py-2"><h6 class="mb-0">{{ __('Return and quality actions') }}</h6></div><div class="card-body py-3">
        <div class="d-flex flex-wrap gap-2 mb-3">
            @if($record->status === 'pending_authorization') @can('sales_returns.authorize')<form data-sales-ui class="js-sales-cycle-action" action="{{ route('admin.sales.sales-returns.authorize', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><button class="btn btn-success btn-sm" type="submit">{{ __('Authorize Return') }}</button></form>@endcan @endif
            @if($record->status === 'authorized') @can('sales_returns.receive')<form data-sales-ui class="js-sales-cycle-action" action="{{ route('admin.sales.sales-returns.receive', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><button class="btn btn-primary btn-sm" type="submit">{{ __('Receive for Quality Inspection') }}</button></form>@endcan @endif
            @if($record->status === 'inspected' || ($record->status === 'authorized' && $record->lines->every->is_service)) @can('sales_returns.close')<form data-sales-ui class="js-sales-cycle-action" action="{{ route('admin.sales.sales-returns.close', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><button class="btn btn-falcon-primary btn-sm" type="submit">{{ __('Close and Post Credit Note') }}</button></form>@endcan @endif
        </div>
        @if($record->status === 'received') @can('sales_returns.inspect')<form data-sales-ui class="js-sales-cycle-action" action="{{ route('admin.sales.sales-returns.inspect', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><div class="table-responsive"><table class="table table-sm table-bordered align-middle" style="min-width:1000px"><thead><tr><th>{{ __('Product') }}</th><th>{{ __('Returned') }}</th><th>{{ __('Saleable') }}</th><th>{{ __('Quarantine') }}</th><th>{{ __('Rework') }}</th><th>{{ __('Scrap') }}</th><th>{{ __('Notes') }}</th></tr></thead><tbody>@foreach($record->lines->where('is_service', false) as $index => $line)<tr><td>{{ $line->product?->name }}<input type="hidden" name="results[{{ $index }}][sales_return_line_public_id]" value="{{ $line->public_id }}"></td><td>{{ $numbers->format($line->quantity) }} {{ $line->unit?->name }}</td>@foreach(['saleable','quarantine','rework','scrap'] as $bucket)<td><input class="form-control form-control-sm text-end" name="results[{{ $index }}][{{ $bucket }}_quantity]" value="0" inputmode="decimal"></td>@endforeach<td><input class="form-control form-control-sm" name="results[{{ $index }}][notes]"></td></tr>@endforeach</tbody></table></div><button class="btn btn-success btn-sm" type="submit">{{ __('Post Quality Disposition') }}</button></form>@endcan @endif
    </div></div>
    @endif
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

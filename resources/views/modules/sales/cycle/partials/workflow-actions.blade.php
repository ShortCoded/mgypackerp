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
            <div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-0" style="min-width:1200px"><thead><tr><th>{{ __('Product') }}</th><th>{{ __('Unit') }}</th><th class="text-end">{{ __('Ordered') }}</th><th class="text-end">{{ __('sales_ui.remainder.declined') }}</th><th class="text-end">{{ __('sales_ui.remainder.effective') }}</th><th class="text-end">{{ __('On hand') }}</th><th class="text-end">{{ __('Available to this order') }}</th><th class="text-end">{{ __('Reserved') }}</th><th class="text-end">{{ __('Shortage') }}</th><th class="text-end">{{ __('Production requested') }}</th><th class="text-end">{{ __('Produced') }}</th><th class="text-end">{{ __('Delivered') }}</th><th class="text-end">{{ __('Remaining') }}</th></tr></thead><tbody>
                @foreach($record->lines->reject->isService() as $line)
                    @php $stock = $stockStatus->get($line->getKey()); @endphp
                    <tr><td>{{ $line->product?->doc_num }} / {{ $line->product?->name }}</td><td>{{ $line->unit?->name }}</td><td class="text-end">{{ $numbers->format($line->quantity) }}</td><td class="text-end">{{ $numbers->format($line->declined_quantity) }}</td><td class="text-end">{{ $numbers->format($line->effectiveQuantity()) }}</td><td class="text-end">{{ $numbers->format($stock['on_hand']) }}</td><td class="text-end">{{ $numbers->format($stock['available']) }}</td><td class="text-end">{{ $numbers->format($stock['reserved']) }}</td><td class="text-end">{{ $numbers->format($stock['shortage']) }}</td><td class="text-end">{{ $numbers->format($line->production_requested_quantity) }}</td><td class="text-end">{{ $numbers->format($line->produced_quantity) }}</td><td class="text-end">{{ $numbers->format($line->delivered_quantity) }}</td><td class="text-end">{{ $numbers->format($line->remainingDeliveryQuantity()) }}</td></tr>
                @endforeach
            </tbody></table></div>
        </div>
    @endif

    @php
        $reviewableOrderStatuses = ['approved', 'rejected', 'closed', 'partially_fulfilled', 'fulfilled'];
        $canReviewReopenOrder = in_array($record->status, $reviewableOrderStatuses, true);
        $canCancelOrder = $record->canCancelSafely();
        $closeableRemainderLines = $record->lines
            ->reject->isService()
            ->filter(fn ($line) => bccomp($line->remainingDeliveryQuantity(), '0', 8) > 0)
            ->values();
        $canCloseRemainder = in_array($record->status, ['approved', 'partially_fulfilled', 'fulfilled'], true)
            && $closeableRemainderLines->isNotEmpty()
            && auth()->user()?->can('sales_orders.close_remainder');
        $hasOrderActions = ($record->isEditable() && auth()->user()?->can('sales_orders.edit'))
            || (in_array($record->status, ['draft', 'pending_approval', 'held_credit'], true) && auth()->user()?->can('sales_orders.approve'))
            || ($record->status === 'held_credit' && auth()->user()?->can('sales_orders.credit_override'))
            || (in_array($record->status, ['draft', 'pending_approval', 'held_credit'], true) && auth()->user()?->can('sales_orders.reject'))
            || ($canReviewReopenOrder && auth()->user()?->can('sales_orders.reopen'))
            || $canCloseRemainder
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
                @if($record->status === 'held_credit') @can('sales_orders.credit_override')<div class="col-lg-4"><form data-sales-ui class="js-sales-cycle-action border rounded p-3" action="{{ route('admin.sales.sales-orders.credit-override', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><label class="form-label">{{ __('Credit override reason') }}</label><x-forms.textarea class="form-control form-control-sm mb-2" name="reason" required></x-forms.textarea><button class="btn btn-warning btn-sm" type="submit">{{ __('Override Hold') }}</button></form></div>@endcan @endif
                @if(in_array($record->status, ['draft', 'pending_approval', 'held_credit'], true)) @can('sales_orders.reject')<div class="col-lg-4"><form data-sales-ui class="js-sales-cycle-action border rounded p-3" action="{{ route('admin.sales.sales-orders.reject', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><label class="form-label">{{ __('Rejection reason') }}</label><x-forms.textarea class="form-control form-control-sm mb-2" name="reason" required></x-forms.textarea><button class="btn btn-danger btn-sm" type="submit">{{ __('Reject') }}</button></form></div>@endcan @endif
                @if($canReviewReopenOrder) @can('sales_orders.reopen')<div class="col-lg-4"><a class="btn btn-falcon-warning btn-sm" href="{{ route('admin.tools.open-documents.index', ['document_type' => 'sales_orders', 'from_number' => $record->doc_number, 'to_number' => $record->doc_number]) }}">{{ __('open_documents.actions.review_edit_reopen') }}</a></div>@endcan @endif
                @if($canCancelOrder) @can('sales_orders.cancel')<div class="col-lg-4"><form data-sales-ui class="js-sales-cycle-action border rounded p-3" action="{{ route('admin.sales.sales-orders.cancel', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><label class="form-label">{{ __('Cancellation reason') }}</label><x-forms.textarea class="form-control form-control-sm mb-2" name="reason" required></x-forms.textarea><button class="btn btn-outline-danger btn-sm" type="submit">{{ __('Cancel') }}</button></form></div>@endcan @endif
                @if($canCloseRemainder)
                    @can('sales_orders.close_remainder')
                        <div class="col-12">
                            <form data-sales-ui class="js-sales-cycle-action border border-warning rounded p-3" action="{{ route('admin.sales.sales-orders.close-remainder', $record) }}" method="POST">
                                @csrf
                                <x-forms.line-item-cards :line-label="__('sales_ui.line')" />
                                <h6>{{ __('sales_ui.remainder.title') }}</h6>
                                <p class="small text-600">{{ __('sales_ui.remainder.description') }}</p>
                                <div class="table-responsive mb-3">
                                    <table class="table table-sm table-bordered align-middle mb-0">
                                        <thead><tr><th>{{ __('Product') }}</th><th>{{ __('Unit') }}</th><th class="text-end">{{ __('Ordered') }}</th><th class="text-end">{{ __('Delivered') }}</th><th class="text-end">{{ __('sales_ui.remainder.previously_declined') }}</th><th class="text-end">{{ __('sales_ui.remainder.effective_before_close') }}</th><th class="text-end">{{ __('sales_ui.remainder.quantity_to_decline') }}</th></tr></thead>
                                        <tbody>
                                            @foreach($closeableRemainderLines as $remainderIndex => $orderLine)
                                                <tr>
                                                    <td>{{ $orderLine->product?->doc_num }} / {{ $orderLine->product?->name }}</td>
                                                    <td>{{ $orderLine->unit?->name }}</td>
                                                    <td class="text-end" dir="ltr">{{ $numbers->format($orderLine->quantity) }}</td>
                                                    <td class="text-end" dir="ltr">{{ $numbers->format($orderLine->delivered_quantity) }}</td>
                                                    <td class="text-end" dir="ltr">{{ $numbers->format($orderLine->declined_quantity) }}</td>
                                                    <td class="text-end" dir="ltr">{{ $numbers->format($orderLine->effectiveQuantity()) }}</td>
                                                    <td class="text-end fw-semibold" dir="ltr">
                                                        {{ $numbers->format($orderLine->remainingDeliveryQuantity()) }}
                                                        <x-forms.input type="hidden" name="lines[{{ $remainderIndex }}][sales_order_line_public_id]" value="{{ $orderLine->public_id }}" />
                                                        <x-forms.input type="hidden" name="lines[{{ $remainderIndex }}][expected_remaining_quantity]" value="{{ $orderLine->remainingDeliveryQuantity() }}" />
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-3"><label class="form-label">{{ __('sales_ui.remainder.closure_date') }}</label><x-forms.date-input name="closure_date" :value="$today" required /></div>
                                    <div class="col-md-7"><label class="form-label">{{ __('sales_ui.remainder.decline_reason') }}</label><x-forms.textarea class="form-control form-control-sm" name="reason" required></x-forms.textarea></div>
                                    <div class="col-md-2"><button class="btn btn-warning btn-sm w-100" type="submit">{{ __('sales_ui.remainder.close_displayed') }}</button></div>
                                </div>
                            </form>
                        </div>
                    @endcan
                @endif
            </div>
        </div>
    </div>
    @endif

    @php
        $closedDeclinedOrderHasDeliveredToInvoice = $record->status === 'closed'
            && $record->lines->contains(fn ($line) => bccomp((string) $line->declined_quantity, '0', 8) > 0)
            && $record->lines->contains(fn ($line) => bccomp((string) $line->delivered_quantity, $line->netInvoicedQuantity(), 8) > 0);
    @endphp
    @if($record->isApprovedForFulfillment() || $record->status === 'fulfilled' || $closedDeclinedOrderHasDeliveredToInvoice)
        @php
            $invoiceableLines = $record->lines->filter(fn ($line) => bccomp($line->remainingInvoiceQuantity(), '0', 8) > 0);
            $invoiceRows = $invoiceableLines->map(fn ($line): array => [
                'order_line' => $line,
                'quantity' => $line->remainingInvoiceQuantity(),
                'delivery' => null,
                'delivery_line' => null,
            ])->values();
            if ($closedDeclinedOrderHasDeliveredToInvoice) {
                $invoicedByDeliveryLine = $record->invoices
                    ->where('document_type', 'invoice')
                    ->flatMap->lines
                    ->filter(fn ($invoiceLine) => $invoiceLine->delivery_line_id !== null)
                    ->groupBy('delivery_line_id')
                    ->map(fn ($invoiceLines): string => $invoiceLines->reduce(
                        fn (string $total, $invoiceLine): string => bcadd($total, (string) $invoiceLine->quantity, 8),
                        '0.00000000',
                    ));
                $remainingByOrderLine = $invoiceableLines->mapWithKeys(
                    fn ($line): array => [$line->getKey() => $line->remainingInvoiceQuantity()],
                );
                $invoiceRows = collect();
                foreach ($record->deliveries->where('document_type', 'sales_delivery')->where('status', 'posted') as $delivery) {
                    foreach ($delivery->lines as $deliveryLine) {
                        if ($deliveryLine->source_line_type !== \Modules\Sales\Models\SalesOrderLine::class) {
                            continue;
                        }
                        $orderLine = $invoiceableLines->firstWhere('id', $deliveryLine->source_line_id);
                        $orderRemaining = (string) $remainingByOrderLine->get($deliveryLine->source_line_id, '0.00000000');
                        $deliveryRemaining = bcsub(
                            (string) $deliveryLine->transaction_quantity,
                            (string) $invoicedByDeliveryLine->get($deliveryLine->getKey(), '0.00000000'),
                            8,
                        );
                        if ($orderLine === null || bccomp($orderRemaining, '0', 8) <= 0 || bccomp($deliveryRemaining, '0', 8) <= 0) {
                            continue;
                        }
                        $quantity = bccomp($orderRemaining, $deliveryRemaining, 8) <= 0 ? $orderRemaining : $deliveryRemaining;
                        $invoiceRows->push([
                            'order_line' => $orderLine,
                            'quantity' => $quantity,
                            'delivery' => $delivery,
                            'delivery_line' => $deliveryLine,
                        ]);
                        $remainingByOrderLine->put($orderLine->getKey(), bcsub($orderRemaining, $quantity, 8));
                    }
                }
            }
            $invoiceableTotal = $invoiceRows->reduce(
                fn (string $total, array $row): string => bcadd($total, bcmul((string) $row['order_line']->line_total, bcdiv($row['quantity'], (string) $row['order_line']->quantity, 12), 4), 4),
                '0.0000',
            );
        @endphp
        @if($invoiceRows->isNotEmpty() && auth()->user()?->can('sales_orders.invoice') && auth()->user()?->can('customer_invoices.create'))
        <div class="card mb-3" id="sales-order-invoice"><div class="card-header"><h6 class="mb-0">{{ __('sales_ui.remainder.create_invoice_title') }}</h6></div><div class="card-body"><form data-sales-ui data-sales-invoice-from-order class="js-sales-cycle-action" action="{{ route('admin.sales.sales-orders.invoices.store', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" />
            <div class="table-responsive mb-3"><table class="table table-sm table-bordered align-middle mb-0"><thead><tr><th>{{ __('Product') }}</th><th>{{ __('sales_ui.remainder.source_delivery') }}</th><th>{{ __('sales_ui.remainder.ordered_effective') }}</th><th>{{ __('sales_ui.remainder.invoiced_net') }}</th><th>{{ __('Invoice quantity') }}</th></tr></thead><tbody>
                @foreach($invoiceRows as $invoiceIndex => $invoiceRow)
                    @php $orderLine = $invoiceRow['order_line']; @endphp
                    <tr data-invoice-source-line data-source-quantity="{{ $orderLine->quantity }}" data-source-total="{{ $orderLine->line_total }}"><td>{{ $orderLine->product?->name }}<x-forms.input type="hidden" name="lines[{{ $invoiceIndex }}][sales_order_line_public_id]" value="{{ $orderLine->public_id }}" />@if($invoiceRow['delivery_line'])<x-forms.input type="hidden" name="lines[{{ $invoiceIndex }}][delivery_line_public_id]" value="{{ $invoiceRow['delivery_line']->public_id }}" />@endif</td><td>{{ $invoiceRow['delivery']?->doc_num ?? '—' }}</td><td>{{ $numbers->format($orderLine->quantity) }} / {{ $numbers->format($orderLine->effectiveQuantity()) }} {{ $orderLine->unit?->name }}</td><td>{{ $numbers->format($orderLine->invoiced_quantity) }} / {{ $numbers->format($orderLine->netInvoicedQuantity()) }}</td><td><x-forms.input class="form-control form-control-sm text-end js-invoice-quantity" name="lines[{{ $invoiceIndex }}][quantity]" value="{{ $numbers->formatForInput($invoiceRow['quantity']) }}" inputmode="decimal" max="{{ $invoiceRow['quantity'] }}" required /></td></tr>
                @endforeach
            </tbody></table></div>
            <div class="row g-3 mb-3"><div class="col-md-4"><label class="form-label">{{ __('Invoice date') }}</label><x-forms.date-input name="invoice_date" :value="$today" required /></div><div class="col-md-4"><label class="form-label">{{ __('Due date') }}</label><x-forms.date-input name="payment_schedules[0][due_date]" :value="$today" required /></div><div class="col-md-4"><label class="form-label">{{ __('Invoice total') }}</label><x-forms.input class="form-control text-end" data-invoice-schedule-total name="payment_schedules[0][amount]" value="{{ $numbers->formatForInput($invoiceableTotal) }}" inputmode="decimal" required readonly /></div></div>
            <button class="btn btn-primary btn-sm" type="submit">{{ __('Create Draft Invoice') }}</button>
        </form></div></div>
        @endif
    @endif
@elseif($kind === 'production_request')
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">{{ __('Canonical manufacturing execution') }}</h6>
            @can('production.orders.view')
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
                            <tr><td><a href="{{ route('admin.production.runs.show', $run) }}">{{ $run->doc_num }}</a></td><td>{{ $run->product?->name }}</td><td>{{ __(str($run->status)->replace('_', ' ')->title()->toString()) }}</td><td class="text-end">{{ $numbers->format($run->planned_base_quantity) }}</td><td class="text-end">{{ $numbers->format($run->received_base_quantity) }}</td></tr>
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
    <div class="card mb-3" data-invoice-actions>
        <div class="card-header py-2"><h6 class="mb-0">{{ __('Invoice actions') }}</h6></div>
        <div class="card-body py-3">
            <div class="d-flex flex-wrap gap-2">
                @if($record->isEditable()) @can('customer_invoices.edit')<a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.sales.sales-invoices.edit', $record) }}">{{ __('Edit Correction') }}</a>@endcan @endif
                @if($record->isEditable()) @can('customer_invoices.post')<form data-sales-ui class="js-sales-cycle-action" action="{{ route('admin.sales.sales-invoices.post', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><button class="btn btn-success btn-sm" type="submit">{{ __('Post Invoice') }}</button></form>@endcan @endif
                <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.sales-invoices.payment-schedule.print', $record) }}">{{ __('Print Payment Schedule') }}</a>
                @if($record->posting_status === 'posted' && bccomp((string) $record->remaining_amount, '0', 4) > 0) @can('customer_receipts.create')<a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.sales.customer-receipts.create', ['invoice' => $record->doc_num]) }}">{{ __('Record Collection') }}</a>@endcan @endif
            </div>
        </div>
    </div>
    @if($record->posting_status === 'posted')
        @if($record->issueOrder)
            <div class="card mb-3" id="sales-invoice-delivery">
                <div class="card-header py-2 fw-semibold">{{ __('sales_issue.issue_order') }} {{ $record->issueOrder->doc_num }}</div>
                <div class="card-body py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span>{{ __('sales_issue.'.$record->issueOrder->status) }}</span>
                    @can('sales_deliveries.view')<a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.sales.issue-orders.show', $record->issueOrder) }}">{{ __('sales_issue.view_order') }}</a>@endcan
                </div>
            </div>
        @endif
        @can('customer_invoices.reopen')
            <div class="card mb-3" data-invoice-action-panel><div class="card-body py-3"><a class="btn btn-falcon-warning btn-sm" href="{{ route('admin.tools.open-documents.index', ['document_type' => 'customer_invoices', 'from_number' => $record->doc_number, 'to_number' => $record->doc_number]) }}">{{ __('open_documents.actions.review_edit_reopen') }}</a></div></div>
        @endcan
        @can('customer_invoices.cancel')
            @if($record->canCancelDirectService())
                <details class="card mb-3" data-invoice-action-panel>
                    <summary class="card-header py-2 fw-semibold">{{ __('sales_ui.cancel_direct_service_invoice') }}</summary>
                    <form data-sales-ui class="js-sales-cycle-action card-body py-3 border-top" action="{{ route('admin.sales.sales-invoices.cancel-direct-service', $record) }}" method="POST">
                        @csrf
                        <x-forms.input type="hidden" name="requires_reason" value="1" />
                        <p class="small text-600">{{ __('sales_ui.direct_service_cancel_help') }}</p>
                        <x-forms.label for="direct-service-cancel-reason" :label="__('Cancellation reason')" required />
                        <x-forms.textarea id="direct-service-cancel-reason" class="form-control mb-2" name="reason" required maxlength="2000"></x-forms.textarea>
                        <button class="btn btn-warning btn-sm" type="submit">{{ __('sales_ui.cancel_direct_service_invoice') }}</button>
                    </form>
                </details>
            @endif
        @endcan
        @can('sales_returns.create')
            <details class="card mb-3" data-invoice-action-panel>
                <summary class="card-header py-2 fw-semibold">{{ __('sales_ui.create_return_from_invoice') }}</summary>
                <form id="sales-invoice-return" data-sales-ui class="js-sales-cycle-action card-body py-3 border-top" action="{{ route('admin.sales.sales-returns.store', $record) }}" method="POST">
                    @csrf
                    <x-forms.line-item-cards :line-label="__('sales_ui.line')" />
                    <p class="small text-600 mb-3">{{ __('sales_ui.return_items_help') }}</p>
                    <div class="row g-2 mb-3">
                        <div class="col-md-4"><x-forms.label for="invoice_return_reason" :label="__('Return reason')" required /><x-forms.select class="form-select-sm" id="invoice_return_reason" name="reason_code" variant="local" :allow-clear="false" required><option value="customer_rejection">{{ __('Customer rejection') }}</option><option value="manufacturing_defect">{{ __('Manufacturing defect') }}</option><option value="damaged_goods">{{ __('Damaged goods') }}</option><option value="wrong_specification">{{ __('Wrong specification') }}</option><option value="other">{{ __('Other') }}</option></x-forms.select></div>
                        <div class="col-md-8"><x-forms.label for="invoice_return_details" :label="__('Details')" /><x-forms.input class="form-control form-control-sm" id="invoice_return_details" name="reason_details" /></div>
                        @if($record->lines->contains(fn ($line) => ! $line->is_service))<div class="col-md-6"><x-forms.label for="return_branch_store_uuid" :label="__('Return warehouse')" required /><x-forms.select id="return_branch_store_uuid" name="branch_store_uuid" variant="ajax" :url="route('admin.sales.select2.stores')" :placeholder="__('Select store')" :allow-clear="false" required></x-forms.select></div>@endif
                    </div>
                    <fieldset>
                        <legend class="fs-10 fw-semibold mb-2">{{ __('sales_ui.return_items') }}</legend>
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
                                $returnToggleId = 'invoice_return_line_'.$index;
                                $returnQuantityId = 'invoice_return_quantity_'.$index;
                            @endphp
                            @if(bccomp($returnable, '0', 8) > 0)
                                <div class="border rounded-2 p-2 mb-2" data-sales-return-line>
                                    <div class="row g-2 align-items-center">
                                        <div class="col-md-8">
                                            <div class="form-check mb-1">
                                                <x-forms.input class="form-check-input" id="{{ $returnToggleId }}" type="checkbox" data-sales-return-toggle />
                                                <label class="form-check-label fw-semibold" for="{{ $returnToggleId }}">{{ __('sales_ui.select_item_for_return') }}: {{ $line->product?->doc_num }} / {{ $line->product?->name }}</label>
                                            </div>
                                            <div class="small text-600 ms-4">{{ __('Sold') }} {{ $numbers->format($line->quantity) }} · {{ __('Previously returned') }} {{ $numbers->format($previouslyReturned) }} · {{ __('Returnable') }} {{ $numbers->format($returnable) }} {{ $line->unit?->name }}</div>
                                            @if($line->deliveryLine?->document)<div class="small text-600 ms-4">{{ __('Delivery') }}: {{ $line->deliveryLine->document->doc_num }}</div>@endif
                                            <x-forms.input type="hidden" name="lines[{{ $index }}][invoice_line_public_id]" value="{{ $line->public_id }}" disabled />
                                            @if($line->product?->tracks_serials)
                                                <x-forms.label :label="__('inventory_serial.numbers')" />
                                                <x-forms.select variant="ajax" :name="'lines['.$index.'][delivery_line_ids][]'" multiple disabled
                                                    :url="route('admin.sales.select2.returnable-serial-deliveries', ['invoice_doc_num' => $record->doc_num, 'invoice_line_public_id' => $line->public_id])"
                                                    :placeholder="__('inventory_serial.source_selection')" />
                                            @endif
                                        </div>
                                        <div class="col-md-4"><x-forms.label :for="$returnQuantityId" :label="__('sales_ui.return_quantity')" required /><x-forms.input class="form-control form-control-sm text-end" id="{{ $returnQuantityId }}" name="lines[{{ $index }}][quantity]" value="{{ $numbers->formatForInput($returnable) }}" inputmode="decimal" min="0.00000001" max="{{ $returnable }}" disabled required /></div>
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </fieldset>
                    <button class="btn btn-falcon-primary btn-sm mt-2" type="submit">{{ __('Create Return Request') }}</button>
                </form>
            </details>
        @endcan
    @endif
    @endif
@elseif($kind === 'sales_return')
    @if(in_array($record->status, ['pending_authorization', 'authorized', 'received', 'inspected'], true))
    <div class="card mb-3"><div class="card-header py-2"><h6 class="mb-0">{{ __('Return and quality actions') }}</h6></div><div class="card-body py-3">
        <div class="d-flex flex-wrap gap-2 mb-3">
            @if($record->status === 'pending_authorization') @can('sales_returns.authorize')<form data-sales-ui class="js-sales-cycle-action" action="{{ route('admin.sales.sales-returns.authorize', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><button class="btn btn-success btn-sm" type="submit">{{ __('Authorize Return') }}</button></form>@endcan @endif
            @if($record->status === 'authorized') @can('sales_returns.receive')<form data-sales-ui class="js-sales-cycle-action" action="{{ route('admin.sales.sales-returns.receive', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><button class="btn btn-primary btn-sm" type="submit">{{ __('Receive for Quality Inspection') }}</button></form>@endcan @endif
            @if($record->status === 'inspected' || ($record->status === 'authorized' && $record->lines->every->is_service)) @can('sales_returns.close')<form data-sales-ui class="js-sales-cycle-action" action="{{ route('admin.sales.sales-returns.close', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><button class="btn btn-falcon-primary btn-sm" type="submit">{{ __('Close and Post Credit Note') }}</button></form>@endcan @endif
        </div>
        @if($record->status === 'received') @can('sales_returns.inspect')<form data-sales-ui class="js-sales-cycle-action" action="{{ route('admin.sales.sales-returns.inspect', $record) }}" method="POST">@csrf<x-forms.line-item-cards :line-label="__('sales_ui.line')" /><div class="table-responsive"><table class="table table-sm table-bordered align-middle" style="min-width:1000px"><thead><tr><th>{{ __('Product') }}</th><th>{{ __('Returned') }}</th><th>{{ __('Saleable') }}</th><th>{{ __('Quarantine') }}</th><th>{{ __('Rework') }}</th><th>{{ __('Scrap') }}</th><th>{{ __('Notes') }}</th></tr></thead><tbody>@foreach($record->lines->where('is_service', false) as $index => $line)<tr><td>{{ $line->product?->name }}<x-forms.input type="hidden" name="results[{{ $index }}][sales_return_line_public_id]" value="{{ $line->public_id }}" /></td><td>{{ $numbers->format($line->quantity) }} {{ $line->unit?->name }}</td>@foreach(['saleable','quarantine','rework','scrap'] as $bucket)<td><x-forms.input class="form-control form-control-sm text-end" name="results[{{ $index }}][{{ $bucket }}_quantity]" value="0" inputmode="decimal" /></td>@endforeach<td><x-forms.input class="form-control form-control-sm" name="results[{{ $index }}][notes]" /></td></tr>@endforeach</tbody></table></div><button class="btn btn-success btn-sm" type="submit">{{ __('Post Quality Disposition') }}</button></form>@endcan @endif
    </div></div>
    @endif
@endif

@if($kind === 'customer_receipt' && $record->status === 'approved' && (!$record->cheque || in_array($record->cheque->status, ['received', 'deposited'], true)))
    @can('customer_receipts.cancel')
        <form data-sales-ui class="js-sales-cycle-action border rounded p-3 my-3" action="{{ route('admin.sales.customer-receipts.reverse', $record) }}" method="POST">
            @csrf
            <label class="form-label">{{ __('Reversal reason') }}</label>
            <x-forms.textarea class="form-control mb-2" name="reason" required></x-forms.textarea>
            <button class="btn btn-warning btn-sm" type="submit">{{ __('Reverse Collection') }}</button>
        </form>
    @endcan
@endif

@if($kind === 'sales_return' && in_array($record->status, ['pending_authorization', 'authorized'], true))
@can('sales_returns.cancel')<form data-sales-ui class="js-sales-cycle-action border rounded p-3 my-3" method="POST" action="{{ route('admin.sales.sales-returns.cancel', $record) }}">@csrf<label class="form-label">{{ __('Cancellation reason') }}</label><x-forms.textarea class="form-control mb-2" name="reason" required></x-forms.textarea><button class="btn btn-warning btn-sm" type="submit">{{ __('Cancel Return') }}</button></form>@endcan
@endif

@if($kind === 'sales_return' && $record->status === 'received')
    @can('sales_returns.correct_receipt')
        <form data-sales-ui class="js-sales-cycle-action border rounded p-3 my-3" method="POST" action="{{ route('admin.sales.sales-returns.correct-receipt', $record) }}">
            @csrf
            <p class="text-700 mb-2">{{ __('sales_return_correction.explanation') }}</p>
            <label class="form-label" for="return-receipt-correction-reason">{{ __('sales_return_correction.reason') }}</label>
            <x-forms.textarea class="form-control mb-2" id="return-receipt-correction-reason" name="reason"></x-forms.textarea>
            <button class="btn btn-outline-warning btn-sm" type="submit">{{ __('sales_return_correction.action') }}</button>
        </form>
    @endcan
@endif
@if($kind === 'sales_return' && $record->status === 'inspected')
    @can('sales_returns.correct_disposition')
        <form data-sales-ui class="js-sales-cycle-action border rounded p-3 my-3" method="POST" action="{{ route('admin.sales.sales-returns.correct-disposition', $record) }}">
            @csrf
            <p class="text-700 mb-2">{{ __('sales_return_correction.inspected_explanation') }}</p>
            <label class="form-label" for="return-disposition-correction-reason">{{ __('sales_return_correction.reason') }}</label>
            <x-forms.textarea id="return-disposition-correction-reason" class="form-control mb-2" name="reason"></x-forms.textarea>
            <button class="btn btn-outline-warning btn-sm" type="submit">{{ __('sales_return_correction.inspected_action') }}</button>
        </form>
    @endcan
@endif
@if($kind === 'sales_return' && $record->status === 'closed')
    @can('sales_returns.correct_closed')
        <form data-sales-ui class="js-sales-cycle-action border rounded p-3 my-3" method="POST" action="{{ route('admin.sales.sales-returns.correct-closed', $record) }}">
            @csrf
            <p class="text-700 mb-2">{{ __('sales_return_correction.closed_explanation') }}</p>
            <label class="form-label" for="return-closed-correction-reason">{{ __('sales_return_correction.reason') }}</label>
            <x-forms.textarea id="return-closed-correction-reason" class="form-control mb-2" name="reason"></x-forms.textarea>
            <button class="btn btn-outline-warning btn-sm" type="submit">{{ __('sales_return_correction.closed_action') }}</button>
        </form>
    @endcan
@endif

@if($kind === 'invoice' && $record->document_type === 'invoice' && $record->posting_status === 'posted')
<div class="card mb-3"><div class="card-body"><p class="small">{{ __('sales_ui.wht.help') }}</p>
@can('customer_withholding_settlements.view')<a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.sales.sales-invoices.withholding.index', $record) }}">{{ __('sales_ui.wht.title') }}</a>@endcan
@can('customer_invoices.correct_prepare')<a class="btn btn-falcon-warning btn-sm" href="{{ route('admin.sales.sales-invoices.corrections.index', $record) }}">{{ __('invoice_correction.title') }}</a>@endcan
@can('sales_returns.create')<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.sales-returns.create', ['invoice_doc_num' => $record->doc_num]) }}">{{ __('Sales Return') }}</a>@endcan
</div></div>
@endif

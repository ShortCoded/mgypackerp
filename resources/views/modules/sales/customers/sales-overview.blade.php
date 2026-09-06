@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
<section class="card mb-3" id="customer-sales-overview">
    <div class="card-header"><h5 class="mb-0">{{ __('Customer sales activity') }}</h5></div>
    <div class="card-body">
        <p>{{ __('Open orders') }}: <strong>{{ $salesOverview['openOrderCount'] }}</strong></p>
        @can('customer_invoices.view')
        @foreach($salesOverview['balances'] as $balance)
        <div class="row g-3 mb-3">
            <div class="col-12 col-md-4">{{ __('Net Sales') }} · {{ $balance->currency?->code }}<div class="fw-bold">{{ $numbers->format(bcsub((string) $balance->sales, (string) ($salesOverview['credits']->get($balance->currency_id)?->returned ?? 0), 4)) }}</div></div>
            <div class="col-12 col-md-4">{{ __('Outstanding balance') }} · {{ $balance->currency?->code }}<div class="fw-bold">{{ $numbers->format($balance->outstanding) }}</div></div>
            <div class="col-12 col-md-4">{{ __('Available Customer Credit') }} · {{ $balance->currency?->code }}<div class="fw-bold">{{ $numbers->format($salesOverview['credits']->get($balance->currency_id)?->available ?? 0) }}</div></div>
            <div class="col-12 col-md-4">{{ __('Overdue') }}<div class="fw-bold">{{ $numbers->format($balance->overdue) }}</div></div>
            <div class="col-12 col-md-4">{{ __('Last sale date') }}<div class="fw-bold">{{ $balance->last_sale ?: '—' }}</div></div>
        </div>
        @endforeach
        @endcan
        @can('reports.customer_statement.view')
        <a class="btn btn-falcon-default btn-sm mb-3" href="{{ route('admin.accounting.reports.customer-statement', ['customer_doc_num' => $record->doc_num]) }}">{{ __('Customer Statement') }}</a>
        @endcan
        @can('customer_invoices.view_prices')
        <details class="mb-3"><summary>{{ __('Price History') }}</summary><div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('Invoice') }}</th><th>{{ __('Date') }}</th><th>{{ __('Item') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Price') }}</th><th>{{ __('Currency') }}</th></tr></thead><tbody>
        @foreach($salesOverview['priceHistory'] as $price)<tr><td><a href="{{ route('admin.sales.sales-invoices.show', $price->invoice) }}">{{ $price->invoice->doc_num }}</a></td><td>{{ $price->invoice->invoice_date?->toDateString() }}</td><td>{{ $price->product?->name }}</td><td>{{ $price->unit?->name }}</td><td>{{ $numbers->format($price->unit_price) }}</td><td>{{ $price->invoice->currency?->code }}</td></tr>@endforeach
        </tbody></table></div></details>
        @endcan
        <div class="accordion" id="customer-sales-activity">
        @foreach([
            ['quotations', 'quotations.title', 'quotations.view', 'admin.sales.quotations.show', 'quotation_date'],
            ['orders', 'Sales Orders', 'sales_orders.view', 'admin.sales.sales-orders.show', 'order_date'],
            ['invoices', 'Invoices', 'customer_invoices.view', 'admin.sales.sales-invoices.show', 'invoice_date'],
            ['receipts', 'Customer Receipts', 'customer_receipts.view', 'admin.sales.customer-receipts.show', 'receipt_date'],
            ['returns', 'Returns', 'sales_returns.view', 'admin.sales.sales-returns.show', 'return_date'],
        ] as [$key, $label, $permission, $route, $dateField])
        @can($permission)
        <div class="accordion-item">
            <h6 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#customer-{{ $key }}">{{ __($label) }}</button></h6>
            <div id="customer-{{ $key }}" class="accordion-collapse collapse"><div class="accordion-body">
                <div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('Document') }}</th><th>{{ __('Date') }}</th><th>{{ __('Status') }}</th><th>{{ __('Source documents') }}</th></tr></thead><tbody>
                @forelse($salesOverview[$key] as $document)
                <tr><td><a href="{{ route($route, $document) }}">{{ $document->doc_num }}</a></td><td>{{ $document->{$dateField}?->toDateString() }}</td><td>{{ __(str($document->status)->replace('_', ' ')->title()->toString()) }}</td><td>
                    @if($key === 'orders')
                        @can('production.orders.view')@foreach($document->productionOrders as $production)<a class="d-inline-block me-2" href="{{ route('admin.production.work-orders.show', $production) }}">{{ $production->doc_num }}</a>@endforeach@endcan
                        @can('sales_deliveries.view')@foreach($document->deliveries as $delivery)<a class="d-inline-block me-2" href="{{ route('admin.sales.delivery-notes.show', $delivery) }}">{{ $delivery->doc_num }}</a>@endforeach@endcan
                    @elseif($key === 'receipts')
                        {{ $document->cashVoucher?->doc_num ?? $document->cheque?->doc_num ?? '—' }}
                    @endif
                </td></tr>
                @empty<tr><td colspan="4">{{ __('No matching records.') }}</td></tr>@endforelse
                </tbody></table></div>
            </div></div>
        </div>
        @endcan
        @endforeach
        </div>
    </div>
</section>

@include('modules.sales.cycle.partials.attachments', ['attachmentRecord' => $record, 'attachmentKind' => 'customer', 'attachmentsReadonly' => !auth()->user()?->can('customers.edit')])
@pushOnce('scripts', 'customer-sales-actions')@include('modules.sales.cycle.partials.scripts')@endPushOnce

<h6>{{ __('inventory_correction.legacy_exchange_details') }}</h6>
<div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('Item') }}</th><th>{{ __('Document') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('inventory_correction.legacy_from_layer') }}</th><th>{{ __('inventory_correction.legacy_to_layer') }}</th><th>{{ __('inventory_correction.legacy_unit_cost') }}</th><th>{{ __('inventory_correction.legacy_issue_total') }}</th></tr></thead><tbody>
@foreach($repair['lines'] as $line)
@foreach($line['moves'] as $move)
@php($issue = collect($repair['financial_proof']['transactions'])->firstWhere('id', $move['issue_id']))
<tr><td>{{ $document->lines->firstWhere('product_id', $line['product_id'])?->product?->name }}</td><td>{{ $issue['source_doc_num'] }}</td><td dir="ltr">{{ $numbers->format($move['quantity']) }}</td><td dir="ltr">{{ $move['from_layer_id'] }}</td><td dir="ltr">{{ $move['to_layer_id'] }}</td><td dir="ltr">{{ $numbers->format($issue['unit_cost']) }}</td><td dir="ltr">{{ $numbers->format($issue['total_cost']) }}</td></tr>
@endforeach
@endforeach
</tbody></table></div>
@can('customer_invoices.view')
@if($repair['financial_proof']['invoices'])
<h6>{{ __('inventory_correction.legacy_invoices') }}</h6>
<div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('Invoice') }}</th><th>{{ __('Date') }}</th>@can('customer_invoices.view_prices')<th>{{ __('Total') }}</th>@endcan</tr></thead><tbody>
@foreach($repair['financial_proof']['invoices'] as $invoice)<tr><td><a href="{{ route('admin.sales.sales-invoices.show', $invoice['doc_num']) }}">{{ $invoice['doc_num'] }}</a></td><td dir="ltr">{{ $dates->formatDate($invoice['invoice_date']) }}</td>@can('customer_invoices.view_prices')<td dir="ltr">{{ $numbers->format($invoice['total_amount']) }}</td>@endcan</tr>@endforeach
</tbody></table></div>
@endif
@endcan
@can('journal_entries.view')
@if($repair['financial_proof']['journals'])
<h6>{{ __('inventory_correction.legacy_journals') }}</h6>
<div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('Document') }}</th><th>{{ __('Date') }}</th><th>{{ __('Debit') }}</th><th>{{ __('Credit') }}</th></tr></thead><tbody>
@foreach($repair['financial_proof']['journals'] as $journal)<tr><td><a href="{{ route('admin.accounting.journal-entries.show', $journal['header']['doc_num']) }}">{{ $journal['header']['doc_num'] }}</a></td><td dir="ltr">{{ $dates->formatDate($journal['header']['entry_date']) }}</td><td dir="ltr">{{ $numbers->format(array_reduce($journal['lines'], fn ($sum, $line) => bcadd($sum, $line['debit_amount'], 4), '0')) }}</td><td dir="ltr">{{ $numbers->format(array_reduce($journal['lines'], fn ($sum, $line) => bcadd($sum, $line['credit_amount'], 4), '0')) }}</td></tr>@endforeach
</tbody></table></div>
@endif
@endcan

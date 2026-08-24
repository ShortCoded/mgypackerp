<h1>{{ $reportTitle }}</h1>

<h2>{{ __('Stock Balance and Valuation') }}</h2>
<table><thead><tr><th>{{ __('Store') }}</th><th>{{ __('Product') }}</th><th>{{ __('Status / Batch') }}</th><th class="number">{{ __('On hand') }}</th>@if($canViewFinancial)<th class="number">{{ __('Value') }}</th><th class="number">{{ __('Unvalued') }}</th>@endif</tr></thead><tbody>
@foreach($balances as $row)<tr><td>{{ $row->branchStore?->name }}</td><td>{{ $row->product?->doc_num }} — {{ $row->product?->name }}</td><td>{{ $row->stock_status }} / {{ $row->batch_lot }}</td><td class="number">{{ $row->on_hand }}</td>@if($canViewFinancial)<td class="number">{{ $row->inventory_value }}</td><td class="number">{{ $row->unvalued_receipt_quantity }}</td>@endif</tr>@endforeach
<tr class="total"><td colspan="3">{{ __('Total') }}</td><td class="number">{{ $reportTotals['on_hand'] }}</td>@if($canViewFinancial)<td class="number">{{ $reportTotals['inventory_value'] }}</td><td class="number">{{ $reportTotals['unvalued_receipt_quantity'] }}</td>@endif</tr>
</tbody></table>

<h2>{{ __('Available vs Reserved and Reorder') }}</h2>
<table><thead><tr><th>{{ __('Product') }}</th><th>{{ __('Store') }}</th><th class="number">{{ __('On hand') }}</th><th class="number">{{ __('Reserved') }}</th><th class="number">{{ __('Available') }}</th><th class="number">{{ __('Reorder') }}</th><th class="number">{{ __('Shortage') }}</th><th class="number">{{ __('Production demand') }}</th></tr></thead><tbody>@foreach($reorder as $row)<tr><td>{{ $row->product?->doc_num }} — {{ $row->product?->name }}</td><td>{{ $row->branchStore?->name }}</td><td class="number">{{ $row->on_hand }}</td><td class="number">{{ $row->reserved }}</td><td class="number">{{ $row->available }}</td><td class="number">{{ $row->reorder_point }}</td><td class="number">{{ $row->shortage }}</td><td class="number">{{ $row->production_demand }}</td></tr>@endforeach</tbody></table>

<h2>{{ __('Movement Ledger / Stock Card') }}</h2>
<table><thead><tr><th>{{ __('Date') }}</th><th>{{ __('Source') }}</th><th>{{ __('Type') }}</th><th>{{ __('Store / status') }}</th><th>{{ __('Product') }}</th><th class="number">{{ __('In') }}</th><th class="number">{{ __('Out') }}</th></tr></thead><tbody>@foreach($movements as $row)<tr><td>{{ $row->transaction_date?->toDateString() }}</td><td>{{ $row->source_doc_num }}</td><td>{{ $row->transaction_type }}</td><td>{{ $row->branchStore?->name }} / {{ $row->stock_status }}</td><td>{{ $row->product?->name }}</td><td class="number">{{ $row->quantity_in }}</td><td class="number">{{ $row->quantity_out }}</td></tr>@endforeach<tr class="total"><td colspan="5">{{ __('Total') }}</td><td class="number">{{ $reportTotals['quantity_in'] }}</td><td class="number">{{ $reportTotals['quantity_out'] }}</td></tr></tbody></table>

<h2>{{ __('QC / Quarantine / Rework') }}</h2>
<table><thead><tr><th>{{ __('Product') }}</th><th>{{ __('Store') }}</th><th>{{ __('Status') }}</th><th>{{ __('Batch') }}</th><th class="number">{{ __('On hand') }}</th></tr></thead><tbody>@foreach($qualityBalances as $row)<tr><td>{{ $row->product?->name }}</td><td>{{ $row->branchStore?->name }}</td><td>{{ $row->stock_status }}</td><td>{{ $row->batch_lot }}</td><td class="number">{{ $row->on_hand }}</td></tr>@endforeach</tbody></table>

<h2>{{ __('Damage / Scrap and Stock Count Variances') }}</h2>
<table><thead><tr><th>{{ __('Document') }}</th><th>{{ __('Product') }}</th><th>{{ __('Event') }}</th><th class="number">{{ __('Quantity') }}</th></tr></thead><tbody>
@foreach($damageAndScrap as $row)<tr><td>{{ $row->source_doc_num }}</td><td>{{ $row->product?->name }}</td><td>{{ $row->transaction_type }}</td><td class="number">{{ $row->quantity_out }}</td></tr>@endforeach
@foreach($stockCountVariances as $row)<tr><td>{{ $row->stockCount?->doc_num }}</td><td>{{ $row->product?->name }}</td><td>{{ __('Stock count variance') }} — {{ $row->variance_reason }}</td><td class="number">{{ $row->variance_quantity }}</td></tr>@endforeach
</tbody></table>

@if($glReconciliation !== null)
<h2>{{ __('Inventory / Production to General Ledger Reconciliation') }}</h2>
<table><thead><tr><th>{{ __('Control') }}</th><th class="number">{{ __('Subledger') }}</th><th class="number">{{ __('General Ledger') }}</th><th class="number">{{ __('Difference') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>@foreach($glReconciliation as $row)<tr><td>{{ $row['label'] }}</td><td class="number">{{ $row['subledger'] }}</td><td class="number">{{ $row['gl'] }}</td><td class="number">{{ $row['difference'] }}</td><td>{{ $row['status'] }}</td></tr>@endforeach</tbody></table>
@endif

<p>{{ __('Inventory aging is unavailable because receipt-layer consumption is not recorded. Expiry reporting is unavailable because batches have no expiry-date field.') }}</p>

@php $selectedProduct = $line['product_doc_num'] ?? ''; @endphp
<tr data-sales-line>
<td data-row-number>{{ is_numeric($index) ? $index + 1 : '' }}</td>
<td><select class="form-select js-sales-product js-select2-ajax" data-url="{{ route('admin.sales.select2.quotation-products') }}" data-allow-clear="true" name="lines[{{ $index }}][product_doc_num]" required><option value="">{{ __('Select product') }}</option>@foreach($products as $product)<option value="{{ $product->doc_num }}" @selected($selectedProduct === $product->doc_num)>{{ $product->doc_num }} / {{ $product->name }}{{ $product->color ? ' / '.$product->color->name : '' }}</option>@endforeach</select></td>
<td><select class="form-select js-sales-unit js-select2-local" name="lines[{{ $index }}][unit_doc_num]" required>@foreach($productUnits[$selectedProduct] ?? [] as $unit)<option value="{{ $unit['id'] }}" @selected(($line['unit_doc_num'] ?? '') === $unit['id'])>{{ $unit['text'] }}</option>@endforeach</select></td>
<td><x-forms.numeric-input class="js-sales-quantity" :name="'lines['.$index.'][quantity]'" :value="$line['quantity'] ?? ''" :scale="8" min="0.00000001" step="0.00000001" required /></td>
<td><x-forms.numeric-input class="js-sales-price" :name="'lines['.$index.'][unit_price]'" :value="$line['unit_price'] ?? ''" :scale="4" min="0" step="0.0001" required /></td>
<td data-sales-line-total data-line-card-total></td><td><button type="button" class="btn btn-outline-secondary btn-sm me-1" data-sales-duplicate-row>{{ __('Duplicate line') }}</button><button class="btn btn-outline-danger btn-sm" type="button" data-sales-remove-row>{{ __('Remove') }}</button></td>
</tr>

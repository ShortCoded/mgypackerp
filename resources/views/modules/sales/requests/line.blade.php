@php $selectedProduct = $line['product_doc_num'] ?? ''; @endphp
<tr data-sales-line>
<td data-row-number>{{ is_numeric($index) ? $index + 1 : '' }}</td>
<td><select class="form-select js-sales-product js-select2-ajax" data-url="{{ route('admin.sales.select2.quotation-products') }}" data-allow-clear="true" name="lines[{{ $index }}][product_doc_num]" required><option value="">{{ __('Select product') }}</option>@foreach($products as $product)<option value="{{ $product->doc_num }}" @selected($selectedProduct === $product->doc_num)>{{ $product->doc_num }} / {{ $product->name }}{{ $product->color ? ' / '.$product->color->name : '' }}</option>@endforeach</select><input class="form-control" name="lines[{{ $index }}][description]" value="{{ $line['description'] ?? '' }}" aria-label="{{ __('Description') }}" placeholder="{{ __('Description') }}"></td>
<td><select class="form-select js-sales-unit js-select2-local" name="lines[{{ $index }}][unit_doc_num]">@foreach($productUnits[$selectedProduct] ?? [] as $unit)<option value="{{ $unit['id'] }}" @selected(($line['unit_doc_num'] ?? '') === $unit['id'])>{{ $unit['text'] }}</option>@endforeach</select></td>
<td><input class="form-control js-sales-quantity" name="lines[{{ $index }}][quantity]" inputmode="decimal" value="{{ app(\Modules\Core\Services\NumericFormatService::class)->formatForInput($line['quantity'] ?? '') }}" required></td>
<td><input class="form-control js-sales-price" name="lines[{{ $index }}][unit_price]" inputmode="decimal" value="{{ app(\Modules\Core\Services\NumericFormatService::class)->formatForInput($line['unit_price'] ?? '') }}"></td>
<td><input class="form-control" name="lines[{{ $index }}][specifications][packaging]" value="{{ $line['specifications']['packaging'] ?? '' }}"></td>
<td><input class="form-control" name="lines[{{ $index }}][specifications][units_per_package]" inputmode="decimal" value="{{ $line['specifications']['units_per_package'] ?? '' }}"></td>
<td><input class="form-control" name="lines[{{ $index }}][notes]" value="{{ $line['notes'] ?? '' }}"></td>
<td data-sales-line-total data-line-card-total></td><td><button type="button" class="btn btn-outline-secondary btn-sm me-1" data-sales-duplicate-row>{{ __('Duplicate line') }}</button><button class="btn btn-outline-danger btn-sm" type="button" data-sales-remove-row>{{ __('Remove') }}</button></td>
</tr>

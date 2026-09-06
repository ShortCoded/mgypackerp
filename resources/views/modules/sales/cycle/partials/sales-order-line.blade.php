@php
    $selectedProduct = $line['product_doc_num'] ?? '';
    $selectedUnit = $line['unit_doc_num'] ?? '';
    $specifications = $line['specifications'] ?? [];
@endphp
<tr data-sales-line>
    <td data-row-number>{{ is_numeric($index) ? $index + 1 : '' }}</td>
    <td><select class="form-select form-select-sm js-sales-product js-select2-ajax" data-url="{{ route('admin.sales.select2.quotation-products') }}" data-allow-clear="true" name="lines[{{ $index }}][product_doc_num]" required><option value="">{{ __('Select product') }}</option>@foreach($products as $product)<option value="{{ $product->doc_num }}" @selected($selectedProduct === $product->doc_num)>{{ $product->doc_num }} / {{ $product->name }}</option>@endforeach</select><input class="form-control form-control-sm mt-1" name="lines[{{ $index }}][description]" value="{{ $line['description'] ?? '' }}" placeholder="{{ __('Line description') }}"><div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.product_doc_num"></div></td>
    <td><select class="form-select form-select-sm js-sales-unit js-select2-local" name="lines[{{ $index }}][unit_doc_num]" required>@foreach(($productUnits[$selectedProduct] ?? collect()) as $unit)<option value="{{ $unit['id'] }}" @selected($selectedUnit === $unit['id'])>{{ $unit['text'] }}</option>@endforeach</select></td>
    <td><input class="form-control form-control-sm text-end js-sales-quantity" name="lines[{{ $index }}][quantity]" value="{{ app(\Modules\Core\Services\NumericFormatService::class)->formatForInput($line['quantity'] ?? '') }}" inputmode="decimal" required></td>
    <td><input class="form-control form-control-sm text-end js-sales-price" name="lines[{{ $index }}][unit_price]" value="{{ app(\Modules\Core\Services\NumericFormatService::class)->formatForInput($line['unit_price'] ?? '') }}" inputmode="decimal" required></td>
    <td><input class="form-control form-control-sm text-end js-sales-discount" name="lines[{{ $index }}][discount_amount]" value="{{ $line['discount_amount'] ?? 0 }}" inputmode="decimal"></td>
    <td><input class="form-control form-control-sm text-end js-sales-tax" name="lines[{{ $index }}][tax_amount]" value="{{ $line['tax_amount'] ?? 0 }}" inputmode="decimal"></td>
    <td class="text-end fw-semibold" dir="ltr" data-sales-line-total data-line-card-total>0.00</td>
    <td><input class="form-control form-control-sm js-date-picker" name="lines[{{ $index }}][requested_date]" value="{{ $line['requested_date'] ?? '' }}"></td>
    <td><input class="form-control form-control-sm" name="lines[{{ $index }}][specifications][packaging]" value="{{ $specifications['packaging'] ?? '' }}" placeholder="{{ __('Carton / pallet / wrapping') }}"></td>
    <td><input class="form-control form-control-sm" name="lines[{{ $index }}][specifications][customer_specification]" value="{{ $specifications['customer_specification'] ?? '' }}"></td>
    <td><input class="form-control form-control-sm mb-1" name="lines[{{ $index }}][warehouse_notes]" value="{{ $line['warehouse_notes'] ?? '' }}" placeholder="{{ __('Warehouse') }}"><input class="form-control form-control-sm" name="lines[{{ $index }}][production_notes]" value="{{ $line['production_notes'] ?? '' }}" placeholder="{{ __('Production') }}"></td>
    <td><button type="button" class="btn btn-outline-secondary btn-sm" data-sales-duplicate-row>{{ __('Duplicate line') }}</button><button class="btn btn-link text-danger p-1" type="button" data-sales-remove-row title="{{ __('Remove') }}">&times;</button></td>
</tr>

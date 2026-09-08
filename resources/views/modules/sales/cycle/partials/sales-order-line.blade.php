@php
    $selectedProduct = $line['product_doc_num'] ?? '';
    $selectedUnit = $line['unit_doc_num'] ?? '';
    $specifications = $line['specifications'] ?? [];
    $sourceRequestLine = $line['source_request_line_public_id'] ?? null;
    $showRequestedDate = $showRequestedDate ?? true;
@endphp
<tr data-sales-line>
    <td data-row-number>{{ is_numeric($index) ? $index + 1 : '' }}</td>
    <td>
        @if($sourceRequestLine)<input type="hidden" name="lines[{{ $index }}][source_request_line_public_id]" value="{{ $sourceRequestLine }}"><input type="hidden" name="lines[{{ $index }}][product_doc_num]" value="{{ $selectedProduct }}">@endif
        <select class="form-select form-select-sm js-sales-product {{ $sourceRequestLine ? 'js-select2-local' : 'js-select2-ajax' }}" @unless($sourceRequestLine) data-url="{{ route('admin.sales.select2.quotation-products') }}" data-allow-clear="true" name="lines[{{ $index }}][product_doc_num]" @endunless @disabled($sourceRequestLine) required><option value="">{{ __('Select product') }}</option>@foreach($products as $product)<option value="{{ $product->doc_num }}" @selected($selectedProduct === $product->doc_num)>{{ $product->doc_num }} / {{ $product->name }}</option>@endforeach</select>
        <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.product_doc_num"></div>
    </td>
    <td>@if($sourceRequestLine)<input type="hidden" name="lines[{{ $index }}][unit_doc_num]" value="{{ $selectedUnit }}">@endif<select class="form-select form-select-sm js-sales-unit js-select2-local" @unless($sourceRequestLine) name="lines[{{ $index }}][unit_doc_num]" @endunless @disabled($sourceRequestLine) required>@foreach(($productUnits[$selectedProduct] ?? collect()) as $unit)<option value="{{ $unit['id'] }}" @selected($selectedUnit === $unit['id'])>{{ $unit['text'] }}</option>@endforeach</select></td>
    <td><input class="form-control form-control-sm text-end js-sales-quantity" name="lines[{{ $index }}][quantity]" value="{{ app(\Modules\Core\Services\NumericFormatService::class)->formatForInput($line['quantity'] ?? '') }}" inputmode="decimal" required></td>
    <td><input class="form-control form-control-sm text-end js-sales-price" name="lines[{{ $index }}][unit_price]" value="{{ app(\Modules\Core\Services\NumericFormatService::class)->formatForInput($line['unit_price'] ?? '') }}" inputmode="decimal" required></td>
    <td><input class="form-control form-control-sm text-end js-sales-discount" name="lines[{{ $index }}][discount_amount]" value="{{ $line['discount_amount'] ?? 0 }}" inputmode="decimal"></td>
    <td><input class="form-control form-control-sm text-end js-sales-tax" name="lines[{{ $index }}][tax_amount]" value="{{ $line['tax_amount'] ?? 0 }}" inputmode="decimal"></td>
    <td class="text-end fw-semibold" dir="ltr" data-sales-line-total data-line-card-total>0.00</td>
    @if($showRequestedDate)<td><input class="form-control form-control-sm js-date-picker" name="lines[{{ $index }}][requested_date]" value="{{ app(\Modules\Core\Services\DateFormatService::class)->formatDate($line['requested_date'] ?? null, '') }}"></td>@endif
    <td>@unless($sourceRequestLine)<button type="button" class="btn btn-link text-600 p-1" data-sales-duplicate-row aria-label="{{ __('Duplicate line') }}" title="{{ __('Duplicate line') }}"><span class="fas fa-copy"></span></button>@endunless<button class="btn btn-link text-danger p-1" type="button" data-sales-remove-row aria-label="{{ __('Remove') }}" title="{{ __('Remove') }}"><span class="fas fa-trash-alt"></span></button></td>
</tr>

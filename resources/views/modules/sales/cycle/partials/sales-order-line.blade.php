@php
    $selectedProduct = $line['product_doc_num'] ?? '';
    $selectedUnit = $line['unit_doc_num'] ?? '';
    $specifications = $line['specifications'] ?? [];
    $sourceRequestLine = $line['source_request_line_public_id'] ?? null;
    $lockedSourceLine = (bool) ($line['locked_source_line'] ?? false);
    $lockedPrice = $lockedSourceLine || (bool) ($line['price_locked'] ?? false);
    $preserveSourceLine = $sourceRequestLine || $lockedSourceLine;
    $showRequestedDate = $showRequestedDate ?? true;
@endphp
<tr data-sales-line @if($lockedPrice) data-price-locked="1" @endif>
    <td data-row-number>
        {{ is_numeric($index) ? $index + 1 : '' }}
        @if(! $showRequestedDate && filled($line['requested_date'] ?? null))<x-forms.input type="hidden" name="lines[{{ $index }}][requested_date]" value="{{ $line['requested_date'] }}" />@endif
    </td>
    <td>
        @if($sourceRequestLine)<x-forms.input type="hidden" name="lines[{{ $index }}][source_request_line_public_id]" value="{{ $sourceRequestLine }}" />@endif
        @if($preserveSourceLine)<x-forms.input type="hidden" name="lines[{{ $index }}][product_doc_num]" value="{{ $selectedProduct }}" />@endif
        <x-forms.select class="form-select form-select-sm js-sales-product {{ $preserveSourceLine ? 'js-select2-local' : 'js-select2-ajax' }}" :url="$preserveSourceLine ? null : route('admin.sales.select2.quotation-products')" :allow-clear="! $preserveSourceLine" :name="$preserveSourceLine ? null : 'lines['.$index.'][product_doc_num]'" :disabled="$preserveSourceLine" required><option value="">{{ __('Select product') }}</option>@foreach($products as $product)<option value="{{ $product->doc_num }}" @selected($selectedProduct === $product->doc_num)>{{ $product->doc_num }} / {{ $product->name }}</option>@endforeach</x-forms.select>
        @if(filled($line['description'] ?? null))<x-forms.input type="hidden" name="lines[{{ $index }}][description]" value="{{ $line['description'] }}" />@endif
        <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.product_doc_num"></div>
    </td>
    <td>@if($preserveSourceLine)<x-forms.input type="hidden" name="lines[{{ $index }}][unit_doc_num]" value="{{ $selectedUnit }}" />@endif<x-forms.select class="form-select form-select-sm js-sales-unit js-select2-local" :name="$preserveSourceLine ? null : 'lines['.$index.'][unit_doc_num]'" :disabled="$preserveSourceLine" required>@foreach(($productUnits[$selectedProduct] ?? collect()) as $unit)<option value="{{ $unit['id'] }}" @selected($selectedUnit === $unit['id'])>{{ $unit['text'] }}</option>@endforeach</x-forms.select></td>
    <td><x-forms.input class="form-control form-control-sm text-end js-sales-quantity" name="lines[{{ $index }}][quantity]" value="{{ app(\Modules\Core\Services\NumericFormatService::class)->formatForInput($line['quantity'] ?? '') }}" inputmode="decimal" :readonly='$lockedSourceLine' required /></td>
    <td>
        <x-forms.input type="hidden" class="js-sales-price" name="lines[{{ $index }}][unit_price]" value="{{ app(\Modules\Core\Services\NumericFormatService::class)->formatForInput($line['unit_price'] ?? '') }}" />
        <div class="form-control-plaintext text-end fw-semibold" dir="ltr" data-price-display>{{ filled($line['unit_price'] ?? null) ? app(\Modules\Core\Services\NumericFormatService::class)->format($line['unit_price']) : __('price_lists.not_selected') }}</div>
        <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.unit_price"></div>
    </td>
    <td><x-forms.input class="form-control form-control-sm text-end js-sales-discount" name="lines[{{ $index }}][discount_amount]" value="{{ $line['discount_amount'] ?? 0 }}" inputmode="decimal" :readonly='$lockedSourceLine' /></td>
    <td><x-forms.input class="form-control form-control-sm text-end js-sales-tax" name="lines[{{ $index }}][tax_amount]" value="{{ $line['tax_amount'] ?? 0 }}" inputmode="decimal" :readonly='$lockedSourceLine' /></td>
    <td class="text-end fw-semibold" dir="ltr" data-sales-line-total data-line-card-total>0.00</td>
    @if($showRequestedDate)<td><x-forms.date-input class="form-control form-control-sm js-date-picker" name="lines[{{ $index }}][requested_date]" value="{{ app(\Modules\Core\Services\DateFormatService::class)->formatDate($line['requested_date'] ?? null, '') }}" /></td>@endif
    <td>@unless($preserveSourceLine)<button type="button" class="btn btn-link text-600 p-1" data-sales-duplicate-row aria-label="{{ __('Duplicate line') }}" title="{{ __('Duplicate line') }}"><span class="fas fa-copy"></span></button><button class="btn btn-link text-danger p-1" type="button" data-sales-remove-row aria-label="{{ __('Remove') }}" title="{{ __('Remove') }}"><span class="fas fa-trash-alt"></span></button>@endunless</td>
</tr>

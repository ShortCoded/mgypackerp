@php
    $sourceProduct = $record->lines->firstWhere('public_id', $line['public_id'] ?? '')?->product;
    $unitOptions = $sourceProduct ? app(\Modules\Core\Services\ProductComponentUnitOptionsService::class)->options($sourceProduct) : [];
@endphp
<tr data-procurement-line>
    <td data-line-number>{{ is_numeric($index) ? $index + 1 : '' }}
        @if(filled($line['public_id'] ?? null))<x-forms.input type="hidden" name="requested_values[lines][{{ $index }}][public_id]" :value="$line['public_id']" />@endif
    </td>
    <td><x-forms.select class="form-select js-select2-ajax js-procurement-product" name="requested_values[lines][{{ $index }}][product_doc_num]" data-url="{{ route('admin.purchases.select2.products') }}" required>
        @if(filled($line['product_doc_num'] ?? null))<option selected value="{{ $line['product_doc_num'] }}">{{ $line['product_text'] ?? $line['product_doc_num'] }}</option>@endif
    </x-forms.select></td>
    <td><x-forms.select class="form-select js-procurement-unit" name="requested_values[lines][{{ $index }}][unit_doc_num]" required>
        @foreach($unitOptions as $option)<option value="{{ $option['id'] }}" @selected($option['id'] === ($line['unit_doc_num'] ?? ''))>{{ $option['text'] }}</option>@endforeach
        @if(filled($line['unit_doc_num'] ?? null) && ! collect($unitOptions)->contains('id', $line['unit_doc_num']))<option selected value="{{ $line['unit_doc_num'] }}">{{ $line['unit_text'] ?? $line['unit_doc_num'] }}</option>@endif
    </x-forms.select></td>
    <td><x-forms.numeric-input name="requested_values[lines][{{ $index }}][ordered_quantity]" :scale="8" min="0.00000001" step="0.00000001" :value="$line['ordered_quantity'] ?? ''" required /></td>
    <td><x-forms.numeric-input name="requested_values[lines][{{ $index }}][unit_price]" :scale="8" min="0" step="0.00000001" :value="$line['unit_price'] ?? ''" required /></td>
    <td><x-forms.date-input name="requested_values[lines][{{ $index }}][required_delivery_date]" :value="$line['required_delivery_date'] ?? null" /></td>
    <td><button class="btn btn-link text-danger" type="button" data-remove-procurement-line aria-label="{{ __('Remove line') }}">&times;</button></td>
</tr>

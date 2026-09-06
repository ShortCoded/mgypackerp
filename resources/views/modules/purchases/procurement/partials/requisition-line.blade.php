@php
    $product = $products->get($line['product_doc_num'] ?? '');
    $unitOptions = $product ? app(\Modules\Core\Services\ProductComponentUnitOptionsService::class)->options($product) : ($line['unit_options'] ?? []);
    $unitDocNum = $line['unit_doc_num'] ?? '';
@endphp
<tr data-procurement-line>
    <td><input type="hidden" name="lines[{{ $index }}][public_id]" value="{{ $line['public_id'] ?? '' }}"><span data-line-number>{{ is_numeric($index) ? $index + 1 : '__NUMBER__' }}</span></td>
    <td class="line-card-wide"><select class="form-select js-select2-ajax js-procurement-product" name="lines[{{ $index }}][product_doc_num]" data-url="{{ route('admin.purchases.select2.products') }}" data-placeholder="{{ __('Select item') }}" data-template="product-image" data-allow-clear="true" required>@if(!empty($line['product_doc_num']))<option value="{{ $line['product_doc_num'] }}" selected>{{ $product ? $product->doc_num.' / '.$product->name : ($line['product_text'] ?? $line['product_doc_num']) }}</option>@endif</select></td>
    <td><select class="form-select js-select2-local js-procurement-unit" name="lines[{{ $index }}][unit_doc_num]" data-placeholder="{{ __('Unit') }}" required>@foreach($unitOptions as $option)<option value="{{ $option['id'] }}" @selected($option['id'] === $unitDocNum)>{{ $option['text'] }}</option>@endforeach @if($unitDocNum && !collect($unitOptions)->contains('id', $unitDocNum))<option selected value="{{ $unitDocNum }}">{{ $line['unit_text'] ?? $unitDocNum }}</option>@endif</select></td>
    <td><x-forms.numeric-input name="lines[{{ $index }}][requested_quantity]" :scale="8" min="0.00000001" step="0.00000001" :value="$line['requested_quantity'] ?? ''" required /></td>
    <td class="line-card-full line-card-info" data-stock-cell hidden><div data-stock-availability aria-live="polite" data-on-hand-label="{{ __('On hand') }}" data-reserved-label="{{ __('Reserved') }}" data-available-label="{{ __('Available') }}"></div></td>
    <td class="line-card-full"><input class="form-control" name="lines[{{ $index }}][notes]" value="{{ $line['notes'] ?? '' }}">
        @if(!empty($line['source_doc_num']))
            <div class="small text-700 mt-1">{{ __('Source document') }}: {{ $line['source_doc_num'] }} / {{ $line['source_line_reference'] ?? '' }}</div>
            @foreach(['source_type', 'source_doc_num', 'source_line_reference', 'required_date', 'specification'] as $field)<input type="hidden" name="lines[{{ $index }}][{{ $field }}]" value="{{ $line[$field] ?? '' }}">@endforeach
        @endif
    </td>
    <td class="line-card-actions">@if(empty($line['source_doc_num']))<button class="btn btn-falcon-default btn-sm" type="button" data-duplicate-procurement-line title="{{ __('Duplicate line') }}" aria-label="{{ __('Duplicate line') }}"><span class="fas fa-copy"></span></button>@endif<button class="btn btn-falcon-default text-danger btn-sm" type="button" data-remove-procurement-line title="{{ __('Remove line') }}" aria-label="{{ __('Remove line') }}"><span class="fas fa-trash-alt"></span></button></td>
</tr>

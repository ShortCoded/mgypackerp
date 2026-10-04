@php
    $selectedProduct = $line['product_doc_num'] ?? '';
    $lockedExistingLine = ($appendOnlyAmendment ?? false) && filled($line['id'] ?? null);
@endphp
<tr data-sales-line @if($lockedExistingLine) data-amendment-locked-line @endif>
<td data-row-number>{{ is_numeric($index) ? $index + 1 : '' }}</td>
<td><x-forms.select class="form-select js-sales-product js-select2-ajax" data-url="{{ route('admin.sales.select2.quotation-products') }}" data-allow-clear="true" name="lines[{{ $index }}][product_doc_num]" required :disabled="$lockedExistingLine"><option value="">{{ __('Select product') }}</option>@foreach($products as $product)<option value="{{ $product->doc_num }}" @selected($selectedProduct === $product->doc_num)>{{ $product->doc_num }} / {{ $product->name }}{{ $product->color ? ' / '.$product->color->name : '' }}</option>@endforeach</x-forms.select>@if($lockedExistingLine)<x-forms.input type="hidden" name="lines[{{ $index }}][product_doc_num]" :value="$selectedProduct" />@endif</td>
<td><x-forms.select class="form-select js-sales-unit js-select2-local" name="lines[{{ $index }}][unit_doc_num]" required :disabled="$lockedExistingLine">@foreach($productUnits[$selectedProduct] ?? [] as $unit)<option value="{{ $unit['id'] }}" @selected(($line['unit_doc_num'] ?? '') === $unit['id'])>{{ $unit['text'] }}</option>@endforeach</x-forms.select>@if($lockedExistingLine)<x-forms.input type="hidden" name="lines[{{ $index }}][unit_doc_num]" :value="$line['unit_doc_num'] ?? ''" />@endif</td>
<td><x-forms.numeric-input class="js-sales-quantity" :name="'lines['.$index.'][quantity]'" :value="$line['quantity'] ?? ''" :scale="8" min="0.00000001" step="0.00000001" required :disabled="$lockedExistingLine" />@if($lockedExistingLine)<x-forms.input type="hidden" name="lines[{{ $index }}][quantity]" :value="$line['quantity'] ?? ''" />@endif</td>
<td>@if($lockedExistingLine)<span class="badge bg-secondary">{{ __('Locked') }}</span>@else<button type="button" class="btn btn-outline-secondary btn-sm me-1" data-sales-duplicate-row>{{ __('Duplicate line') }}</button><button class="btn btn-outline-danger btn-sm" type="button" data-sales-remove-row>{{ __('Remove') }}</button>@endif</td>
</tr>

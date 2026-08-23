<tr data-procurement-line>
    <td class="text-center" data-line-number>{{ is_numeric($index) ? $index + 1 : '__NUMBER__' }}</td>
    <td class="procurement-item-column"><select class="form-select js-procurement-product" name="lines[{{ $index }}][product_doc_num]" required><option value="">{{ __('Select item') }}</option>@foreach($products as $product)<option value="{{ $product->doc_num }}" data-unit="{{ $product->unit?->doc_num }}" data-unit-label="{{ $product->unit?->name }}" @selected(($line['product_doc_num'] ?? null) === $product->doc_num)>{{ $product->doc_num }} / {{ $product->name }}</option>@endforeach</select></td>
    <td><input class="form-control js-procurement-unit-label" value="" readonly><input class="js-procurement-unit" type="hidden" name="lines[{{ $index }}][unit_doc_num]" value="{{ $line['unit_doc_num'] ?? '' }}"></td>
    <td><x-forms.numeric-input name="lines[{{ $index }}][requested_quantity]" :scale="8" min="0.00000001" step="0.00000001" :value="$line['requested_quantity'] ?? ''" required /></td>
    <td><input class="form-control" type="date" name="lines[{{ $index }}][required_date]" value="{{ $line['required_date'] ?? '' }}"></td>
    <td><select class="form-select" name="lines[{{ $index }}][source_type]"><option value="manual" @selected(($line['source_type'] ?? 'manual') === 'manual')>{{ __('Manual') }}</option><option value="production_order" @selected(($line['source_type'] ?? null) === 'production_order')>{{ __('Production Order') }}</option></select></td>
    <td><input class="form-control" name="lines[{{ $index }}][source_doc_num]" value="{{ $line['source_doc_num'] ?? '' }}" placeholder="PROD-..."></td>
    <td><input class="form-control" name="lines[{{ $index }}][source_line_reference]" value="{{ $line['source_line_reference'] ?? '' }}"></td>
    <td><input class="form-control" name="lines[{{ $index }}][specification]" value="{{ $line['specification'] ?? '' }}"></td>
    <td><button class="btn btn-falcon-danger btn-sm" type="button" data-remove-procurement-line><span class="fas fa-trash"></span></button></td>
</tr>

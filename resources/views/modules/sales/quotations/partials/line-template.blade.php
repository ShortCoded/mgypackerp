<td>
    <select class="form-select js-select2-ajax js-quotation-product" name="lines[__INDEX__][product_doc_num]" data-url="{{ route('admin.sales.select2.quotation-products') }}" data-placeholder="{{ __('quotations.placeholders.product') }}" data-allow-clear="true"></select>
    <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.product_doc_num"></div>
</td>
<td>
    <textarea class="form-control" name="lines[__INDEX__][description]" rows="1"></textarea>
    <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.description"></div>
</td>
<td>
    <select class="form-select js-select2-ajax js-quotation-unit" name="lines[__INDEX__][unit_doc_num]" data-url="{{ route('admin.select2.item-units') }}" data-placeholder="{{ __('quotations.placeholders.unit') }}" data-allow-clear="true"></select>
    <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.unit_doc_num"></div>
</td>
<td>
    <x-forms.numeric-input class="text-center js-quotation-calc" name="lines[__INDEX__][quantity]" value="1" :scale="4" min="0" step="0.0001" />
    <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.quantity"></div>
</td>
<td>
    <x-forms.numeric-input class="text-center js-quotation-calc" name="lines[__INDEX__][unit_price]" value="" :scale="4" min="0" step="0.0001" />
    <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.unit_price"></div>
</td>
<td>
    <div class="input-group input-group-sm">
        <select class="form-select js-quotation-calc" name="lines[__INDEX__][discount_type]">
            <option value="">{{ __('quotations.discount_types.none') }}</option>
            <option value="fixed">{{ __('quotations.discount_types.fixed') }}</option>
            <option value="percentage">{{ __('quotations.discount_types.percentage') }}</option>
        </select>
        <x-forms.numeric-input class="text-center js-quotation-calc" name="lines[__INDEX__][discount_value]" value="0" :scale="4" min="0" step="0.0001" />
    </div>
</td>
<td>
    <x-forms.numeric-input class="text-center js-quotation-calc" name="lines[__INDEX__][tax_rate]" value="0" :scale="4" min="0" max="100" step="0.0001" />
</td>
<td class="text-center">
    <span class="js-quotation-line-total" dir="ltr">0</span>
</td>
<td>
    <input class="form-control" name="lines[__INDEX__][notes]" type="text" value="">
</td>
<td class="text-center">
    <button class="btn btn-link text-600 p-0 me-2 js-quotation-duplicate-line" type="button"><span class="fas fa-copy"></span></button>
    <button class="btn btn-link text-danger p-0 js-quotation-remove-line" type="button"><span class="fas fa-trash-alt"></span></button>
</td>

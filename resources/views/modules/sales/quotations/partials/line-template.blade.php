<td>
    <x-forms.select class="form-select js-select2-ajax js-quotation-product" name="lines[__INDEX__][product_doc_num]" data-url="{{ route('admin.sales.select2.quotation-products') }}" data-placeholder="{{ __('quotations.placeholders.product') }}" data-allow-clear="true" required></x-forms.select>
    <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.product_doc_num"></div>
</td>
<td>
    <x-forms.textarea class="form-control" name="lines[__INDEX__][description]" rows="1"></x-forms.textarea>
    <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.description"></div>
</td>
<td>
    <x-forms.select class="form-select js-select2-local js-quotation-unit" name="lines[__INDEX__][unit_doc_num]" data-placeholder="{{ __('quotations.placeholders.unit') }}" data-allow-clear="true" required></x-forms.select>
    <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.unit_doc_num"></div>
</td>
<td>
    <x-forms.numeric-input class="text-center js-quotation-calc" name="lines[__INDEX__][quantity]" value="1" :scale="4" min="0.0001" step="0.0001" required />
    <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.quantity"></div>
</td>
<td>
    <x-forms.input type="hidden" class="js-quotation-calc" name="lines[__INDEX__][unit_price]" value="" />
    <div class="form-control-plaintext text-center fw-semibold" dir="ltr" data-price-display>{{ __('price_lists.not_selected') }}</div>
    <div class="invalid-feedback d-block" data-error-for="lines.__INDEX__.unit_price"></div>
</td>
<td>
    <div class="input-group input-group-sm">
        <x-forms.select class="form-select js-quotation-calc" name="lines[__INDEX__][discount_type]">
            <option value="">{{ __('quotations.discount_types.none') }}</option>
            <option value="fixed">{{ __('quotations.discount_types.fixed') }}</option>
            <option value="percentage">{{ __('quotations.discount_types.percentage') }}</option>
        </x-forms.select>
        <x-forms.numeric-input class="text-center js-quotation-calc" name="lines[__INDEX__][discount_value]" value="0" :scale="4" min="0" step="0.0001" disabled />
    </div>
</td>
<td>
    <x-forms.numeric-input class="text-center js-quotation-calc" name="lines[__INDEX__][tax_rate]" value="0" :scale="4" min="0" max="100" step="0.0001" />
</td>
<td class="text-center">
    <span class="js-quotation-line-total" data-line-card-total dir="ltr">0</span>
</td>
<td>
    <x-forms.date-input class="form-control js-date-picker" name="lines[__INDEX__][requested_date]" data-date-format="{{ app(\Modules\Core\Services\DateFormatService::class)->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" />
</td>




<td>
    <x-forms.input class="form-control" name="lines[__INDEX__][notes]" type="text" value="" />
</td>
<td class="text-center line-card-actions">
    <button class="btn btn-link text-600 p-0 me-2 js-quotation-duplicate-line" type="button" aria-label="{{ __('Duplicate line') }}" title="{{ __('Duplicate line') }}"><span class="fas fa-copy"></span></button>
    <button class="btn btn-link text-danger p-0 js-quotation-remove-line" type="button" aria-label="{{ __('Remove line') }}" title="{{ __('Remove line') }}"><span class="fas fa-trash-alt"></span></button>
</td>

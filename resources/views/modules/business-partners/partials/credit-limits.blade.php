@php
    $rows = collect($rows ?? [])->filter(fn ($row) => is_array($row))->values();
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
@endphp

<div class="business-partner-credit-limits-panel w-100">
<div class="d-flex justify-content-end align-items-center mb-3">
    @unless($isView)
        <button class="btn btn-falcon-default btn-sm js-credit-limit-add" type="button" data-shortcut-action="business-partners.add-credit-limit" title="{{ __('business_partners.actions.add_credit_limit_shortcut') }}" data-bs-title="{{ __('business_partners.actions.add_credit_limit_shortcut') }}">
            <span class="fas fa-plus me-1"></span>{{ __("{$resource}.actions.add_credit_limit") }}
        </button>
    @endunless
</div>

<div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0 w-100 business-partner-credit-limits-table js-credit-limits-table">
        <thead class="bg-100 text-900">
            <tr>
                <th style="width: 38%">{{ __("{$resource}.attributes.currency") }}</th>
                <th class="text-center" style="width: 18%">{{ __("{$resource}.attributes.credit_limit") }}</th>
                <th>{{ __("{$resource}.attributes.notes") }}</th>
                @unless($isView)<th class="text-center" style="width: 76px">{{ __('common.fields.actions') }}</th>@endunless
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $index => $row)
                <tr class="js-credit-limit-row">
                    <td>
                        @if($isView)
                            {{ $row['currency_text'] ?? __('common.empty_value') }}
                        @else
                            <select class="form-select js-select2-ajax js-credit-limit-currency" name="credit_limits[{{ $index }}][currency_doc_num]" data-url="{{ route('admin.select2.currencies') }}" data-placeholder="{{ __('business_partners.placeholders.currency') }}" data-allow-clear="true">
                                @if(! empty($row['currency_doc_num']) && ! empty($row['currency_text']))
                                    <option value="{{ $row['currency_doc_num'] }}" selected>{{ $row['currency_text'] }}</option>
                                @endif
                            </select>
                            <div class="invalid-feedback d-block" data-error-for="credit_limits.{{ $index }}.currency_doc_num"></div>
                        @endif
                    </td>
                    <td class="text-center">
                        @if($isView)
                            <span class="d-block text-center" dir="ltr">{{ isset($row['credit_limit']) && $row['credit_limit'] !== '' ? $numbers->format($row['credit_limit']) : __('common.empty_value') }}</span>
                        @else
                            <x-forms.numeric-input class="text-center" :name="'credit_limits['.$index.'][credit_limit]'" :value="$row['credit_limit'] ?? ''" :scale="4" min="0" step="0.0001" />
                            <div class="invalid-feedback d-block" data-error-for="credit_limits.{{ $index }}.credit_limit"></div>
                        @endif
                    </td>
                    <td>
                        @if($isView)
                            {{ $row['notes'] ?? __('common.empty_value') }}
                        @else
                            <input class="form-control" name="credit_limits[{{ $index }}][notes]" value="{{ $row['notes'] ?? '' }}">
                            <input type="hidden" name="credit_limits[{{ $index }}][_delete]" value="0">
                        @endif
                    </td>
                    @unless($isView)
                        <td class="text-center">
                            <button class="btn btn-link text-600 p-0 me-2 js-credit-limit-duplicate" type="button" title="{{ __('business_partners.actions.duplicate_credit_limit_shortcut') }}" data-bs-title="{{ __('business_partners.actions.duplicate_credit_limit_shortcut') }}">
                                <span class="fas fa-copy"></span>
                                <span class="visually-hidden">{{ __('business_partners.actions.duplicate_credit_limit') }}</span>
                            </button>
                            <button class="btn btn-link text-danger p-0 js-credit-limit-delete" type="button" title="{{ __('business_partners.actions.delete_credit_limit_shortcut') }}" data-bs-title="{{ __('business_partners.actions.delete_credit_limit_shortcut') }}">
                                <span class="fas fa-trash-alt"></span>
                                <span class="visually-hidden">{{ __('common.actions.delete') }}</span>
                            </button>
                        </td>
                    @endunless
                </tr>
            @empty
                @if($isView)
                    <tr><td colspan="3" class="text-center text-500 py-4">{{ __('common.empty_value') }}</td></tr>
                @endif
            @endforelse
        </tbody>
    </table>
</div>

@unless($isView)
    <template id="credit-limit-row-template">
        <tr class="js-credit-limit-row">
            <td>
                <select class="form-select js-select2-ajax js-credit-limit-currency" name="credit_limits[__INDEX__][currency_doc_num]" data-url="{{ route('admin.select2.currencies') }}" data-placeholder="{{ __('business_partners.placeholders.currency') }}" data-allow-clear="true"></select>
                <div class="invalid-feedback d-block" data-error-for="credit_limits.__INDEX__.currency_doc_num"></div>
            </td>
            <td class="text-center">
                <x-forms.numeric-input class="text-center" name="credit_limits[__INDEX__][credit_limit]" :scale="4" min="0" step="0.0001" />
                <div class="invalid-feedback d-block" data-error-for="credit_limits.__INDEX__.credit_limit"></div>
            </td>
            <td>
                <input class="form-control" name="credit_limits[__INDEX__][notes]">
                <input type="hidden" name="credit_limits[__INDEX__][_delete]" value="0">
            </td>
            <td class="text-center">
                <button class="btn btn-link text-600 p-0 me-2 js-credit-limit-duplicate" type="button" title="{{ __('business_partners.actions.duplicate_credit_limit_shortcut') }}" data-bs-title="{{ __('business_partners.actions.duplicate_credit_limit_shortcut') }}">
                    <span class="fas fa-copy"></span>
                    <span class="visually-hidden">{{ __('business_partners.actions.duplicate_credit_limit') }}</span>
                </button>
                <button class="btn btn-link text-danger p-0 js-credit-limit-delete" type="button" title="{{ __('business_partners.actions.delete_credit_limit_shortcut') }}" data-bs-title="{{ __('business_partners.actions.delete_credit_limit_shortcut') }}">
                    <span class="fas fa-trash-alt"></span>
                    <span class="visually-hidden">{{ __('common.actions.delete') }}</span>
                </button>
            </td>
        </tr>
    </template>
@endunless
</div>

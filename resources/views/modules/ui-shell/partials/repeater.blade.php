@php
    $repeaterPrefix = ($namePrefix ?? '') !== ''
        ? $namePrefix.'['.$repeater['key'].']'
        : $repeater['key'];
    $repeaterId = preg_replace('/[^A-Za-z0-9_-]/', '-', ($fieldIdPrefix ?? 'erp-ui').'-'.$repeater['key']);
    $repeaterToken = substr(sha1($repeaterId), 0, 12);
    $repeaterIndexToken = '__INDEX_'.$repeaterToken.'__';
    $repeaterNumberToken = '__NUMBER_'.$repeaterToken.'__';
@endphp

<section class="erp-ui-shell-repeater mb-3"
    id="{{ $repeaterId }}"
    data-repeater-key="{{ $repeater['key'] }}"
    data-repeater-prefix="{{ $repeaterPrefix }}"
    data-repeater-index-token="{{ $repeaterIndexToken }}"
    data-repeater-number-token="{{ $repeaterNumberToken }}">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
        <div>
            <h6 class="mb-0">{{ $definition->localized($repeater['title']) }}</h6>
            <div class="text-600 fs-11">{{ __('erp_ui_shell.repeater.card_hint') }}</div>
        </div>
        @unless ($isReadonly)
            <button class="btn btn-falcon-default btn-sm js-erp-ui-add-card"
                type="button"
                title="{{ __('erp_ui_shell.shortcuts.add_detail') }}"
                data-bs-title="{{ __('erp_ui_shell.shortcuts.add_detail') }}">
                <span class="fas fa-plus me-1"></span>{{ __('erp_ui_shell.repeater.add') }}
            </button>
        @endunless
    </div>

    <div class="erp-ui-shell-repeater-cards js-erp-ui-repeater-cards">
        @include('modules.ui-shell.partials.repeater-card', [
            'cardIndex' => 0,
            'cardNumber' => 1,
            'repeaterPrefix' => $repeaterPrefix,
            'fieldIdPrefix' => $repeaterId,
        ])
    </div>

    <div class="erp-ui-shell-repeater-empty d-none text-center text-600 py-3 js-erp-ui-repeater-empty">
        {{ __('erp_ui_shell.repeater.empty') }}
    </div>

    @unless ($isReadonly)
        <template class="js-erp-ui-repeater-template">
            @include('modules.ui-shell.partials.repeater-card', [
                'cardIndex' => $repeaterIndexToken,
                'cardNumber' => $repeaterNumberToken,
                'repeaterPrefix' => $repeaterPrefix,
                'fieldIdPrefix' => $repeaterId,
            ])
        </template>
    @endunless
</section>

@php($cardPrefix = $repeaterPrefix.'['.$cardIndex.']')

<article class="card erp-ui-shell-repeater-card js-erp-ui-repeater-card mb-2" data-card-index="{{ $cardIndex }}" tabindex="-1">
    <div class="card-header py-2 d-flex align-items-center justify-content-between gap-2">
        <span class="fw-semibold">
            {{ __('erp_ui_shell.repeater.card') }}
            <span class="js-erp-ui-card-number">{{ $cardNumber }}</span>
        </span>
        @unless ($isReadonly)
            <div class="btn-group btn-group-sm">
                <button class="btn btn-falcon-default js-erp-ui-duplicate-card" type="button" title="{{ __('erp_ui_shell.shortcuts.duplicate_detail') }}" data-bs-title="{{ __('erp_ui_shell.shortcuts.duplicate_detail') }}" aria-label="{{ __('erp_ui_shell.repeater.duplicate') }}">
                    <span class="fas fa-copy"></span>
                </button>
                <button class="btn btn-falcon-default text-danger js-erp-ui-delete-card" type="button" title="{{ __('erp_ui_shell.shortcuts.delete_detail') }}" data-bs-title="{{ __('erp_ui_shell.shortcuts.delete_detail') }}" aria-label="{{ __('erp_ui_shell.repeater.delete') }}">
                    <span class="fas fa-trash-alt"></span>
                </button>
            </div>
        @endunless
    </div>
    <div class="card-body py-3">
        <div class="row g-3 align-items-start">
            @foreach ($repeater['fields'] ?? [] as $field)
                @include('modules.ui-shell.partials.field', [
                    'field' => $field,
                    'namePrefix' => $cardPrefix,
                    'fieldIdPrefix' => $fieldIdPrefix.'-'.$cardIndex,
                ])
            @endforeach
        </div>

        @foreach ($repeater['nested_repeaters'] ?? [] as $nestedRepeater)
            <div class="mt-3 pt-3 border-top border-200">
                @include('modules.ui-shell.partials.repeater', [
                    'repeater' => $nestedRepeater,
                    'namePrefix' => $cardPrefix,
                    'fieldIdPrefix' => $fieldIdPrefix.'-'.$cardIndex,
                ])
            </div>
        @endforeach
    </div>
</article>

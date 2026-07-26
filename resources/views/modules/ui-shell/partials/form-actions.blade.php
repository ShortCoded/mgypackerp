@php
    $canList = auth()->user()?->can($definition->permission('view'));
    $isViewMode = $mode === 'view';
    $isCreateLike = in_array($mode, ['create', 'clone'], true);
    $operationalActions = [
        'approve' => ['icon' => 'check-circle', 'class' => 'text-success'],
        'post' => ['icon' => 'book', 'class' => 'text-primary'],
        'close' => ['icon' => 'lock', 'class' => 'text-warning'],
        'cancel' => ['icon' => 'ban', 'class' => 'text-danger'],
        'restore' => ['icon' => 'undo', 'class' => 'text-success'],
        'delete' => ['icon' => 'trash-alt', 'class' => 'text-danger'],
        'print' => ['icon' => 'print', 'class' => ''],
        'export' => ['icon' => 'file-export', 'class' => ''],
    ];
@endphp

<div class="d-flex flex-wrap justify-content-end gap-2 erp-ui-shell-form-actions" data-action-position="{{ $position }}">
    @if ($canList)
        <a class="btn btn-falcon-default btn-sm" href="{{ route($definition->route('index')) }}" data-shortcut-action="form.back" title="{{ __('erp_ui_shell.shortcuts.back') }}" data-bs-title="{{ __('erp_ui_shell.shortcuts.back') }}">
            <span class="fas fa-arrow-left me-1"></span>{{ __('erp_ui_shell.actions.back') }}
        </a>
    @endif

    @if ($isViewMode && $docNum)
        @if ($definition->supportsMode('edit'))
            @can($definition->permission('edit'))
                <a class="btn btn-primary btn-sm" href="{{ route($definition->route('edit'), ['doc_num' => $docNum]) }}" data-shortcut-action="form.edit">
                    <span class="fas fa-edit me-1"></span>{{ __('erp_ui_shell.actions.edit') }}
                </a>
            @endcan
        @endif

        @if ($screen['show_clone'] && $definition->supportsMode('clone'))
            @can($definition->permission('clone'))
                <a class="btn btn-falcon-default btn-sm" href="{{ route($definition->route('clone'), ['doc_num' => $docNum]) }}" data-shortcut-action="form.clone">
                    <span class="fas fa-copy me-1"></span>{{ __('erp_ui_shell.actions.clone') }}
                </a>
            @endcan
        @endif

        @foreach ($operationalActions as $action => $presentation)
            @if ($definition->hasAction($action))
                @can($definition->permission($action))
                    <button class="btn btn-falcon-default btn-sm {{ $presentation['class'] }} js-erp-ui-operational-action"
                        type="button"
                        @if ($action === 'delete') data-shortcut-action="form.delete" title="{{ __('erp_ui_shell.shortcuts.delete') }}" data-bs-title="{{ __('erp_ui_shell.shortcuts.delete') }}" @endif>
                        <span class="fas fa-{{ $presentation['icon'] }} me-1"></span>{{ __('erp_ui_shell.actions.'.$action) }}
                    </button>
                @endcan
            @endif
        @endforeach
    @endif

    @unless ($isViewMode)
        <div class="btn-group btn-group-sm">
            <button class="btn btn-primary js-erp-ui-operational-action" type="button" data-submit-action="save" data-shortcut-action="form.save" title="{{ __('erp_ui_shell.shortcuts.save') }}" data-bs-title="{{ __('erp_ui_shell.shortcuts.save') }}">
                <span class="fas fa-save me-1"></span>{{ __('erp_ui_shell.actions.save') }}
            </button>
            <button class="btn btn-primary dropdown-toggle dropdown-toggle-split" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="visually-hidden">{{ __('erp_ui_shell.actions.more') }}</span>
            </button>
            <div class="dropdown-menu dropdown-menu-end py-2">
                <button class="dropdown-item js-erp-ui-operational-action" type="button" data-submit-action="save_view" data-shortcut-action="form.save_view">{{ __('erp_ui_shell.actions.save_view') }}</button>
                <button class="dropdown-item js-erp-ui-operational-action" type="button" data-submit-action="save_edit" data-shortcut-action="form.save_edit">{{ __('erp_ui_shell.actions.save_edit') }}</button>
                <button class="dropdown-item js-erp-ui-operational-action" type="button" data-submit-action="save_back" data-shortcut-action="form.save_back">{{ __('erp_ui_shell.actions.save_back') }}</button>
                <button class="dropdown-item js-erp-ui-operational-action" type="button" data-submit-action="save_new" data-shortcut-action="form.save_new">{{ __('erp_ui_shell.actions.save_new') }}</button>
                @if ($definition->hasAction('clone'))
                    <button class="dropdown-item js-erp-ui-operational-action" type="button" data-submit-action="save_clone" data-shortcut-action="form.save_clone">{{ __('erp_ui_shell.actions.save_clone') }}</button>
                @endif
            </div>
        </div>
    @endunless
</div>

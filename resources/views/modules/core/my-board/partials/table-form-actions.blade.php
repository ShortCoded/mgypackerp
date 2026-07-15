@php
    $isView = $mode === 'view';
    $isCreate = $mode === 'create';
    $canList = auth()->user()?->can('my_board.view');
    $canSave = ! $isView;
    $canView = auth()->user()?->can('my_board.view');
    $canEdit = auth()->user()?->can('my_board.edit');
    $canCreate = auth()->user()?->can('my_board.create');
    $canDelete = $isView && $record && auth()->user()?->can('my_board.delete') && ($boardConfig['can']['delete'] ?? false);
    $segment = $type === \Modules\Core\Models\UserTask::TypeNote ? 'notes' : 'tasks';
    $routes = $boardConfig['urls'][$type] ?? [];
    $routeForRecord = static function (string $action) use ($routes, $record): string {
        $url = (string) ($routes[$action] ?? '');

        if ($record && $url !== '') {
            return str_replace('__TASK__', (string) $record->doc_num, $url);
        }

        return $url;
    };
    $indexUrl = (string) ($boardConfig['urls']['index'] ?? route('admin.my-board.table'));
    $editUrl = $record ? ($routeForRecord('edit') ?: route("admin.my-board.{$segment}.edit", $record->doc_num)) : '';
    $destroyUrl = $record ? ($routeForRecord('destroy') ?: route("admin.my-board.{$segment}.destroy", $record->doc_num)) : '';
    $showSaveDropdown = $canView || $canEdit || $canList || $canCreate;
    $shortcutTitles = [
        'back' => __('common.shortcuts.back'),
        'delete' => __('common.shortcuts.delete'),
        'edit' => __('common.shortcuts.edit'),
        'save' => __('common.shortcuts.save'),
        'save_back' => __('common.shortcuts.save_back'),
        'save_edit' => __('common.shortcuts.save_edit'),
        'save_new' => __('common.shortcuts.save_new'),
        'save_view' => __('common.shortcuts.save_view'),
    ];
@endphp

<div class="d-flex flex-wrap justify-content-end gap-2 {{ $class ?? '' }}">
    @if ($canList)
        <a class="btn btn-falcon-default btn-sm" href="{{ $indexUrl }}" data-shortcut-action="form.back" title="{{ $shortcutTitles['back'] }}" data-bs-title="{{ $shortcutTitles['back'] }}">
            <span class="fas fa-arrow-left me-1"></span>{{ __('common.actions.back') }}
        </a>
    @endif

    @if ($isView && $record)
        @if ($canEdit)
            <a class="btn btn-primary btn-sm" href="{{ $editUrl }}" data-shortcut-action="form.edit" title="{{ $shortcutTitles['edit'] }}" data-bs-title="{{ $shortcutTitles['edit'] }}">
                <span class="fas fa-edit me-1"></span>{{ __('common.actions.edit') }}
            </a>
        @endif

        @if ($canDelete)
            <button type="button" class="btn btn-falcon-default text-danger btn-sm js-delete-record js-my-board-table-delete" data-shortcut-action="form.delete" data-doc-num="{{ $record->doc_num }}" data-record-name="{{ $record->title }}" data-delete-url="{{ $destroyUrl }}" data-my-board-delete-url="{{ $destroyUrl }}" data-redirect-url="{{ $indexUrl }}" title="{{ $shortcutTitles['delete'] }}" data-bs-title="{{ $shortcutTitles['delete'] }}">
                <span class="fas fa-trash-alt me-1"></span>{{ __('common.actions.delete') }}
            </button>
        @endif
    @endif

    @if ($canSave)
        <div class="btn-group btn-group-sm">
            <button type="submit" class="btn btn-primary js-my-board-table-submit-action" data-submit-action="save" data-shortcut-action="form.save" title="{{ $shortcutTitles['save'] }}" data-bs-title="{{ $shortcutTitles['save'] }}">
                <span class="fas fa-save me-1"></span>{{ __('common.actions.save_data') }}
            </button>
            @if ($showSaveDropdown)
                <button class="btn btn-primary dropdown-toggle dropdown-toggle-split" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="visually-hidden">{{ __('common.fields.actions') }}</span>
                </button>
                <div class="dropdown-menu dropdown-menu-end py-2">
                    @unless ($isCreate)
                        <button class="dropdown-item js-my-board-table-submit-action" type="submit" data-submit-action="save" data-shortcut-action="form.save" title="{{ $shortcutTitles['save'] }}" data-bs-title="{{ $shortcutTitles['save'] }}">{{ __('common.actions.save') }}</button>
                    @endunless
                    @if ($canView)
                        <button class="dropdown-item js-my-board-table-submit-action" type="submit" data-submit-action="save_view" data-shortcut-action="form.save_view" title="{{ $shortcutTitles['save_view'] }}" data-bs-title="{{ $shortcutTitles['save_view'] }}">{{ __('common.actions.save_and_view') }}</button>
                    @endif
                    @if ($canEdit && $isCreate)
                        <button class="dropdown-item js-my-board-table-submit-action" type="submit" data-submit-action="save_edit" data-shortcut-action="form.save_edit" title="{{ $shortcutTitles['save_edit'] }}" data-bs-title="{{ $shortcutTitles['save_edit'] }}">{{ __('common.actions.save_and_edit') }}</button>
                    @endif
                    @if ($canList)
                        <button class="dropdown-item js-my-board-table-submit-action" type="submit" data-submit-action="save_back" data-shortcut-action="form.save_back" title="{{ $shortcutTitles['save_back'] }}" data-bs-title="{{ $shortcutTitles['save_back'] }}">{{ __('common.actions.save_and_back') }}</button>
                    @endif
                    @if ($canCreate)
                        <button class="dropdown-item js-my-board-table-submit-action" type="submit" data-submit-action="save_new" data-shortcut-action="form.save_new" title="{{ $shortcutTitles['save_new'] }}" data-bs-title="{{ $shortcutTitles['save_new'] }}">{{ __('common.actions.save_and_new') }}</button>
                    @endif
                </div>
            @endif
        </div>
    @endif
</div>

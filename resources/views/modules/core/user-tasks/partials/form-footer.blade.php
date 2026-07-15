<div class="card">
    <div class="card-footer">
        <div class="row flex-between-center g-2">
            <div class="col-auto">
                <a class="btn btn-falcon-default" href="{{ route('admin.tasks.index') }}" data-shortcut-action="form.back" title="{{ __('common.shortcuts.back') }}" data-bs-title="{{ __('common.shortcuts.back') }}">
                    {{ __('common.actions.back') }}
                </a>
            </div>
            <div class="col-auto">
                @include('modules.core.user-tasks.partials.form-actions')
            </div>
        </div>
    </div>
</div>

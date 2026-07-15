<div class="card mb-3">
    <div class="card-header">
        <div class="row flex-between-center g-2">
            <div class="col">
                <h5 class="mb-0">
                    @if ($mode === 'create')
                        {{ __('user_tasks.create') }}
                    @elseif ($mode === 'edit')
                        {{ __('user_tasks.edit') }}
                    @elseif ($mode === 'clone')
                        {{ __('user_tasks.titles.clone') }}
                    @else
                        {{ __('user_tasks.view') }}
                    @endif
                </h5>
            </div>
            <div class="col-auto">
                @include('modules.core.user-tasks.partials.form-actions')
            </div>
        </div>
    </div>
</div>

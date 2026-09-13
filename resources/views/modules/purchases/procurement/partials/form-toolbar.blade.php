<div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h5 class="mb-0">{{ $toolbarTitle }}</h5>
    <x-forms.input type="hidden" name="submit_action" value="save_view" />
    @include('modules.finance.partials.form-actions', ['resource' => $toolbarPermission, 'routePrefix' => 'admin.purchases.'.$toolbarRoute, 'mode' => isset($draft) && $draft ? 'edit' : 'create', 'record' => $draft ?? null, 'canClone' => false, 'canEdit' => Route::has('admin.purchases.'.$toolbarRoute.'.edit') && auth()->user()?->can($toolbarPermission.'.edit'), 'canDeleteRecord' => false])
</div>

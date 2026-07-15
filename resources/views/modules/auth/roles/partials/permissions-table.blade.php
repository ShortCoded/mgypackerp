@php
    $permissionsReadonly = $isReadonly ?? $isView;
@endphp

<div class="roles-permissions js-permission-selector">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
            <h6 class="mb-1">{{ __('auth.roles.permissions') }}</h6>
            <div class="invalid-feedback d-block" data-error-for="permissions"></div>
        </div>
    </div>

    <div class="table-responsive scrollbar roles-permissions-scroll">
        <table class="table table-sm align-middle mb-0 roles-permissions-table">
            <thead class="bg-200 text-900">
                <tr>
                    <th class="white-space-nowrap" style="width: 18rem;">
                        <div class="roles-permission-check roles-permission-check-heading">
                            <input class="form-check-input js-permission-global-check" id="role-permissions-global" type="checkbox" aria-label="{{ __('roles.permissions_ui.select_all_permissions') }}" @disabled($permissionsReadonly)>
                            <label class="form-check-label fw-semibold" for="role-permissions-global">{{ __('roles.permissions_ui.group') }}</label>
                        </div>
                    </th>
                    <th class="white-space-nowrap" style="width: 18rem;">{{ __('roles.permissions_ui.screen') }}</th>
                    <th>{{ __('roles.permissions_ui.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($permissionGroups as $permissionNode)
                    @include('modules.auth.roles.partials.permission-node', [
                        'node' => $permissionNode,
                        'depth' => 0,
                        'ancestorKeys' => [],
                    ])
                @endforeach
            </tbody>
        </table>
    </div>
</div>

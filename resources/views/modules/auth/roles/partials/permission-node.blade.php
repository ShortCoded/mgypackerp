@php
    $children = is_array($node['children'] ?? null) ? $node['children'] : [];
    $permissions = is_array($node['permissions'] ?? null) ? $node['permissions'] : [];
    $nodeKey = (string) ($node['key'] ?? 'permission-node-'.md5((string) ($node['label'] ?? $depth)));
    $nodeLabel = (string) ($node['label'] ?? $nodeKey);
    $nodeId = 'permission-node-'.md5($nodeKey);
    $resourceId = 'permission-resource-'.md5($nodeKey);
    $nodeTokens = trim(implode(' ', array_filter([...$ancestorKeys, $nodeKey])));
    $groupKey = (string) ($ancestorKeys[0] ?? $nodeKey);
    $parentKey = (string) (count($ancestorKeys) > 0 ? $ancestorKeys[array_key_last($ancestorKeys)] : '');
    $depthClass = min($depth, 6);
    $permissionsReadonly = $permissionsReadonly ?? ($isReadonly ?? $isView);
@endphp

@if ($children !== [])
    <tr class="roles-permission-row roles-permission-group-row {{ $depth === 0 ? 'roles-permission-row-main' : 'roles-permission-row-subgroup' }}" data-permission-node="{{ $nodeKey }}" data-permission-parent="{{ $parentKey }}" data-permission-depth="{{ $depth }}" data-permission-scope="group">
        <td colspan="3">
            <div class="roles-permission-node roles-permission-node-depth-{{ $depthClass }}">
                <span class="roles-permission-branch" aria-hidden="true"></span>
                <span class="roles-permission-check">
                    <x-forms.input class="form-check-input js-permission-group-check" id="{{ $nodeId }}" type="checkbox" data-permission-node="{{ $nodeKey }}" data-permission-group="{{ $groupKey }}" data-permission-parent="{{ $parentKey }}" data-permission-depth="{{ $depth }}" data-permission-scope="group" aria-label="{{ __('roles.permissions_ui.select_group_permissions', ['group' => $nodeLabel]) }}" :disabled='$permissionsReadonly' />
                    <label class="form-check-label" for="{{ $nodeId }}">{{ $nodeLabel }}</label>
                </span>
            </div>
        </td>
    </tr>
@endif

@if ($permissions !== [])
    <tr class="roles-permission-row roles-permission-resource-row" data-permission-node="{{ $nodeKey }}" data-permission-parent="{{ $parentKey }}" data-permission-resource="{{ $nodeKey }}" data-permission-depth="{{ $depth }}" data-permission-scope="resource">
        <td class="roles-permission-tree-cell">
            <span class="roles-permission-resource-guide roles-permission-node-depth-{{ $depthClass }}" aria-hidden="true"></span>
        </td>
        <td class="white-space-nowrap roles-permission-screen-cell">
            <div class="roles-permission-node roles-permission-node-depth-{{ $depthClass }}">
                <span class="roles-permission-branch" aria-hidden="true"></span>
                <span class="roles-permission-check">
                    <x-forms.input class="form-check-input js-permission-resource-check" id="{{ $resourceId }}" type="checkbox" data-permission-node="{{ $nodeKey }}" data-permission-nodes="{{ $nodeTokens }}" data-permission-group="{{ $groupKey }}" data-permission-parent="{{ $parentKey }}" data-permission-resource="{{ $nodeKey }}" data-permission-scope="resource" aria-label="{{ __('roles.permissions_ui.select_screen_permissions', ['screen' => $nodeLabel]) }}" :disabled='$permissionsReadonly' />
                    <label class="form-check-label" for="{{ $resourceId }}">{{ $nodeLabel }}</label>
                </span>
            </div>
        </td>
        <td class="roles-permission-actions-cell">
            <div class="roles-permission-actions">
                @foreach ($permissions as $permission)
                    @php
                        $permissionId = 'permission-'.md5($permission['name']);
                    @endphp
                    <div class="roles-permission-action">
                        <x-forms.input class="form-check-input js-permission-checkbox" id="{{ $permissionId }}" name="permissions[]" type="checkbox" value="{{ $permission['name'] }}" data-permission-node="{{ $nodeKey }}" data-permission-nodes="{{ $nodeTokens }}" data-permission-group="{{ $groupKey }}" data-permission-parent="{{ $parentKey }}" data-permission-resource="{{ $nodeKey }}" data-permission-scope="permission" :checked="in_array($permission['name'], $assignedPermissions, true)" :disabled='$permissionsReadonly' />
                        <label class="form-check-label" for="{{ $permissionId }}">
                            {{ $permission['label'] }}
                            {{-- <span class="roles-permission-code text-500 fs-11" dir="ltr">{{ $permission['name'] }}</span> --}}
                        </label>
                    </div>
                @endforeach
            </div>
        </td>
    </tr>
@endif

@foreach ($children as $childNode)
    @include('modules.auth.roles.partials.permission-node', [
        'node' => $childNode,
        'depth' => $depth + 1,
        'ancestorKeys' => [...$ancestorKeys, $nodeKey],
    ])
@endforeach

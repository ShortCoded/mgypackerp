@php
    $isOperatingScopeEditable = $canManageOperatingScope && ! ($isReadonly ?? $isView);
    $scopeFields = [
        [
            'id' => 'role-accessible-companies',
            'name' => 'accessible_company_doc_nums',
            'label' => __('roles.operating_scope.companies'),
            'placeholder' => __('roles.operating_scope.placeholders.companies'),
            'url' => route('admin.select2.companies', ['access_scope' => 'operating_scope']),
            'options' => $assignedCompanyOptions,
            'restricted' => $companyAccessRestricted,
            'all' => __('roles.operating_scope.all_companies'),
            'none' => __('roles.operating_scope.no_active_companies'),
        ],
        [
            'id' => 'role-accessible-branches',
            'name' => 'accessible_branch_doc_nums',
            'label' => __('roles.operating_scope.branches'),
            'placeholder' => __('roles.operating_scope.placeholders.branches'),
            'url' => route('admin.select2.branches', ['access_scope' => 'operating_scope']),
            'options' => $assignedBranchOptions,
            'restricted' => $branchAccessRestricted,
            'all' => __('roles.operating_scope.all_branches'),
            'none' => __('roles.operating_scope.no_active_branches'),
            'depends' => '#role-accessible-companies',
            'dependsParam' => 'company_doc_nums',
            'dependsResultField' => 'company_doc_num',
        ],
        [
            'id' => 'role-accessible-periods',
            'name' => 'accessible_financial_period_doc_nums',
            'label' => __('roles.operating_scope.financial_periods'),
            'placeholder' => __('roles.operating_scope.placeholders.financial_periods'),
            'url' => route('admin.select2.financial-periods', ['access_scope' => 'operating_scope']),
            'options' => $assignedFinancialPeriodOptions,
            'restricted' => $financialPeriodAccessRestricted,
            'all' => __('roles.operating_scope.all_financial_periods'),
            'none' => __('roles.operating_scope.no_active_financial_periods'),
            'depends' => '#role-accessible-companies',
            'dependsParam' => 'company_doc_nums',
            'dependsResultField' => 'company_doc_num',
        ],
    ];
@endphp

<div class="roles-operating-scope">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
            <h6 class="mb-1">{{ __('roles.operating_scope.title') }}</h6>
            <div class="text-600 fs-10">{{ __('roles.operating_scope.helper') }}</div>
        </div>
    </div>

    <div class="row g-3">
        @foreach ($scopeFields as $field)
            <div class="col-12">
                <label class="form-label" for="{{ $field['id'] }}">{{ $field['label'] }}</label>
                @if ($isOperatingScopeEditable)
                    <select id="{{ $field['id'] }}"
                            name="{{ $field['name'] }}[]"
                            class="form-select js-select2-ajax js-role-operating-scope"
                            multiple
                            data-url="{{ $field['url'] }}"
                            @isset($field['depends'])
                                data-depends-on="{{ $field['depends'] }}"
                                data-dependent-param="{{ $field['dependsParam'] }}"
                                data-dependent-result-field="{{ $field['dependsResultField'] }}"
                                data-preserve-dependent-values="true"
                                data-disable-when-dependency-empty="true"
                            @endisset
                            data-placeholder="{{ $field['placeholder'] }}"
                            data-allow-clear="true"
                            data-clear-all="true"
                            data-clear-all-label="{{ __('common.actions.clear_all') }}">
                        @foreach ($field['options'] as $option)
                            <option value="{{ $option['id'] }}" @if (($option['company_doc_num'] ?? null) !== null) data-dependent-value="{{ $option['company_doc_num'] }}" @endif selected>{{ $option['text'] }}</option>
                        @endforeach
                    </select>
                @elseif (! $field['restricted'])
                    <x-forms.view-field :for="$field['id']" :value="$field['all']" />
                @elseif ($field['options'] !== [])
                    <x-forms.view-field :for="$field['id']" as="display">
                        <div class="d-flex flex-wrap gap-2">
                            @foreach ($field['options'] as $option)
                                <span class="badge rounded-pill badge-subtle-primary">{{ $option['text'] }}</span>
                            @endforeach
                        </div>
                    </x-forms.view-field>
                @else
                    <x-forms.view-field :for="$field['id']" :value="$field['none']" />
                @endif
                <div class="invalid-feedback d-block" data-error-for="{{ $field['name'] }}"></div>
            </div>
        @endforeach
    </div>

    @if (! $canManageOperatingScope && ! $isView)
        <div class="form-text mt-2">{{ __('roles.operating_scope.manage_forbidden') }}</div>
    @endif
</div>

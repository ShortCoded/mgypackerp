@extends('layouts.app')

@section('title', __('hr_payroll_policies.title'))

@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $selectedPolicyBranch = filled(old('branch_doc_num'))
        ? $branches->firstWhere('doc_num', old('branch_doc_num'))
        : null;
@endphp

@section('content')
    <div class="container-fluid w-100 mw-100 m-0 p-0 hr-cycle-shell">
        @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        @if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div class="card mb-3"><div class="card-body py-3 d-flex flex-wrap justify-content-between align-items-center gap-2"><div><h4 class="mb-1">{{ __('hr_payroll_policies.title') }}</h4><div class="small text-muted">{{ __('hr_payroll_policies.help') }}</div></div><div class="d-flex gap-2"><a class="btn btn-sm btn-falcon-default" href="{{ route('admin.hr.employee-attendance.import.index') }}">{{ __('hr_payroll_policies.workspace.import') }}</a><a class="btn btn-sm btn-falcon-default" href="{{ route('admin.hr.payroll-preparation.index') }}">{{ __('hr_payroll_policies.workspace.payroll') }}</a></div></div></div>

        @can('hr.payroll_attendance_policies.manage')
            <div class="card mb-3"><div class="card-header py-3"><h5 class="mb-0">{{ __('hr_payroll_policies.create') }}</h5></div><div class="card-body">
                <form method="POST" action="{{ route('admin.hr.payroll-attendance-policies.store') }}" class="row g-3">
                    @csrf
                    <div class="col-12"><h6 class="mb-0">{{ __('hr_payroll_policies.workspace.scope_section') }}</h6><div class="small text-muted">{{ __('hr_payroll_policies.workspace.scope_help') }}</div></div>
                    <div class="col-md-4"><x-forms.label for="policy_branch" :label="__('hr_payroll_policies.fields.branch')" /><x-forms.select id="policy_branch" variant="local" name="branch_doc_num">@if ($canCreateCompanyPolicy)<option value="">{{ __('hr_payroll_policies.company_scope') }}</option>@endif @foreach($branches as $branch)<option value="{{ $branch->doc_num }}" @selected(old('branch_doc_num') === $branch->doc_num)>{{ $branch->name }}</option>@endforeach</x-forms.select></div>
                    <div class="col-md-4"><x-forms.label for="policy_effective_from" :label="__('hr_payroll_policies.fields.effective_from')" :required="true" /><x-forms.date-input id="policy_effective_from" name="effective_from" :value="old('effective_from', now()->toDateString())" required /></div>
                    <div class="col-md-4"><x-forms.label for="policy_deduction_item" :label="__('hr_payroll_policies.fields.deduction_payroll_item_code')" /><x-forms.select id="policy_deduction_item" variant="local" name="deduction_payroll_item_code"><option value="">{{ __('common.placeholders.select') }}</option>@foreach ($payrollItems as $item)<option value="{{ $item->code }}" @selected(old('deduction_payroll_item_code') === $item->code)>{{ $item->display_name }}</option>@endforeach</x-forms.select><div class="form-text">{{ __('hr_payroll_policies.workspace.deduction_item_help') }}</div></div>
                    <div class="col-md-6"><x-forms.label for="salary_day_divisor" :label="__('hr_payroll_policies.fields.salary_day_divisor')" :required="true" /><x-forms.numeric-input id="salary_day_divisor" min="1" max="366" name="salary_day_divisor" :scale="0" step="1" :value="old('salary_day_divisor', 30)" required /><div class="form-text">{{ __('hr_payroll_policies.workspace.divisor_help') }}</div></div>
                    <div class="col-md-6"><x-forms.label for="standard_day_minutes" :label="__('hr_payroll_policies.fields.standard_day_minutes')" :required="true" /><x-forms.numeric-input id="standard_day_minutes" min="1" max="1440" name="standard_day_minutes" :scale="0" step="1" :value="old('standard_day_minutes', 480)" required /><div class="form-text">{{ __('hr_payroll_policies.workspace.minutes_help') }}</div></div>
                    <div class="col-12"><h6 class="mb-0">{{ __('hr_payroll_policies.workspace.rules_section') }}</h6><div class="small text-muted">{{ __('hr_payroll_policies.workspace.rules_help') }}</div></div>
                    <div class="col-12"><div class="hr-choice-grid">
                        @foreach (['deduct_absence', 'deduct_late', 'deduct_early_leave', 'deduct_unpaid_leave'] as $field)
                            <label class="hr-choice-card" for="{{ $field }}"><x-forms.input class="form-check-input" type="checkbox" name="{{ $field }}" value="1" :id="$field" :checked="old($field)" /><span><strong>{{ __('hr_payroll_policies.fields.'.$field) }}</strong><small>{{ __('hr_payroll_policies.descriptions.'.$field) }}</small></span></label>
                        @endforeach
                    </div></div>
                    <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary px-4" type="submit">{{ __('hr_payroll_policies.workspace.save_version') }}</button></div>
                </form>
            </div></div>
        @endcan

        <div class="card"><div class="card-header py-3"><h5 class="mb-0">{{ __('hr_payroll_policies.workspace.versions') }}</h5></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>{{ __('hr_payroll_policies.scope') }}</th><th>{{ __('hr_payroll_policies.fields.effective_from') }}</th><th>{{ __('hr_payroll_policies.fields.effective_to') }}</th><th>{{ __('hr_payroll_policies.fields.deduction_payroll_item_code') }}</th><th>{{ __('hr_payroll_policies.workspace.enabled_rules') }}</th></tr></thead><tbody>
            @forelse ($policies as $policy)<tr class="hr-policy-version"><td><strong>{{ $policy->branch?->name ?? __('hr_payroll_policies.company_scope') }}</strong></td><td>{{ $dates->formatDate($policy->effective_from, '') }}</td><td>{{ $dates->formatDate($policy->effective_to, __('hr_payroll_policies.workspace.current')) }}</td><td>{{ $payrollItemNames->get($policy->deduction_payroll_item_code, __('hr_payroll_reports.payslip.default_item')) }}</td><td><div class="d-flex flex-wrap gap-1">@foreach (['deduct_absence', 'deduct_late', 'deduct_early_leave', 'deduct_unpaid_leave'] as $field) @if($policy->{$field})<span class="badge badge-subtle-primary">{{ __('hr_payroll_policies.short.'.$field) }}</span>@endif @endforeach @if(! $policy->deduct_absence && ! $policy->deduct_late && ! $policy->deduct_early_leave && ! $policy->deduct_unpaid_leave)<span class="badge badge-subtle-secondary">{{ __('hr_payroll_policies.workspace.no_deductions') }}</span>@endif</div></td></tr>@empty<tr><td colspan="5" class="text-center text-muted py-4">{{ __('hr_payroll_policies.messages.empty') }}</td></tr>@endforelse
        </tbody></table></div></div>
        <div class="mt-3">{{ $policies->links() }}</div>
    </div>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/HR/hr-cycle.css') }}">
@endpush

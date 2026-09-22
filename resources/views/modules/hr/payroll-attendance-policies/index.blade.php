@extends('layouts.app')

@section('title', __('hr_payroll_policies.title'))

@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $selectedPolicyBranch = filled(old('branch_doc_num'))
        ? $branches->firstWhere('doc_num', old('branch_doc_num'))
        : null;
@endphp

@section('content')
    <div class="container-fluid px-0 px-sm-3 hr-cycle-shell">
        @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        @if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <section class="hr-cycle-hero mb-3">
            <div class="card-body p-4 p-lg-5 position-relative" style="z-index:1">
                <div class="hr-cycle-kicker mb-2">{{ __('hr_payroll_policies.workspace.kicker') }}</div>
                <h2 class="text-white mb-2">{{ __('hr_payroll_policies.title') }}</h2>
                <p class="text-600 mb-3">{{ __('hr_payroll_policies.workspace.description') }}</p>
                <div class="hr-quick-nav">
                    <a href="{{ route('admin.hr.employee-attendance.import.index') }}"><span class="fas fa-file-import me-1"></span>{{ __('hr_payroll_policies.workspace.import') }}</a>
                    <a href="{{ route('admin.hr.employee-attendance.index') }}"><span class="fas fa-user-clock me-1"></span>{{ __('hr_payroll_policies.workspace.attendance') }}</a>
                    <a href="{{ route('admin.hr.payroll-preparation.index') }}"><span class="fas fa-calculator me-1"></span>{{ __('hr_payroll_policies.workspace.payroll') }}</a>
                </div>
            </div>
        </section>

        <div class="hr-help-band p-3 mb-3">
            <div class="d-flex gap-2"><span class="fas fa-info-circle mt-1"></span><div><strong>{{ __('hr_payroll_policies.workspace.how_it_works') }}</strong><div class="small mt-1">{{ __('hr_payroll_policies.help') }}</div></div></div>
        </div>

        @can('hr.payroll_attendance_policies.manage')
            <div class="card hr-section-card mb-3"><div class="card-header py-3"><div class="hr-section-eyebrow">{{ __('hr_payroll_policies.workspace.new_version') }}</div><h5 class="mb-0">{{ __('hr_payroll_policies.create') }}</h5></div><div class="card-body">
                <form method="POST" action="{{ route('admin.hr.payroll-attendance-policies.store') }}" class="row g-3">
                    @csrf
                    <div class="col-12"><h6 class="mb-0">{{ __('hr_payroll_policies.workspace.scope_section') }}</h6><div class="small text-muted">{{ __('hr_payroll_policies.workspace.scope_help') }}</div></div>
                    <div class="col-md-4"><x-forms.label for="policy_branch" :label="__('hr_payroll_policies.fields.branch')" /><x-forms.select id="policy_branch" variant="ajax" name="branch_doc_num" :url="route('admin.select2.branches')" :placeholder="__('hr_payroll_policies.company_scope')">@if ($canCreateCompanyPolicy)<option value="">{{ __('hr_payroll_policies.company_scope') }}</option>@endif @if($selectedPolicyBranch)<option value="{{ $selectedPolicyBranch->doc_num }}" selected>{{ $selectedPolicyBranch->name }}</option>@endif</x-forms.select></div>
                    <div class="col-md-4"><x-forms.label for="policy_effective_from" :label="__('hr_payroll_policies.fields.effective_from')" :required="true" /><x-forms.date-input id="policy_effective_from" name="effective_from" :value="old('effective_from', now()->toDateString())" required /></div>
                    <div class="col-md-4"><x-forms.label for="policy_deduction_item" :label="__('hr_payroll_policies.fields.deduction_payroll_item_code')" /><x-forms.select id="policy_deduction_item" variant="local" name="deduction_payroll_item_code"><option value="">{{ __('common.placeholders.select') }}</option>@foreach ($payrollItems as $item)<option value="{{ $item->code }}" @selected(old('deduction_payroll_item_code') === $item->code)>{{ $item->name }} ({{ $item->code }})</option>@endforeach</x-forms.select><div class="form-text">{{ __('hr_payroll_policies.workspace.deduction_item_help') }}</div></div>
                    <div class="col-md-6"><x-forms.label for="salary_day_divisor" :label="__('hr_payroll_policies.fields.salary_day_divisor')" :required="true" /><input class="form-control" id="salary_day_divisor" type="number" min="1" max="366" name="salary_day_divisor" value="{{ old('salary_day_divisor', 30) }}" required><div class="form-text">{{ __('hr_payroll_policies.workspace.divisor_help') }}</div></div>
                    <div class="col-md-6"><x-forms.label for="standard_day_minutes" :label="__('hr_payroll_policies.fields.standard_day_minutes')" :required="true" /><input class="form-control" id="standard_day_minutes" type="number" min="1" max="1440" name="standard_day_minutes" value="{{ old('standard_day_minutes', 480) }}" required><div class="form-text">{{ __('hr_payroll_policies.workspace.minutes_help') }}</div></div>
                    <div class="col-12"><h6 class="mb-0">{{ __('hr_payroll_policies.workspace.rules_section') }}</h6><div class="small text-muted">{{ __('hr_payroll_policies.workspace.rules_help') }}</div></div>
                    <div class="col-12"><div class="hr-choice-grid">
                        @foreach (['deduct_absence', 'deduct_late', 'deduct_early_leave', 'deduct_unpaid_leave'] as $field)
                            <label class="hr-choice-card" for="{{ $field }}"><input class="form-check-input" type="checkbox" name="{{ $field }}" value="1" id="{{ $field }}" @checked(old($field))><span><strong>{{ __('hr_payroll_policies.fields.'.$field) }}</strong><small>{{ __('hr_payroll_policies.descriptions.'.$field) }}</small></span></label>
                        @endforeach
                    </div></div>
                    <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary px-4" type="submit"><span class="fas fa-layer-group me-1"></span>{{ __('hr_payroll_policies.workspace.save_version') }}</button></div>
                </form>
            </div></div>
        @endcan

        <div class="card hr-section-card"><div class="card-header py-3"><div class="hr-section-eyebrow">{{ __('hr_payroll_policies.workspace.history') }}</div><h5 class="mb-0">{{ __('hr_payroll_policies.workspace.versions') }}</h5></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>{{ __('hr_payroll_policies.scope') }}</th><th>{{ __('hr_payroll_policies.fields.effective_from') }}</th><th>{{ __('hr_payroll_policies.fields.effective_to') }}</th><th>{{ __('hr_payroll_policies.fields.deduction_payroll_item_code') }}</th><th>{{ __('hr_payroll_policies.workspace.enabled_rules') }}</th></tr></thead><tbody>
            @forelse ($policies as $policy)<tr class="hr-policy-version"><td><strong>{{ $policy->branch?->name ?? __('hr_payroll_policies.company_scope') }}</strong></td><td>{{ $dates->formatDate($policy->effective_from, '') }}</td><td>{{ $dates->formatDate($policy->effective_to, __('hr_payroll_policies.workspace.current')) }}</td><td>{{ $policy->deduction_payroll_item_code ?? '—' }}</td><td><div class="d-flex flex-wrap gap-1">@foreach (['deduct_absence', 'deduct_late', 'deduct_early_leave', 'deduct_unpaid_leave'] as $field) @if($policy->{$field})<span class="badge badge-subtle-primary">{{ __('hr_payroll_policies.short.'.$field) }}</span>@endif @endforeach @if(! $policy->deduct_absence && ! $policy->deduct_late && ! $policy->deduct_early_leave && ! $policy->deduct_unpaid_leave)<span class="badge badge-subtle-secondary">{{ __('hr_payroll_policies.workspace.no_deductions') }}</span>@endif</div></td></tr>@empty<tr><td colspan="5" class="text-center text-muted py-4">{{ __('hr_payroll_policies.messages.empty') }}</td></tr>@endforelse
        </tbody></table></div></div>
        <div class="mt-3">{{ $policies->links() }}</div>
    </div>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/modules/HR/hr-cycle.css') }}?v=20260922">
@endpush

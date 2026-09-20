@extends('layouts.app')

@section('title', __('hr_payroll_policies.title'))

@section('content')
    <div class="container-fluid px-0 px-sm-3">
        @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        @if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div class="alert alert-info">{{ __('hr_payroll_policies.help') }}</div>

        @can('hr.payroll_attendance_policies.manage')
            <div class="card mb-3"><div class="card-header"><h5 class="mb-0">{{ __('hr_payroll_policies.create') }}</h5></div><div class="card-body">
                <form method="POST" action="{{ route('admin.hr.payroll-attendance-policies.store') }}" class="row g-3">
                    @csrf
                    <div class="col-md-4"><label class="form-label">{{ __('hr_payroll_policies.fields.branch') }}</label><select class="form-select" name="branch_doc_num">@if ($canCreateCompanyPolicy)<option value="">{{ __('hr_payroll_policies.company_scope') }}</option>@endif @foreach ($branches as $branch)<option value="{{ $branch->doc_num }}">{{ $branch->name }}</option>@endforeach</select></div>
                    <div class="col-md-4"><label class="form-label">{{ __('hr_payroll_policies.fields.effective_from') }}</label><input class="form-control" type="date" name="effective_from" value="{{ old('effective_from', now()->toDateString()) }}" required></div>
                    <div class="col-md-4"><label class="form-label">{{ __('hr_payroll_policies.fields.deduction_payroll_item_code') }}</label><select class="form-select" name="deduction_payroll_item_code"><option value=""></option>@foreach ($payrollItems as $item)<option value="{{ $item->code }}">{{ $item->name }} ({{ $item->code }})</option>@endforeach</select></div>
                    <div class="col-md-3"><label class="form-label">{{ __('hr_payroll_policies.fields.salary_day_divisor') }}</label><input class="form-control" type="number" min="1" max="366" name="salary_day_divisor" value="{{ old('salary_day_divisor', 30) }}" required></div>
                    <div class="col-md-3"><label class="form-label">{{ __('hr_payroll_policies.fields.standard_day_minutes') }}</label><input class="form-control" type="number" min="1" max="1440" name="standard_day_minutes" value="{{ old('standard_day_minutes', 480) }}" required></div>
                    @foreach (['deduct_absence', 'deduct_late', 'deduct_early_leave', 'deduct_unpaid_leave'] as $field)<div class="col-md-3 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="{{ $field }}" value="1" id="{{ $field }}"><label class="form-check-label" for="{{ $field }}">{{ __('hr_payroll_policies.fields.'.$field) }}</label></div></div>@endforeach
                    <div class="col-12"><button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button></div>
                </form>
            </div></div>
        @endcan

        <div class="table-responsive"><table class="table table-striped align-middle"><thead><tr><th>{{ __('hr_payroll_policies.scope') }}</th><th>{{ __('hr_payroll_policies.fields.effective_from') }}</th><th>{{ __('hr_payroll_policies.fields.effective_to') }}</th><th>{{ __('hr_payroll_policies.fields.deduction_payroll_item_code') }}</th></tr></thead><tbody>
            @forelse ($policies as $policy)<tr><td>{{ $policy->branch?->name ?? __('hr_payroll_policies.company_scope') }}</td><td>{{ $policy->effective_from?->toDateString() }}</td><td>{{ $policy->effective_to?->toDateString() ?? '—' }}</td><td>{{ $policy->deduction_payroll_item_code ?? '—' }}</td></tr>@empty<tr><td colspan="4" class="text-center text-muted py-4">{{ __('hr_payroll_policies.messages.empty') }}</td></tr>@endforelse
        </tbody></table></div>
        <div class="mt-3">{{ $policies->links() }}</div>
    </div>
@endsection

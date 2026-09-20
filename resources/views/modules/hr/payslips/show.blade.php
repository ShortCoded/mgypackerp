@extends('layouts.app')

@section('title', __('hr_payroll_reports.payslip.title'))

@section('content')
    <div class="container-fluid px-0 px-sm-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div><h3 class="mb-1">{{ __('hr_payroll_reports.payslip.title') }} #{{ $payslip->id }}</h3><div class="text-muted">{{ $payslip->employee_name }} / {{ $payslip->employee_doc_num }}</div></div>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary" target="_blank" href="{{ route($selfService ? 'employee.hr.payslips.print' : 'admin.hr.payslips.print', $payslip->id) }}">{{ __('hr_payroll_reports.actions.print') }}</a>
                <a class="btn btn-primary" target="_blank" href="{{ route($selfService ? 'employee.hr.payslips.pdf' : 'admin.hr.payslips.pdf', $payslip->id) }}">PDF</a>
            </div>
        </div>
        @include('modules.hr.payslips.partials.content')
    </div>
@endsection

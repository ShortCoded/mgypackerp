@extends('layouts.app')

@section('title', __('hr_leave_types.show'))

@section('content')
    <div class="container-fluid px-0 px-sm-3">
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <h5 class="mb-0">{{ $leaveType->name }}</h5>
                <span class="badge bg-{{ $leaveType->trashed() ? 'danger' : ($leaveType->status === 'active' ? 'success' : 'secondary') }}">
                    {{ $leaveType->trashed() ? __('common.trash.trashed') : __('hr_leave_types.statuses.'.$leaveType->status) }}
                </span>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">{{ __('hr_leave_types.fields.code') }}</dt><dd class="col-sm-8">{{ $leaveType->code }}</dd>
                    <dt class="col-sm-4">{{ __('hr_leave_types.fields.payment_status') }}</dt><dd class="col-sm-8">{{ __('hr_leave_types.payment_statuses.'.($leaveType->isPaid() ? 'paid' : 'unpaid')) }}</dd>
                    <dt class="col-sm-4">{{ __('hr_leave_types.fields.requires_balance') }}</dt><dd class="col-sm-8">{{ $leaveType->requiresBalance() ? __('common.yes') : __('common.no') }}</dd>
                    <dt class="col-sm-4">{{ __('hr_leave_types.fields.annual_entitlement_days') }}</dt><dd class="col-sm-8">{{ $leaveType->annualEntitlementDays() ?? '—' }}</dd>
                    <dt class="col-sm-4">{{ __('hr_leave_types.fields.carry_forward_max_days') }}</dt><dd class="col-sm-8">{{ $leaveType->carryForwardMaxDays() ?? '—' }}</dd>
                    <dt class="col-sm-4">{{ __('hr_leave_types.fields.notes') }}</dt><dd class="col-sm-8">{{ $leaveType->notes ?: '—' }}</dd>
                </dl>
                @if ($leaveType->trashed())
                    @can('hr.leave_types.restore')
                        <form method="POST" action="{{ route('admin.hr.leave-types.restore', $leaveType->getKey()) }}" class="mt-3">
                            @csrf @method('PATCH')
                            <button class="btn btn-success" type="submit">{{ __('common.actions.restore') }}</button>
                        </form>
                    @endcan
                @endif
            </div>
        </div>
    </div>
@endsection

@extends('layouts.app')

@section('title', __('hr_leave_types.title'))

@section('content')
    <div class="container-fluid px-0 px-sm-3">
        @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        @if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        @if ($canViewDeleted)
            <div class="btn-group mb-3" role="group">
                @foreach (['active' => '', 'with' => 'with', 'only' => 'only'] as $label => $value)
                    <a class="btn btn-sm {{ $trash === $value ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('admin.hr.leave-types.index', $value === '' ? [] : ['trash' => $value]) }}">{{ __('hr_leave_types.trash_filters.'.$label) }}</a>
                @endforeach
            </div>
        @endif

        @can('hr.leave_types.create')
            <div class="card mb-3">
                <div class="card-header"><h5 class="mb-0">{{ __('hr_leave_types.create') }}</h5></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.hr.leave-types.store') }}" class="row g-3">
                        @csrf
                        <div class="col-md-3"><x-forms.label for="leave_type_code" :label="__('hr_leave_types.fields.code')" :required="true" /><x-forms.input id="leave_type_code" name="code" :value="old('code')" required /></div>
                        <div class="col-md-5"><x-forms.label for="leave_type_name" :label="__('hr_leave_types.fields.name')" :required="true" /><x-forms.input id="leave_type_name" name="name" :value="old('name')" required /></div>
                        <div class="col-md-4"><x-forms.label for="leave_type_payment_status" :label="__('hr_leave_types.fields.payment_status')" :required="true" /><x-forms.select id="leave_type_payment_status" variant="local" name="payment_status" :allow-clear="false" required>@foreach (['paid', 'unpaid'] as $status)<option value="{{ $status }}" @selected(old('payment_status', 'paid') === $status)>{{ __('hr_leave_types.payment_statuses.'.$status) }}</option>@endforeach</x-forms.select></div>
                        <div class="col-md-3"><x-forms.label for="leave_type_annual_entitlement_days" :label="__('hr_leave_types.fields.annual_entitlement_days')" /><x-forms.numeric-input id="leave_type_annual_entitlement_days" min="0" max="366" step="0.001" name="annual_entitlement_days" :scale="3" :value="old('annual_entitlement_days')" /></div>
                        <div class="col-md-3"><x-forms.label for="leave_type_carry_forward_max_days" :label="__('hr_leave_types.fields.carry_forward_max_days')" /><x-forms.numeric-input id="leave_type_carry_forward_max_days" min="0" max="366" step="0.001" name="carry_forward_max_days" :scale="3" :value="old('carry_forward_max_days')" /></div>
                        <div class="col-md-3"><x-forms.label for="leave_type_status" :label="__('hr_leave_types.fields.status')" /><x-forms.select id="leave_type_status" variant="local" name="status" :allow-clear="false">@foreach (['active', 'inactive'] as $status)<option value="{{ $status }}">{{ __('hr_leave_types.statuses.'.$status) }}</option>@endforeach</x-forms.select></div>
                        <div class="col-md-3 d-flex align-items-end"><div class="form-check mb-2"><x-forms.input class="form-check-input" type="checkbox" name="requires_balance" value="1" id="requires_balance" /><label class="form-check-label" for="requires_balance">{{ __('hr_leave_types.fields.requires_balance') }}</label></div></div>
                        <div class="col-12"><x-forms.label for="leave_type_notes" :label="__('hr_leave_types.fields.notes')" /><x-forms.textarea id="leave_type_notes" name="notes" rows="2">{{ old('notes') }}</x-forms.textarea></div>
                        <div class="col-12"><button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button></div>
                    </form>
                </div>
            </div>
        @endcan

        <div class="row g-3">
            @forelse ($leaveTypes as $leaveType)
                <div class="col-12 col-xl-6">
                    <div class="card h-100"><div class="card-body">
                        <div class="d-flex justify-content-between gap-2 mb-2"><div><strong>{{ $leaveType->name }}</strong><div class="small text-muted" dir="ltr">{{ $leaveType->code }}</div></div><div><a href="{{ route('admin.hr.leave-types.show', $leaveType->getKey()) }}">{{ __('hr_leave_types.show') }}</a>@if ($leaveType->trashed())<span class="badge bg-danger ms-2">{{ __('common.trash.trashed') }}</span>@endif</div></div>
                        @if ($leaveType->trashed())
                            @can('hr.leave_types.restore')<form method="POST" action="{{ route('admin.hr.leave-types.restore', $leaveType->getKey()) }}">@csrf @method('PATCH')<button class="btn btn-success w-100" type="submit">{{ __('common.actions.restore') }}</button></form>@endcan
                        @else
                        <form method="POST" action="{{ route('admin.hr.leave-types.update', $leaveType) }}" class="row g-2">
                            @csrf @method('PATCH')
                            <div class="col-4"><x-forms.label class="visually-hidden" :for="'leave_type_code_'.$leaveType->getKey()" :label="__('hr_leave_types.fields.code')" /><x-forms.input class="form-control" :id="'leave_type_code_'.$leaveType->getKey()" name="code" :value="$leaveType->code" :disabled="! auth()->user()?->can('hr.leave_types.update')" required /></div>
                            <div class="col-8"><x-forms.label class="visually-hidden" :for="'leave_type_name_'.$leaveType->getKey()" :label="__('hr_leave_types.fields.name')" /><x-forms.input class="form-control" :id="'leave_type_name_'.$leaveType->getKey()" name="name" :value="$leaveType->name" :disabled="! auth()->user()?->can('hr.leave_types.update')" required /></div>
                            <div class="col-6"><x-forms.label class="visually-hidden" :for="'leave_type_payment_status_'.$leaveType->getKey()" :label="__('hr_leave_types.fields.payment_status')" /><x-forms.select :id="'leave_type_payment_status_'.$leaveType->getKey()" variant="local" name="payment_status" :allow-clear="false" :disabled="! auth()->user()?->can('hr.leave_types.update')">@foreach (['paid', 'unpaid'] as $status)<option value="{{ $status }}" @selected(data_get($leaveType->metadata, 'payment_status', 'paid') === $status)>{{ __('hr_leave_types.payment_statuses.'.$status) }}</option>@endforeach</x-forms.select></div>
                            <div class="col-3"><x-forms.label class="visually-hidden" :for="'leave_type_annual_entitlement_days_'.$leaveType->getKey()" :label="__('hr_leave_types.fields.annual_entitlement_days')" /><x-forms.numeric-input :id="'leave_type_annual_entitlement_days_'.$leaveType->getKey()" min="0" max="366" step="0.001" name="annual_entitlement_days" :scale="3" :value="data_get($leaveType->metadata, 'annual_entitlement_days')" :disabled="! auth()->user()?->can('hr.leave_types.update')" /></div>
                            <div class="col-3"><x-forms.label class="visually-hidden" :for="'leave_type_carry_forward_max_days_'.$leaveType->getKey()" :label="__('hr_leave_types.fields.carry_forward_max_days')" /><x-forms.numeric-input :id="'leave_type_carry_forward_max_days_'.$leaveType->getKey()" min="0" max="366" step="0.001" name="carry_forward_max_days" :scale="3" :value="data_get($leaveType->metadata, 'carry_forward_max_days')" :disabled="! auth()->user()?->can('hr.leave_types.update')" /></div>
                            <div class="col-5"><x-forms.label class="visually-hidden" :for="'leave_type_status_'.$leaveType->getKey()" :label="__('hr_leave_types.fields.status')" /><x-forms.select :id="'leave_type_status_'.$leaveType->getKey()" variant="local" name="status" :allow-clear="false" :disabled="! auth()->user()?->can('hr.leave_types.update')">@foreach (['active', 'inactive'] as $status)<option value="{{ $status }}" @selected($leaveType->status === $status)>{{ __('hr_leave_types.statuses.'.$status) }}</option>@endforeach</x-forms.select></div>
                            <div class="col-7 d-flex align-items-center"><div class="form-check"><x-forms.input class="form-check-input" type="checkbox" name="requires_balance" value="1" :id="'requires_balance_'.$leaveType->getKey()" :checked="$leaveType->requiresBalance()" :disabled="! auth()->user()?->can('hr.leave_types.update')" /><label class="form-check-label" for="requires_balance_{{ $leaveType->getKey() }}">{{ __('hr_leave_types.fields.requires_balance') }}</label></div></div>
                            <div class="col-12"><x-forms.label class="visually-hidden" :for="'leave_type_notes_'.$leaveType->getKey()" :label="__('hr_leave_types.fields.notes')" /><x-forms.textarea :id="'leave_type_notes_'.$leaveType->getKey()" name="notes" rows="2" :disabled="! auth()->user()?->can('hr.leave_types.update')">{{ $leaveType->notes }}</x-forms.textarea></div>
                            @can('hr.leave_types.update')<div class="col-6"><button class="btn btn-primary w-100" type="submit">{{ __('common.actions.save') }}</button></div>@endcan
                        </form>
                        @can('hr.leave_types.delete')<form method="POST" action="{{ route('admin.hr.leave-types.destroy', $leaveType) }}" class="mt-2">@csrf @method('DELETE')<button class="btn btn-outline-danger w-100" type="submit">{{ __('common.actions.delete') }}</button></form>@endcan
                        @endif
                    </div></div>
                </div>
            @empty
                <div class="col-12"><div class="alert alert-info">{{ __('hr_leave_types.messages.empty') }}</div></div>
            @endforelse
        </div>
        <div class="mt-3">{{ $leaveTypes->links() }}</div>
    </div>
@endsection

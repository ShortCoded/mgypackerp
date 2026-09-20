@extends('layouts.app')

@section('title', __('hr_shift_assignments.title'))

@section('content')
    <div class="container-fluid px-0 px-sm-3">
        @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        @if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        @can('hr.shift_assignments.manage')
            <div class="card mb-3">
                <div class="card-header"><h5 class="mb-0">{{ __('hr_shift_assignments.actions.assign') }}</h5></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.hr.shift-assignments.store') }}" class="row g-3">
                        @csrf
                        <div class="col-12 col-lg-5">
                            <label class="form-label" for="shift_assignment_employees">{{ __('hr_shift_assignments.labels.employees') }}</label>
                            <select id="shift_assignment_employees" name="employee_ids[]" class="form-select" multiple required></select>
                        </div>
                        <div class="col-12 col-lg-3">
                            <label class="form-label" for="shift_assignment_shift">{{ __('hr_shift_assignments.labels.shift') }}</label>
                            <select id="shift_assignment_shift" name="shift_doc_num" class="form-select" required>
                                <option value="">{{ __('common.placeholders.select') }}</option>
                                @foreach ($shifts as $shift)<option value="{{ $shift->doc_num }}" @selected(old('shift_doc_num') === $shift->doc_num)>{{ $shift->name }} / {{ $shift->doc_num }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-6 col-lg-2">
                            <label class="form-label" for="shift_assignment_from">{{ __('hr_shift_assignments.labels.effective_from') }}</label>
                            <x-forms.date-input id="shift_assignment_from" name="effective_from" :value="old('effective_from', now()->toDateString())" required />
                        </div>
                        <div class="col-6 col-lg-2">
                            <label class="form-label" for="shift_assignment_to">{{ __('hr_shift_assignments.labels.effective_to') }}</label>
                            <x-forms.date-input id="shift_assignment_to" name="effective_to" :value="old('effective_to')" />
                        </div>
                        <div class="col-12"><button class="btn btn-primary" type="submit">{{ __('hr_shift_assignments.actions.assign') }}</button></div>
                    </form>
                </div>
            </div>
        @endcan

        <div class="card">
            <div class="card-header"><h5 class="mb-0">{{ __('hr_shift_assignments.history') }}</h5></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>{{ __('hr_shift_assignments.labels.employee') }}</th><th>{{ __('hr_shift_assignments.labels.branch') }}</th><th>{{ __('hr_shift_assignments.labels.shift') }}</th><th>{{ __('hr_shift_assignments.labels.effective_from') }}</th><th>{{ __('hr_shift_assignments.labels.effective_to') }}</th><th>{{ __('hr_shift_assignments.labels.status') }}</th><th>{{ __('hr_shift_assignments.labels.actions') }}</th></tr></thead>
                    <tbody>
                    @forelse ($assignments as $assignment)
                        @php($effective = $assignment->effective_from->isPast() && ($assignment->effective_to === null || $assignment->effective_to->isFuture()))
                        <tr>
                            <td>{{ $assignment->employee?->full_name }} <span class="text-muted" dir="ltr">{{ $assignment->employee?->doc_num }}</span></td>
                            <td>{{ $assignment->employee?->branch?->name ?: '—' }}</td>
                            <td>{{ $assignment->shift?->name ?: '—' }}</td>
                            <td dir="ltr">{{ $assignment->effective_from?->toDateString() }}</td>
                            <td dir="ltr">{{ $assignment->effective_to?->toDateString() ?: '—' }}</td>
                            <td><span class="badge badge-subtle-{{ $effective ? 'success' : 'secondary' }}">{{ __('hr_shift_assignments.status.'.($effective ? 'effective' : 'historical')) }}</span></td>
                            <td>
                                @can('hr.shift_assignments.manage')
                                    <form method="POST" action="{{ route('admin.hr.shift-assignments.update', $assignment) }}" class="d-flex gap-2 align-items-center">
                                        @csrf
                                        @method('PATCH')
                                        <x-forms.date-input name="effective_to" :value="$assignment->effective_to?->toDateString()" :min="$assignment->effective_from->toDateString()" required />
                                        <button class="btn btn-sm btn-outline-primary" type="submit">{{ __('hr_shift_assignments.actions.update_end') }}</button>
                                    </form>
                                @else
                                    —
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">{{ __('hr_shift_assignments.empty') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($assignments->hasPages())<div class="card-footer">{{ $assignments->links() }}</div>@endif
        </div>
    </div>
@endsection

@push('scripts')
<script>
    (() => {
        const element = $('#shift_assignment_employees');
        if (!element.length || !$.fn.select2) return;
        element.select2({
            width: '100%',
            minimumInputLength: 1,
            ajax: {
                url: @json(route('admin.hr.select2.employees')),
                dataType: 'json',
                delay: 250,
                data: params => ({q: params.term || '', page: params.page || 1}),
                processResults: data => data,
            },
        });
    })();
</script>
@endpush

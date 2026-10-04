@extends('layouts.app')

@section('title', __('hr_wage_versions.title'))

@section('content')
    @php
        $dates = app(\Modules\Core\Services\DateFormatService::class);
        $numbers = app(\Modules\Core\Services\NumericFormatService::class);
        $rateField = match ($employee->pay_basis) {
            'monthly_salary' => 'basic_salary',
            'weekly_wage', 'daily_wage', 'hourly_wage', 'shift_wage', 'piece_rate' => $employee->pay_basis,
            default => null,
        };
    @endphp
    <div class="container-fluid px-0 px-sm-3">
        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h5 class="mb-0">{{ __('hr_wage_versions.title') }}</h5>
                    <div class="text-muted small">{{ $employee->full_name }} / <span dir="ltr">{{ $employee->doc_num }}</span></div>
                </div>
                <a href="{{ route('admin.hr.employees.show', $employee->doc_num) }}" class="btn btn-falcon-default btn-sm">{{ __('common.actions.back') }}</a>
            </div>
            @if (session('success'))<div class="card-body pb-0"><div class="alert alert-success mb-0">{{ session('success') }}</div></div>@endif
            @if ($errors->any())<div class="card-body pb-0"><div class="alert alert-danger mb-0"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>@endif
            <div class="card-body">
                <p class="text-muted small">{{ __('hr_wage_versions.intro') }}</p>
                <div class="mb-3"><strong>{{ __('hr_wage_versions.labels.pay_basis') }}:</strong> {{ __('hr.employees.pay_basis.'.$employee->pay_basis) }}</div>
                @can('hr.employees.edit')
                    @if ($rateField !== null)
                        <form method="POST" action="{{ route('admin.hr.employees.wage-versions.store', $employee->doc_num) }}" class="row g-3" data-wage-version-form novalidate>
                            @csrf
                            <div class="col-md-3">
                                <x-forms.label for="wage_effective_from" :label="__('hr_wage_versions.labels.effective_from')" required />
                                <x-forms.date-input id="wage_effective_from" name="effective_from" :value="old('effective_from', now()->toDateString())" />
                            </div>
                            <div class="col-md-3">
                                <x-forms.label for="wage_pay_basis" :label="__('hr_wage_versions.labels.pay_basis')" required />
                                <x-forms.select id="wage_pay_basis" name="pay_basis" variant="local" :allow-clear="false">
                                    @foreach(\Modules\HR\Services\HrEmployeeWageVersionService::PayBases as $basis)
                                        <option value="{{ $basis }}" @selected(old('pay_basis', $employee->pay_basis) === $basis)>{{ __('hr.employees.pay_basis.'.$basis) }}</option>
                                    @endforeach
                                </x-forms.select>
                            </div>
                            <div class="col-md-2">
                                <x-forms.label for="wage_rate" :label="__('hr_wage_versions.labels.rate')" required />
                                <x-forms.numeric-input id="wage_rate" name="rate" :scale="4" step="0.0001" min="0" :value="old('rate')" />
                            </div>
                            <div class="col-md-4">
                                <x-forms.label for="wage_reason" :label="__('hr_wage_versions.labels.reason')" required />
                                <x-forms.textarea id="wage_reason" name="reason" rows="2">{{ old('reason') }}</x-forms.textarea>
                            </div>
                            <div class="col-12"><button type="submit" class="btn btn-primary">{{ __('hr_wage_versions.record') }}</button></div>
                        </form>
                    @endif
                @endcan
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h6 class="mb-0">{{ __('hr_wage_versions.history') }}</h6></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr>
                        <th>{{ __('hr_wage_versions.labels.effective_from') }}</th>
                        <th>{{ __('hr_wage_versions.labels.effective_to') }}</th>
                        <th>{{ __('hr_wage_versions.labels.pay_basis') }}</th>
                        <th>{{ __('hr_wage_versions.labels.rate') }}</th>
                        <th>{{ __('hr_wage_versions.labels.reason') }}</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($versions as $version)
                            @php($versionRateField = match ($version->pay_basis) {
                                'monthly_salary' => 'basic_salary',
                                'weekly_wage', 'daily_wage', 'hourly_wage', 'shift_wage', 'piece_rate' => $version->pay_basis,
                                default => null,
                            })
                            <tr>
                                <td dir="ltr">{{ $dates->formatDate($version->effective_from, '') }}</td>
                                <td dir="ltr">{{ $dates->formatDate($version->effective_to, '—') }}</td>
                                <td>{{ $version->pay_basis ? __('hr.employees.pay_basis.'.$version->pay_basis) : __('hr_wage_versions.legacy') }}</td>
                                <td dir="ltr">{{ $versionRateField !== null ? $numbers->format($version->{$versionRateField}) : '—' }}</td>
                                <td>{{ $version->reason ?: '—' }}</td>
                            </tr>
                            @if ($version->pay_basis === null && auth()->user()?->can('hr.employees.edit') && $rateField !== null)
                                <tr><td colspan="5">
                                    <form method="POST" action="{{ route('admin.hr.employees.wage-versions.verify', [$employee->doc_num, $version->id]) }}" class="row g-2 align-items-end" data-wage-version-form novalidate>
                                        @csrf
                                        <div class="col-md-3">
                                            <x-forms.label :for="'legacy_basis_'.$version->id" :label="__('hr_wage_versions.labels.pay_basis')" required />
                                            <x-forms.select :id="'legacy_basis_'.$version->id" name="pay_basis" variant="local" :allow-clear="false">
                                                @foreach(\Modules\HR\Services\HrEmployeeWageVersionService::PayBases as $basis)
                                                    <option value="{{ $basis }}" @selected(old('pay_basis', $employee->pay_basis) === $basis)>{{ __('hr.employees.pay_basis.'.$basis) }}</option>
                                                @endforeach
                                            </x-forms.select>
                                        </div>
                                        <div class="col-md-2">
                                            <x-forms.label :for="'legacy_rate_'.$version->id" :label="__('hr_wage_versions.labels.rate')" required />
                                            <x-forms.numeric-input :id="'legacy_rate_'.$version->id" name="rate" :scale="4" step="0.0001" min="0" />
                                        </div>
                                        <div class="col-md-5">
                                            <x-forms.label :for="'legacy_reason_'.$version->id" :label="__('hr_wage_versions.labels.reason')" required />
                                            <x-forms.textarea :id="'legacy_reason_'.$version->id" name="reason" rows="2"></x-forms.textarea>
                                        </div>
                                        <div class="col-md-2"><button type="submit" class="btn btn-falcon-primary w-100">{{ __('hr_wage_versions.verify') }}</button></div>
                                    </form>
                                </td></tr>
                            @endif
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">{{ __('hr_wage_versions.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($versions->hasPages())<div class="card-footer">{{ $versions->links() }}</div>@endif
        </div>
    </div>
@endsection

@push('scripts')
<script>
    document.querySelectorAll('[data-wage-version-form]').forEach(function (form) {
        const basis = form.querySelector('[name="pay_basis"]');
        const rate = form.querySelector('[name="rate"]');
        if (!basis || !rate) return;
        const updateScale = function () {
            const monthly = basis.value === 'monthly_salary';
            rate.setAttribute('data-numeric-scale', monthly ? '2' : '4');
            rate.setAttribute('step', monthly ? '0.01' : '0.0001');
        };
        basis.addEventListener('change', updateScale);
        updateScale();
    });
</script>
@endpush

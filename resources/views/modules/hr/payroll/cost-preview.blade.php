@extends('layouts.app')

@section('title', __('hr_payroll.cost_preview.title'))
@inject('numbers', 'Modules\Core\Services\NumericFormatService')

@section('content')
    @php($dates = app(\Modules\Core\Services\DateFormatService::class))
    <div class="container-fluid w-100 mw-100 m-0 p-0 hr-cycle-shell">
        <div class="card mb-3">
            <div class="card-body p-3">
                <div class="text-muted small mb-1">{{ __('hr_payroll.cost_preview.kicker') }}</div>
                <h2 class="h4 mb-2">{{ __('hr_payroll.cost_preview.title') }} #{{ $run->id }}</h2>
                <p class="text-muted mb-3">{{ __('hr_payroll.cost_preview.description') }}</p>
                <div class="d-flex flex-wrap gap-3 align-items-center">
                    <a class="btn btn-outline-primary btn-sm" href="{{ route('admin.hr.payroll-preparation.index', ['run' => $run->id]) }}"><span class="fas fa-arrow-right me-1"></span>{{ __('hr_payroll.cost_preview.back') }}</a>
                    <span class="text-muted"><span class="fas fa-calendar-alt me-1"></span>{{ $dates->formatDate($run->period_start, '—') }} — {{ $dates->formatDate($run->period_end, '—') }}</span>
                </div>
            </div>
        </div>

        <div class="card hr-section-card">
        <div class="card-body">
            @if(in_array($run->status, ['draft', 'calculated'], true) && auth()->user()->can('hr.payroll_preparation.calculate') && collect($lines)->contains('stored', true))
                <form method="POST" action="{{ route('admin.hr.payroll-runs.cost-allocations.recalculate', $run->id) }}" class="mb-3" novalidate>
                    @csrf
                    <button type="submit" class="btn btn-outline-primary">{{ __('hr_payroll.cost_preview.recalculate') }}</button>
                </form>
            @endif
            @if($errors !== [])
                <div class="alert alert-danger">
                    <strong>{{ __('hr_payroll.cost_preview.blocked') }}</strong>
                    <ul class="mb-0">@foreach($errors as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @else
                <div class="alert alert-success d-flex gap-2 align-items-center"><span class="fas fa-check-circle"></span><span>{{ __('hr_payroll.cost_preview.ready') }}</span></div>
            @endif
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr>
                        <th>{{ __('hr_payroll.cost_preview.employee') }}</th><th>{{ __('hr_payroll.cost_preview.item') }}</th><th>{{ __('hr_payroll.cost_preview.account') }}</th>
                        <th>{{ __('hr_payroll.cost_preview.classification') }}</th><th>{{ __('hr_payroll.cost_preview.department') }}</th><th>{{ __('hr_payroll.cost_preview.cost_center') }}</th>
                        <th>{{ __('hr_payroll.cost_preview.allocation') }}</th><th class="text-end">{{ __('hr_payroll.cost_preview.amount') }}</th>
                    </tr></thead>
                    <tbody>
                        @forelse($lines as $line)
                            <tr>
                                <td>{{ $line['employee'] }}</td><td>{{ $line['payroll_item'] }}</td><td>{{ $line['account'] }}</td>
                                <td>{{ $line['classification_label'] }}</td><td>{{ $line['department'] ?: '—' }}</td>
                                <td>{{ $line['cost_center'] }}</td><td><span class="badge badge-subtle-primary">{{ __('hr_payroll.cost_preview.types.'.$line['allocation_type']) }}</span> <span dir="ltr">{{ $numbers->format($line['percentage']) }}%</span></td>
                                <td class="text-end" dir="ltr">{{ $numbers->format($line['amount']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">{{ __('hr_payroll.cost_preview.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        </div>
    </div>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/HR/hr-cycle.css') }}">
@endpush

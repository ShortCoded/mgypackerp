@extends('layouts.app')

@section('title', __('Payroll Cost Preview'))

@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="mb-1">{{ __('Payroll Cost Preview') }} #{{ $run->id }}</h5>
            <div class="text-muted">{{ $run->period_start }} — {{ $run->period_end }}</div>
        </div>
        <div class="card-body">
            @if($errors !== [])
                <div class="alert alert-danger">
                    <ul class="mb-0">@foreach($errors as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr>
                        <th>{{ __('Employee') }}</th><th>{{ __('Payroll Item') }}</th><th>{{ __('Account') }}</th>
                        <th>{{ __('Account Classification') }}</th><th>{{ __('Department') }}</th><th>{{ __('Cost Center') }}</th>
                        <th>{{ __('Allocation') }}</th><th class="text-end">{{ __('Amount') }}</th>
                    </tr></thead>
                    <tbody>
                        @forelse($lines as $line)
                            <tr>
                                <td>{{ $line['employee'] }}</td><td>{{ $line['payroll_item'] }}</td><td>{{ $line['account'] }}</td>
                                <td><code>{{ $line['classification'] }}</code></td><td>{{ $line['department'] ?: '—' }}</td>
                                <td>{{ $line['cost_center'] }}</td><td>{{ $line['allocation_type'] }} / {{ $line['percentage'] }}%</td>
                                <td class="text-end" dir="ltr">{{ $line['amount'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">{{ __('No payroll cost lines found.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

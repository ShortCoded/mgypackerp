@extends('layouts.app')

@section('title', __('maintenance.plans.title'))

@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
@endphp

@section('content')
    <div class="production-mobile-workflow">
        @if ($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        @can('maintenance.plans.create')
            <form class="card mb-3" method="POST" action="{{ route('admin.maintenance.plans.store') }}">
                @csrf
                <div class="card-header"><h5 class="mb-0">{{ __('maintenance.plans.create') }}</h5><p class="small text-600 mb-0">{{ __('maintenance.plans.approval_help') }}</p></div>
                <div class="card-body"><div class="row g-3">
                    <div class="col-md-4"><label class="form-label">{{ __('maintenance.fields.plan_name') }}</label><x-forms.input name="name" :value="old('name')" required /></div>
                    <div class="col-md-4"><label class="form-label">{{ __('maintenance.fields.asset') }}</label><x-forms.select name="fixed_asset_id"><option value="">{{ __('maintenance.asset_or_mold') }}</option>@foreach($assets as $asset)<option value="{{ $asset->id }}" @selected(old('fixed_asset_id') == $asset->id)>{{ $asset->doc_num }} — {{ $asset->asset_name }}</option>@endforeach</x-forms.select></div>
                    <div class="col-md-4"><label class="form-label">{{ __('maintenance.fields.mold') }}</label><x-forms.select name="production_mold_id"><option value="">{{ __('maintenance.asset_or_mold') }}</option>@foreach($molds as $mold)<option value="{{ $mold->id }}" @selected(old('production_mold_id') == $mold->id)>{{ $mold->code }} — {{ $mold->name }}</option>@endforeach</x-forms.select></div>
                    <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.maintenance_type') }}</label><x-forms.select name="maintenance_type" required>@foreach(['preventive', 'condition_based'] as $type)<option value="{{ $type }}" @selected(old('maintenance_type', 'preventive') === $type)>{{ __('maintenance.maintenance_types.'.$type) }}</option>@endforeach</x-forms.select></div>
                    <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.discipline') }}</label><x-forms.select name="discipline"><option value="">—</option>@foreach(['electrical', 'mechanical', 'molds', 'other'] as $discipline)<option value="{{ $discipline }}" @selected(old('discipline') === $discipline)>{{ __('maintenance.disciplines.'.$discipline) }}</option>@endforeach</x-forms.select></div>
                    <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.service_mode') }}</label><x-forms.select name="service_mode" required>@foreach(['internal', 'external', 'mixed'] as $mode)<option value="{{ $mode }}" @selected(old('service_mode', 'internal') === $mode)>{{ __('maintenance.service_modes.'.$mode) }}</option>@endforeach</x-forms.select></div>
                    <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.supplier') }}</label><x-forms.select name="supplier_id"><option value="">—</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected(old('supplier_id') == $supplier->id)>{{ $supplier->doc_num }} — {{ $supplier->name }}</option>@endforeach</x-forms.select></div>
                    <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.external_provider_name') }}</label><x-forms.input name="external_provider_name" :value="old('external_provider_name')" /></div>
                    <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.frequency_basis') }}</label><x-forms.select name="frequency_basis" required>@foreach(['calendar', 'operating_hours', 'cycles', 'condition'] as $basis)<option value="{{ $basis }}" @selected(old('frequency_basis', 'calendar') === $basis)>{{ __('maintenance.frequency_bases.'.$basis) }}</option>@endforeach</x-forms.select></div>
                    <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.interval_value') }}</label><x-forms.input name="interval_value" type="number" min="0.0001" step="0.0001" :value="old('interval_value', 30)" /></div>
                    <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.schedule_anchor') }}</label><x-forms.select name="schedule_anchor" required>@foreach(['planned', 'actual'] as $anchor)<option value="{{ $anchor }}" @selected(old('schedule_anchor', 'planned') === $anchor)>{{ __('maintenance.schedule_anchors.'.$anchor) }}</option>@endforeach</x-forms.select></div>
                    <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.next_due_at') }}</label><x-forms.date-input name="next_due_at" enable-time :value="old('next_due_at')" /></div>
                    <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.next_meter_value') }}</label><x-forms.input name="next_meter_value" type="number" min="0" step="0.0001" :value="old('next_meter_value')" /></div>
                    <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.expected_duration_minutes') }}</label><x-forms.input name="expected_duration_minutes" type="number" min="1" :value="old('expected_duration_minutes')" /></div>
                    <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.estimated_cost') }}</label><x-forms.input name="estimated_cost" type="number" min="0" step="0.0001" :value="old('estimated_cost', 0)" /></div>
                    <div class="col-12"><label class="form-label">{{ __('maintenance.fields.task_template') }}</label><x-forms.textarea name="task_template" rows="3" required>{{ old('task_template') }}</x-forms.textarea></div>
                </div></div>
                <div class="card-footer text-end"><button class="btn btn-primary">{{ __('maintenance.plans.create') }}</button></div>
            </form>
        @endcan

        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">{{ __('maintenance.plans.title') }}</h5></div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>{{ __('maintenance.fields.document') }}</th><th>{{ __('maintenance.fields.plan_name') }}</th><th>{{ __('maintenance.fields.asset') }}</th><th>{{ __('maintenance.fields.frequency_basis') }}</th><th>{{ __('maintenance.fields.next_due') }}</th><th>{{ __('maintenance.fields.status') }}</th><th></th></tr></thead><tbody>
                @forelse($plans as $plan)
                    <tr>
                        <td>{{ $plan->doc_num }}</td><td>{{ $plan->name }}</td><td>{{ $plan->asset ? $plan->asset->doc_num.' — '.$plan->asset->asset_name : ($plan->mold ? $plan->mold->code.' — '.$plan->mold->name : '—') }}</td>
                        <td>{{ __('maintenance.frequency_bases.'.$plan->frequency_basis) }} · {{ $plan->interval_value ?: '—' }} · {{ __('maintenance.schedule_anchors.'.$plan->schedule_anchor) }}</td>
                        <td>{{ $plan->frequency_basis === 'calendar' ? $dates->formatDateTime($plan->next_due_at, '—') : ($plan->next_meter_value ?? __('maintenance.frequency_bases.condition')) }}</td>
                        <td>{{ __('maintenance.plan_statuses.'.$plan->status) }}</td>
                        <td><div class="d-flex flex-wrap gap-1">
                            @if($plan->status === 'draft')@can('maintenance.plans.approve')<button class="btn btn-sm btn-primary" type="button" data-action="post" data-url="{{ route('admin.maintenance.plans.approve', $plan) }}">{{ __('maintenance.actions.approve') }}</button>@endcan @endif
                            @if($plan->status === 'approved')@can('maintenance.plans.generate')<button class="btn btn-sm btn-falcon-default" type="button" data-action="post" data-url="{{ route('admin.maintenance.plans.generate', $plan) }}">{{ __('maintenance.actions.generate_due') }}</button>@endcan @endif
                        </div></td>
                    </tr>
                    @if($plan->status === 'approved' && $plan->frequency_basis !== 'calendar')
                        @can('maintenance.plans.readings')
                            <tr><td colspan="7" class="bg-light">
                                <form class="row g-2 align-items-end" method="POST" action="{{ route('admin.maintenance.plans.readings.store', $plan) }}">
                                    @csrf
                                    <x-forms.input type="hidden" name="basis" :value="$plan->frequency_basis" />
                                    <x-forms.input type="hidden" name="idempotency_key" :value="(string) \Illuminate\Support\Str::uuid()" />
                                    <div class="col-md-2"><label class="form-label">{{ __('maintenance.fields.reading_value') }}</label><x-forms.input name="reading_value" type="number" min="0" step="0.0001" /></div>
                                    @if($plan->frequency_basis === 'condition')<div class="col-md-2"><div class="form-check mb-2"><x-forms.input class="form-check-input" type="checkbox" name="is_triggered" value="1" id="condition_{{ $plan->id }}" /><label class="form-check-label" for="condition_{{ $plan->id }}">{{ __('maintenance.fields.condition_triggered') }}</label></div></div>@endif
                                    <div class="col-md-2"><label class="form-label">{{ __('maintenance.fields.reading_type') }}</label><x-forms.select name="reading_type" required>@foreach(['reading', 'correction', 'replacement'] as $type)<option value="{{ $type }}">{{ __('maintenance.reading_types.'.$type) }}</option>@endforeach</x-forms.select></div>
                                    <div class="col-md-2"><label class="form-label">{{ __('maintenance.fields.recorded_at') }}</label><x-forms.date-input name="recorded_at" enable-time :value="now()->format('Y-m-d H:i')" required /></div>
                                    <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.notes') }}</label><x-forms.input name="notes" /></div>
                                    <div class="col-md-1"><button class="btn btn-primary w-100">{{ __('maintenance.actions.record') }}</button></div>
                                </form>
                            </td></tr>
                        @endcan
                    @endif
                @empty<tr><td colspan="7" class="text-center text-600">{{ __('maintenance.plans.none') }}</td></tr>@endforelse
            </tbody></table></div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="mb-0">{{ __('maintenance.plans.due_title') }}</h5></div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>{{ __('maintenance.fields.plan_name') }}</th><th>{{ __('maintenance.fields.asset') }}</th><th>{{ __('maintenance.fields.due_at') }}</th><th>{{ __('maintenance.fields.meter_target') }}</th><th>{{ __('maintenance.fields.work_order') }}</th><th>{{ __('maintenance.fields.status') }}</th><th></th></tr></thead><tbody>
                @forelse($dues as $due)<tr><td>{{ $due->plan->doc_num }} — {{ $due->plan->name }}</td><td>{{ $due->plan->asset?->asset_name ?? $due->plan->mold?->name ?? '—' }}</td><td>{{ $dates->formatDateTime($due->due_at, '') }}</td><td>{{ $due->meter_target ?? '—' }}</td><td>@if($due->workOrder)<a href="{{ route('admin.maintenance.orders.show', $due->workOrder) }}">{{ $due->workOrder->doc_num }}</a>@else—@endif</td><td>{{ __('maintenance.due_statuses.'.$due->status) }}</td><td>@if($due->status === 'open')@can('maintenance.plans.execute')<button class="btn btn-sm btn-primary" type="button" data-action="post" data-url="{{ route('admin.maintenance.plans.dues.convert', $due) }}">{{ __('maintenance.actions.create_order') }}</button>@endcan @endif</td></tr>@empty<tr><td colspan="7" class="text-center text-600">{{ __('maintenance.plans.no_dues') }}</td></tr>@endforelse
            </tbody></table></div>
        </div>
    </div>
@endsection

@push('styles')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endpush
@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endpush

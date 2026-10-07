@extends('layouts.app')
@section('title', __('production_stage_transfer.title').' — '.$record->run_number)
@section('content')
@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $service = app(\Modules\Production\Services\ProductionStageTransferService::class);
@endphp
<div class="production-mobile-workflow">
    <div class="card mb-3"><div class="card-header"><h5>{{ __('production_stage_transfer.title') }} — {{ $record->run_number }}</h5></div>
        <div class="card-body"><p>{{ __('production_stage_transfer.help') }}</p><p>{{ __('production_stage_transfer.scope_help') }}</p>
            <a href="{{ route('admin.production.runs.show', $record) }}">{{ __('common.actions.view') }} — {{ $record->run_number }}</a>
            @if($service->isManaged($record))<span class="mx-2">·</span><a href="{{ route('admin.production.runs.stage-output-costs.index', $record) }}">{{ __('production_stage_transfer.output_cost_title') }}</a>@endif
        </div>
    </div>
    @if($errors->any())<div class="alert alert-danger">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
    @if($blocker)<div class="alert alert-warning">{{ $blocker }}</div>@endif
    @if($schemaReady && $record->material_accounting_mode === 'legacy' && !in_array($record->status, ['completed', 'cancelled'], true))
    @can('production.runs.account_materials')
    @can('production.runs.progress')
    <form class="card mb-3" method="POST" action="{{ route('admin.production.runs.output-evidence', $record) }}">@csrf
        <x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" />
        <x-forms.input type="hidden" name="execution_structure" value="physical_route" />
        <div class="card-body"><h6>{{ __('production_stage_transfer.enroll') }}</h6><p>{{ __('production_stage_transfer.enroll_help') }}</p>
            @foreach($record->requirements as $index => $requirement)
            <div class="row g-2 mb-2"><div class="col-md-6">{{ $requirement->product?->name }} — {{ $requirement->unit?->name }}</div><div class="col-md-6">
                <x-forms.input type="hidden" name="requirements[{{ $index }}][requirement_public_id]" :value="$requirement->public_id" />
                <x-forms.select variant="local" name="requirements[{{ $index }}][basis]" required>
                    <option value="measured_material">{{ __('production_execution.evidence.measured_material') }}</option>
                    <option value="output_components">{{ __('production_execution.evidence.output_components') }}</option>
                </x-forms.select>
            </div></div>
            @endforeach
        </div><div class="card-footer"><button class="btn btn-primary" type="submit">{{ __('production_stage_transfer.enroll') }}</button></div>
    </form>
    @endcan
    @endcan
    @endif
    @if($schemaReady && $service->isManaged($record) && $targets->isNotEmpty())
    <form class="card mb-3" method="GET" action="{{ route('admin.production.runs.stage-transfers.index', $record) }}">
        <div class="card-body row g-2"><div class="col-md-8"><x-forms.label :label="__('production_stage_transfer.target')" /><x-forms.select variant="local" name="target" required>
            <option value="">—</option>@foreach($targets as $candidate)<option value="{{ $candidate->id }}" @selected($target?->id === $candidate->id)>{{ $candidate->run_number }} — {{ $candidate->stageSnapshot?->stage_name }}</option>@endforeach
        </x-forms.select></div><div class="col-md-4 align-self-end"><button class="btn btn-falcon-default" type="submit">{{ __('production_stage_transfer.preview') }}</button></div></div>
    </form>
    @endif
    @if($preview)
    <div class="card mb-3"><div class="card-body row g-2"><div class="col-md-4">{{ __('production_stage_transfer.available') }}: {{ $numbers->format($preview['available_quantity']) }}</div>
        <div class="col-md-4">{{ __('production_stage_transfer.quality') }}: {{ $numbers->format($preview['quality_quantity']) }}</div><div class="col-md-4">{{ __('production_stage_transfer.wip') }}: {{ $numbers->format($preview['source_wip']) }}</div></div></div>
    @can('production.runs.account_materials')
    <form class="card mb-3" method="POST" action="{{ route('admin.production.runs.stage-transfers.prepare', $record) }}">@csrf
        <x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" />
        <x-forms.input type="hidden" name="fingerprint" :value="$preview['fingerprint']" /><x-forms.input type="hidden" name="target_run_id" :value="$target->id" />
        <div class="card-body row g-3"><div class="col-md-4"><x-forms.label :label="__('production_stage_transfer.quantity').' — '.$record->product?->unit?->name" required /><x-forms.numeric-input name="base_quantity" :value="old('base_quantity')" :scale="8" min="0.00000001" required /></div>
            <div class="col-md-4"><x-forms.label :label="__('production_execution.fields.reason')" required /><x-forms.textarea name="reason" minlength="5" maxlength="2000" required>{{ old('reason') }}</x-forms.textarea></div>
            <div class="col-md-4"><x-forms.label :label="__('production_stage_transfer.evidence')" required /><x-forms.textarea name="evidence" minlength="5" maxlength="2000" required>{{ old('evidence') }}</x-forms.textarea></div>
        </div><div class="card-footer"><button class="btn btn-primary" type="submit">{{ __('production_stage_transfer.prepare') }}</button></div>
    </form>
    @endcan
    @endif
    @foreach($transfers ?? [] as $owner)
    <div class="card mb-3"><div class="card-header">{{ $owner->sourceRun?->run_number }} → {{ $owner->targetRun?->run_number }} <span class="badge bg-secondary">{{ __('production_stage_transfer.status_'.$owner->status) }}</span></div>
        <div class="card-body"><div>{{ __('production_stage_transfer.quantity') }}: {{ $numbers->format($owner->base_quantity) }} — {{ __('production_stage_transfer.cost') }}: {{ $numbers->format($owner->total_cost) }}</div>
            @foreach($owner->posting_snapshot['cost_components'] ?? [] as $kind => $amount)<span class="d-inline-block me-3">{{ __('production_stage_transfer.component_'.$kind) }}: {{ $numbers->format($amount) }}</span>@endforeach
            <div>{{ __('production_stage_transfer.booked_amount') }}: {{ $numbers->format($owner->booked_amount) }} · {{ __('production_stage_transfer.rounding_difference') }}: {{ $numbers->formatWithMinimumDecimals($owner->posting_snapshot['rounding_difference'] ?? '0', 8) }}</div>
            <div>{{ $owner->reason }}</div><div>{{ $owner->evidence }}</div></div>
        @can('production.runs.correct_approve')
        @if($owner->status === 'prepared')
        <form class="card-footer" method="POST" action="{{ route('admin.production.runs.stage-transfers.reject', [$record, $owner]) }}">
            @csrf
            <x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" />
            <x-forms.label :label="__('production_execution.fields.reason')" required />
            <x-forms.textarea name="reason" minlength="5" maxlength="2000" required />
            <button class="btn btn-outline-danger mt-2" type="submit">{{ __('production_stage_transfer.reject_proposal') }}</button>
        </form>
        @endif
        @if((int) $owner->prepared_by !== (int) auth()->id() && $owner->status === 'prepared')
        <form method="POST" action="{{ route('admin.production.runs.stage-transfers.approve', [$record, $owner]) }}" class="card-footer">@csrf<x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" /><button class="btn btn-success" type="submit">{{ __('production_stage_transfer.approve') }}</button></form>
        @elseif((int) $owner->prepared_by !== (int) auth()->id() && $owner->status === 'posted')
        <form method="POST" action="{{ route('admin.production.runs.stage-transfers.reverse', [$record, $owner]) }}" class="card-footer">@csrf<x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" /><x-forms.label :label="__('production_execution.fields.reason')" required /><x-forms.textarea name="reason" minlength="5" maxlength="2000" required /><button class="btn btn-warning mt-2" type="submit">{{ __('production_stage_transfer.reverse') }}</button></form>
        @endif
        @endcan
    </div>
    @endforeach
    {{ $transfers?->links() }}
</div>
@endsection

@extends('layouts.app')
@section('title', __('production_stage_transfer.output_cost_title').' — '.$record->run_number)
@section('content')
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
<div class="production-mobile-workflow">
    <div class="card mb-3"><div class="card-header"><h5>{{ __('production_stage_transfer.output_cost_title') }} — {{ $record->run_number }}</h5></div>
        <div class="card-body"><p>{{ __('production_stage_transfer.output_cost_help') }}</p>
            <a href="{{ route('admin.production.runs.stage-transfers.index', $record) }}">{{ __('production_stage_transfer.title') }}</a>
            <span class="mx-2">·</span><a href="{{ route('admin.production.runs.show', $record) }}">{{ __('common.actions.view') }} — {{ $record->run_number }}</a>
        </div>
    </div>
    @if($errors->any())<div class="alert alert-danger">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
    @if($blocker)<div class="alert alert-warning">{{ $blocker }}</div>@endif
    @if($preview)
    <div class="card mb-3"><div class="card-body row g-3">
        @foreach($preview['quantities'] as $kind => $quantity)<div class="col-md-3">{{ __('production_stage_transfer.output_'.$kind) }}: {{ $numbers->format($quantity) }}</div>@endforeach
        @foreach($preview['components'] as $kind => $amount)<div class="col-md-3">{{ __('production_stage_transfer.component_'.$kind) }}: {{ $numbers->format($amount) }}</div>@endforeach
        <div class="col-md-6">{{ __('production_stage_transfer.held_cost') }}: {{ $numbers->format(app(\Modules\Production\Services\ProductionStageCostComponentService::class)->sum(app(\Modules\Production\Services\ProductionStageCostComponentService::class)->add($preview['position']['held_components']['rejected'], $preview['position']['held_components']['rework']))) }}</div>
        <div class="col-md-6">{{ __('production_stage_transfer.loss_cost') }}: {{ $numbers->format($preview['position']['expensed_cost']) }}</div>
    </div></div>
    @if(!$activeOwner && collect($preview['quantities'])->except('good')->contains(fn ($quantity) => bccomp($quantity, '0', 8) > 0))
    @can('production.runs.account_materials')
    <form class="card mb-3" method="POST" action="{{ route('admin.production.runs.stage-output-costs.prepare', $record) }}">@csrf
        <x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" />
        <x-forms.input type="hidden" name="fingerprint" :value="$preview['fingerprint']" />
        <div class="card-body row g-3">
            <div class="col-12"><p>{{ __('production_stage_transfer.allocation_basis_help') }}</p><label class="form-check-label">
                <x-forms.input type="checkbox" class="form-check-input me-2" name="completed_stage_units" value="1" required :checked="(bool) old('completed_stage_units')" />{{ __('production_stage_transfer.completed_stage_units') }}
            </label></div>
            <div class="col-md-6"><x-forms.label :label="__('production_stage_transfer.scrap_treatment')" required />
                <x-forms.select variant="local" name="scrap_treatment" required><option value="">—</option>
                    @foreach(['none', 'normal', 'abnormal'] as $treatment)
                    <option value="{{ $treatment }}" @selected(old('scrap_treatment') === $treatment) @disabled((bccomp($preview['quantities']['scrap'], '0', 8) > 0) === ($treatment === 'none'))>{{ __('production_stage_transfer.scrap_'.$treatment) }}</option>
                    @endforeach
                </x-forms.select>
            </div>
            @foreach(['rejected' => 'hold', 'rework' => 'rework', 'scrap' => 'scrap'] as $kind => $disposition)
            @if(bccomp($preview['quantities'][$kind], '0', 8) > 0)
            <div class="col-md-6"><x-forms.label :label="__('production_stage_transfer.output_'.$kind).' — '.__('production_stage_transfer.quality_evidence')" required />
                <x-forms.select variant="local" name="quality_ids[{{ $kind }}]" required><option value="">—</option>
                    @foreach($quality->where('disposition', $disposition) as $inspection)
                    <option value="{{ $inspection->id }}" @selected((string) old('quality_ids.'.$kind) === (string) $inspection->id)>{{ $inspection->doc_num }} — {{ $numbers->format($inspection->affected_base_quantity) }}</option>
                    @endforeach
                </x-forms.select>
            </div>
            @endif
            @endforeach
            <div class="col-md-6"><x-forms.label :label="__('production_execution.fields.reason')" required /><x-forms.textarea name="reason" minlength="5" maxlength="2000" required>{{ old('reason') }}</x-forms.textarea></div>
            <div class="col-md-6"><x-forms.label :label="__('production_stage_transfer.allocation_evidence')" required /><x-forms.textarea name="evidence" minlength="5" maxlength="2000" required>{{ old('evidence') }}</x-forms.textarea></div>
        </div><div class="card-footer"><button class="btn btn-primary" type="submit">{{ __('production_stage_transfer.prepare_output_cost') }}</button></div>
    </form>
    @endcan
    @endif
    @endif
    @if($activeOwner && $recovery && collect($recovery['position']['held_quantities'])->contains(fn ($quantity) => bccomp($quantity, '0', 8) > 0))
    @can('production.runs.account_materials')
    <form class="card mb-3" method="POST" action="{{ route('admin.production.runs.stage-output-costs.recover', [$record, $activeOwner]) }}">@csrf
        <x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" /><x-forms.input type="hidden" name="fingerprint" :value="$recovery['fingerprint']" />
        <div class="card-header">{{ __('production_stage_transfer.recover_held') }}</div><div class="card-body row g-3"><div class="col-12">{{ __('production_stage_transfer.recover_help') }}</div>
            <div class="col-md-4"><x-forms.label :label="__('production_stage_transfer.output_kind')" required /><x-forms.select variant="local" name="output_kind" required>
                @foreach($recovery['position']['held_quantities'] as $kind => $quantity)@if(bccomp($quantity, '0', 8) > 0)<option value="{{ $kind }}">{{ __('production_stage_transfer.output_'.$kind) }} — {{ $numbers->format($quantity) }}</option>@endif @endforeach
            </x-forms.select></div>
            <div class="col-md-4"><x-forms.label :label="__('production_stage_transfer.quantity')" required /><x-forms.numeric-input name="base_quantity" :scale="8" min="0.00000001" required /></div>
            <div class="col-md-4"><x-forms.label :label="__('production_stage_transfer.quality_evidence')" required /><x-forms.select variant="local" name="quality_inspection_id" required><option value="">—</option>
                @foreach($quality->where('result', 'passed')->where('disposition', 'release') as $inspection)<option value="{{ $inspection->id }}">{{ $inspection->doc_num }} — {{ $numbers->format($inspection->affected_base_quantity) }}</option>@endforeach
            </x-forms.select></div>
            <div class="col-md-6"><x-forms.label :label="__('production_execution.fields.reason')" required /><x-forms.textarea name="reason" minlength="5" maxlength="2000" required /></div>
            <div class="col-md-6"><x-forms.label :label="__('production_stage_transfer.allocation_evidence')" required /><x-forms.textarea name="evidence" minlength="5" maxlength="2000" required /></div>
        </div><div class="card-footer"><button class="btn btn-primary" type="submit">{{ __('production_stage_transfer.prepare_recovery') }}</button></div>
    </form>
    @endcan
    @endif
    @foreach($owners ?? [] as $owner)
    <div class="card mb-3"><div class="card-header">{{ __('production_stage_transfer.owner_'.$owner->kind) }} — {{ $owner->public_id }} <span class="badge bg-secondary">{{ __('production_stage_transfer.status_'.$owner->status) }}</span></div>
        <div class="card-body"><div>{{ __('production_stage_transfer.quantity') }}: {{ $numbers->format($owner->base_quantity) }} — {{ __('production_stage_transfer.cost') }}: {{ $numbers->format($owner->total_cost) }}</div>
            @foreach($owner->posting_snapshot['components'] as $kind => $amount)<span class="d-inline-block me-3">{{ __('production_stage_transfer.component_'.$kind) }}: {{ $numbers->format($amount) }}</span>@endforeach
            @if($owner->kind === 'allocation')<div>{{ __('production_stage_transfer.scrap_treatment') }}: {{ __('production_stage_transfer.scrap_'.$owner->posting_snapshot['scrap_treatment']) }}</div>
            @foreach($owner->posting_snapshot['allocations'] as $kind => $allocation)<div>{{ __('production_stage_transfer.output_'.$kind) }}: {{ $numbers->format($allocation['quantity']) }} — {{ __('production_stage_transfer.cost') }}: {{ $numbers->format($allocation['total_cost']) }}</div>@endforeach
            @endif
            @if($owner->kind === 'allocation')<div>{{ __('production_stage_transfer.booked_amount') }}: {{ $numbers->format($owner->booked_loss_amount) }} · {{ __('production_stage_transfer.rounding_difference') }}: {{ $numbers->formatWithMinimumDecimals($owner->posting_snapshot['rounding_difference'] ?? '0', 8) }}</div>@endif
            <div>{{ $owner->reason }}</div><div>{{ $owner->evidence }}</div>
        </div>
        @can('production.runs.correct_approve')
        @if($owner->status === 'prepared')
        <form class="card-footer" method="POST" action="{{ route('admin.production.runs.stage-output-costs.reject', [$record, $owner]) }}">
            @csrf
            <x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" />
            <x-forms.label :label="__('production_execution.fields.reason')" required />
            <x-forms.textarea name="reason" minlength="5" maxlength="2000" required />
            <button class="btn btn-outline-danger mt-2" type="submit">{{ __('production_stage_transfer.reject_proposal') }}</button>
        </form>
        @endif
        @if((int) $owner->prepared_by !== (int) auth()->id() && $owner->status === 'prepared')
        <form class="card-footer" method="POST" action="{{ route('admin.production.runs.stage-output-costs.approve', [$record, $owner]) }}">@csrf<x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" /><button class="btn btn-success" type="submit">{{ __('production_stage_transfer.approve') }}</button></form>
        @elseif((int) $owner->prepared_by !== (int) auth()->id() && $owner->status === 'posted')
        <form class="card-footer" method="POST" action="{{ route('admin.production.runs.stage-output-costs.reverse', [$record, $owner]) }}">@csrf<x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" /><x-forms.label :label="__('production_execution.fields.reason')" required /><x-forms.textarea name="reason" minlength="5" maxlength="2000" required /><button class="btn btn-warning mt-2" type="submit">{{ __('production_stage_transfer.reverse_output_cost') }}</button></form>
        @endif
        @endcan
    </div>
    @endforeach
    {{ $owners?->links() }}
</div>
@endsection

@extends('layouts.app')
@section('title', __('production_daily_report.correction.title'))
@section('content')
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
<div class="card" data-daily-report-correction><div class="card-header"><h5>{{ __('production_daily_report.correction.title') }} — {{ $record->run_number }}</h5></div><div class="card-body">
<p>{{ __('production_daily_report.correction.help') }}</p>
<p>{{ __('production_daily_report.correction.'.$correction_mode) }}</p>
@foreach($dependency_steps as $step)
<p><a href="{{ $step['correction_url'] }}">{{ $step['doc_num'] }}</a> — {{ __('production_run_correction.actions.'.$step['action']) }} · {{ $step['context_label'] }}</p>
@endforeach
<p>{{ __('production_daily_report.correction.original') }}: {{ $numbers->format(bcdiv((string)$entry->good_base_quantity, (string)$record->conversion_factor, 8)) }} · {{ __('production_daily_report.correction.effective') }}: {{ $numbers->format($effective_quantity) }}</p>
@if($errors->any())<div class="alert alert-danger">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if($blockers)<div class="alert alert-warning">@foreach($blockers as $blocker)<p>{{ $blocker }}</p>@endforeach</div>@endif
@can('production.quality.review')
@foreach($batches as $batch)
<form method="POST" action="{{ route('admin.production.runs.daily-reports.quality-withdraw', [$record, $entry->public_id, $batch->id]) }}" class="border rounded p-3 mb-3">
@csrf<x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" />
<p>{{ __('production_daily_report.correction.quality_withdraw') }} #{{ $batch->batch_number }} — {{ $numbers->format($batch->base_quantity) }}</p>
<label class="form-label w-100">{{ __('production_run_correction.reason') }}<x-forms.textarea name="reason" required minlength="5" maxlength="2000" /></label>
<label class="form-label w-100">{{ __('production_daily_report.correction.evidence') }}<x-forms.textarea name="evidence" required minlength="5" maxlength="2000" /></label>
<button class="btn btn-warning" type="submit">{{ __('production_daily_report.correction.quality_withdraw') }}</button>
</form>
@endforeach
@endcan
@can('production.runs.correct')
@if(!$blockers && !$corrections->contains('status', 'prepared'))
<form method="POST" action="{{ route('admin.production.runs.daily-reports.correction-prepare', [$record, $entry->public_id]) }}">
@csrf<x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" /><x-forms.input type="hidden" name="fingerprint" :value="$fingerprint" />
<label class="form-label w-100">{{ __('production_daily_report.correction.quantity') }}<x-forms.input type="number" name="quantity" min="0" step="0.00000001" :value="old('quantity', $effective_quantity)" required /></label>
@foreach(['rejected', 'rework', 'scrap'] as $kind)
<label class="form-label w-100">{{ __('production_stage_transfer.output_'.$kind) }}<x-forms.input type="number" :name="$kind.'_quantity'" min="0" step="0.00000001" :value="old($kind.'_quantity', $effective_quantities[$kind])" required /></label>
@endforeach
<label class="form-label w-100"><x-forms.input type="checkbox" name="document_error" value="1" required /> {{ __('production_daily_report.correction.document_error') }}</label>
<label class="form-label w-100">{{ __('production_run_correction.posting_date') }}<x-forms.date-input name="posting_date" :value="old('posting_date', now()->toDateString())" required /></label>
<label class="form-label w-100">{{ __('production_run_correction.reason') }}<x-forms.textarea name="reason" required minlength="5" maxlength="2000" /></label>
<label class="form-label w-100">{{ __('production_daily_report.correction.evidence') }}<x-forms.textarea name="evidence" required minlength="5" maxlength="2000" /></label>
<button class="btn btn-primary" type="submit">{{ __('production_run_correction.prepare') }}</button>
</form>
@endif
@endcan
<hr><h6>{{ __('production_run_correction.history') }}</h6>
@foreach($corrections as $correction)
@php($output = json_decode($correction->corrected_output, true, flags: JSON_THROW_ON_ERROR))
<div class="border rounded p-3 mb-3"><p>#{{ $correction->id }} — {{ __('production_run_correction.'.$correction->status) }} · {{ $numbers->format($output['quantity']) }}@if(isset($output['quantities'])) · @foreach(['rejected', 'rework', 'scrap'] as $kind){{ __('production_stage_transfer.output_'.$kind) }}: {{ $numbers->format($output['quantities'][$kind]) }} @endforeach @endif · {{ $correction->reason }} · {{ $output['evidence'] }}</p>
@can('production.runs.correct_approve')
@if($correction->status === 'prepared' && (int)$correction->prepared_by !== (int)auth()->id())
<form method="POST" action="{{ route('admin.production.runs.corrections.approve', [$record, $correction->id]) }}">@csrf<button class="btn btn-danger" type="submit">{{ __('production_run_correction.approve') }}</button></form>
@endif
@if($correction->status === 'prepared')<form method="POST" action="{{ route('admin.production.runs.corrections.reject', [$record, $correction->id]) }}" class="mt-2">@csrf<button class="btn btn-falcon-default" type="submit">{{ __('production_run_correction.reject') }}</button></form>@endif
@endcan</div>
@endforeach
<a class="btn btn-falcon-default" href="{{ route('admin.production.runs.show', $record) }}">{{ __('Back') }}</a>
</div></div>
@endsection

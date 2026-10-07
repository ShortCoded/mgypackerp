@extends('layouts.app')
@section('title', __('production_receipt_cancellation.title'))
@section('content')
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
@php($dates = app(\Modules\Core\Services\DateFormatService::class))
@php($cancelled = $receipt->status === \Modules\Inventory\Models\InventoryDocument::StatusReversed)
<div class="card mb-3" data-production-receipt-cancellation>
    <div class="card-header d-flex justify-content-between"><h5>{{ __('production_receipt_cancellation.title') }} — {{ $receipt->doc_num }}</h5><a class="btn btn-falcon-default btn-sm" href="{{ route('admin.production.runs.show', $record) }}">{{ __('Back') }}</a></div>
    <div class="card-body">
        <p>{{ __('production_receipt_cancellation.help') }}</p>
        <p>{{ $record->run_number }} · {{ $posting_period?->name }}</p>
        @can('inventory.documents.view')<a href="{{ route('admin.inventory.documents.show', $receipt) }}">{{ __('production_run_correction.source') }} — {{ $receipt->doc_num }}</a>@endcan
        @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        @if($errors->any())<div class="alert alert-danger"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @if($cancelled)<div class="alert alert-success"><strong>{{ __('production_receipt_cancellation.cancelled') }}</strong><p class="mb-0">{{ __('production_receipt_cancellation.retained') }}</p></div>
        @elseif($blockers)<div class="alert alert-warning"><ul class="mb-0">@foreach($blockers as $blocker)<li>{{ $blocker }}</li>@endforeach</ul></div>@endif
        @if($correction_steps)
        <div class="alert alert-warning"><h6>{{ __('production_run_correction.dependencies_title') }}</h6><p>{{ __('production_run_correction.dependencies_help') }}</p>
        <ul>@foreach($correction_steps as $step)<li><a href="{{ $step['source_url'] }}">{{ $step['doc_num'] }}</a> — {{ __('production_run_correction.actions.'.$step['action']) }}
        @if($step['permitted'] && $step['correction_url'])<a href="{{ $step['correction_url'] }}">{{ __('production_run_correction.resolve_dependency') }}</a>@endif</li>@endforeach</ul></div>
        @endif
        <div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('Product') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('inventory.movements.fields.batch_lot') }}</th></tr></thead><tbody>
        @foreach($receipt->lines as $line)<tr><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</td><td dir="ltr">{{ $numbers->format($line->quantity) }}</td><td>{{ $line->batch_lot ?: '—' }}</td></tr>@endforeach
        </tbody></table></div>
        @can('production.runs.correct')
        @if(!$blockers && !$corrections->contains('status', 'prepared'))
        <form method="POST" action="{{ route('admin.production.runs.receipt-cancellations.store', [$record, $receipt->doc_num]) }}" data-document-cancellation-form data-error-message="{{ __('cancellation_review.operation_failed') }}">
            @csrf<x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" /><x-forms.input type="hidden" name="fingerprint" :value="$fingerprint" />
            <div class="row g-3">
                <div class="col-md-6"><x-forms.label :label="__('production_run_correction.mode')" /><x-forms.select name="correction_mode" variant="local" :allow-clear="false">
                    <option value="original_period" @selected($default_mode === 'original_period')>{{ __('production_run_correction.original_period') }}</option>
                    @can('production.runs.correct_later_period')<option value="later_period" @selected($default_mode === 'later_period')>{{ __('production_run_correction.later_period') }}</option>@endcan
                </x-forms.select></div>
                <div class="col-md-6"><x-forms.label :label="__('production_run_correction.posting_date')" /><x-forms.date-input name="posting_date" :value="old('posting_date', now()->toDateString())" required /></div>
                <div class="col-12"><label class="form-label w-100">{{ __('production_run_correction.reason') }}<x-forms.textarea name="reason" rows="3" required maxlength="2000">{{ old('reason') }}</x-forms.textarea></label></div>
                <div class="col-12"><div class="alert alert-danger d-none" role="alert" data-cancellation-error></div><button type="submit" class="btn btn-danger">{{ __('production_receipt_cancellation.prepare') }}</button></div>
            </div>
        </form>
        @endif
        @endcan
    </div>
</div>
<div class="card"><div class="card-header">{{ __('production_run_correction.history') }}</div><div class="card-body">
    @foreach($corrections as $correction)
    <div class="border rounded p-3 mb-3"><h6>#{{ $correction->id }} — {{ __('production_run_correction.'.$correction->status) }}</h6><p>{{ $correction->reason }} · {{ $dates->formatDateTime($correction->created_at) }}</p><p>{{ $dates->formatDate($correction->posting_date) }}</p>
        @can('production.runs.correct_approve')
        @if($correction->status === 'prepared' && !$blockers && (int)$correction->prepared_by !== (int)auth()->id())
        <form method="POST" action="{{ route('admin.production.runs.receipt-cancellations.approve', [$record, $receipt->doc_num, $correction->id]) }}" data-document-cancellation-form data-error-message="{{ __('cancellation_review.operation_failed') }}">
            @csrf<x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" /><div class="alert alert-danger d-none" role="alert" data-cancellation-error></div><button type="submit" class="btn btn-danger">{{ __('production_receipt_cancellation.approve') }}</button>
        </form>
        @endif
        @if($correction->status === 'prepared')<form method="POST" action="{{ route('admin.production.runs.corrections.reject', [$record, $correction->id]) }}" class="mt-2">@csrf<button type="submit" class="btn btn-falcon-default">{{ __('production_run_correction.reject') }}</button></form>@endif
        @endcan
    </div>
    @endforeach
</div></div>
@endsection
@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/document-cancellation-review.js') }}"></script>@endpush

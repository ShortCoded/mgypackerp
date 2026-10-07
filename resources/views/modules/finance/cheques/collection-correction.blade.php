@extends('layouts.app')
@section('title', __('cheque_collection_correction.title'))
@section('content')
<div class="card"><div class="card-header"><h5>{{ __('cheque_collection_correction.title') }} — {{ $record->doc_number }}</h5></div><div class="card-body">
<p>{{ __('cheque_collection_correction.help') }}</p>
@if($errors->any())<div class="alert alert-danger">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@foreach($blockers as $blocker)<p class="alert alert-warning">{{ $blocker }}</p>@endforeach
@if(!$blockers && $record->status === \Modules\Finance\Models\Cheque::StatusCollected && !$corrections->contains('status', 'prepared'))
@can('cheques.cancel')@can('customer_receipts.cancel')
<form method="POST" action="{{ route('admin.finance.cheques.collection-corrections.prepare', $record->doc_num) }}">@csrf
<x-forms.input type="hidden" name="fingerprint" :value="$fingerprint" />
<label class="form-label w-100">{{ __('cheque_collection_correction.treatment') }}<x-forms.select name="treatment" required>@foreach(['bank_reversal', 'collection_entry_error'] as $treatment)<option value="{{ $treatment }}">{{ __('cheque_collection_correction.'.$treatment) }}</option>@endforeach</x-forms.select></label>
<label class="form-label w-100">{{ __('cheque_collection_correction.bank_reference') }}<x-forms.input name="bank_reference" required maxlength="255" /></label>
<label class="form-label w-100">{{ __('cheque_collection_correction.reason') }}<x-forms.textarea name="reason" required minlength="5" maxlength="2000" /></label>
<label class="form-label w-100">{{ __('cheque_collection_correction.evidence') }}<x-forms.textarea name="evidence" required minlength="5" maxlength="2000" /></label>
<label class="form-label w-100"><x-forms.input type="checkbox" name="confirmed" value="1" required /> {{ __('cheque_collection_correction.confirmed') }}</label>
<button type="submit" class="btn btn-warning">{{ __('cheque_collection_correction.prepare') }}</button>
</form>
@endcan
@endcan
@endif
@foreach($corrections as $correction)
<div class="border rounded p-3 mt-3"><p>#{{ $correction->id }} — {{ __('cheque_collection_correction.'.$correction->status) }} · {{ __('cheque_collection_correction.'.$correction->treatment) }} · {{ $correction->bank_reference }} · {{ $correction->reason }} · {{ $correction->evidence }}</p>
@if($correction->status === 'approved')<p>{{ __('cheque_collection_correction.journal') }}: #{{ $correction->original_journal_entry_id }} → #{{ $correction->reversal_journal_entry_id }}</p>@endif
@if($correction->status === 'prepared')@can('cheques.cancel')@can('customer_receipts.cancel')
@if((int)$correction->prepared_by !== (int)auth()->id())<form method="POST" action="{{ route('admin.finance.cheques.collection-corrections.approve', [$record->doc_num, $correction->id]) }}">@csrf<button class="btn btn-danger" type="submit">{{ __('cheque_collection_correction.approve') }}</button></form>@endif
<form method="POST" action="{{ route('admin.finance.cheques.collection-corrections.reject', [$record->doc_num, $correction->id]) }}" class="mt-2">@csrf<button class="btn btn-falcon-default" type="submit">{{ __('cheque_collection_correction.reject') }}</button></form>
@endcan
@endcan
@endif
</div>
@endforeach
<a class="btn btn-falcon-default mt-3" href="{{ route('admin.finance.cheques.show', $record->doc_num) }}">{{ __('Back') }}</a>
</div></div>
@endsection

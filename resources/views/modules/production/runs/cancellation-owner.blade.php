@extends('layouts.app')
@section('title', __('production_cancellation_owner.title'))
@section('content')
<x-document-cancellation-review :record="$record" />
@php
    $isRun = $record instanceof \Modules\Production\Models\ProductionRun;
    $prefix = $isRun ? 'admin.production.runs' : 'admin.production.work-orders';
@endphp
<div class="card"><div class="card-header"><h5>{{ __('production_cancellation_owner.title') }} — {{ $isRun ? $record->run_number : $record->doc_num }}</h5></div><div class="card-body">
<p>{{ __('production_cancellation_owner.help') }}</p><p>{{ __('production_cancellation_owner.owner_order') }}</p>
@if($errors->any())<div class="alert alert-danger">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@foreach($blockers as $treatment => $blocker)
<p><strong>{{ __('production_cancellation_owner.'.$treatment) }}</strong> @if($blocker) — {{ $blocker }} @endif</p>
@if(!$blocker && !$owners->whereNull('inventory_document_id')->contains('status', 'prepared'))
@can($isRun ? 'production.runs.cancel' : 'production.orders.cancel')
<form method="POST" action="{{ route($prefix.'.cancellation-owners.prepare', $record) }}" class="border rounded p-3 mb-3">@csrf
<x-forms.input type="hidden" name="fingerprint" :value="$fingerprint" /><x-forms.input type="hidden" name="treatment" :value="$treatment" />
<label class="form-label w-100">{{ __('production_cancellation_owner.reason') }}<x-forms.textarea name="reason" required minlength="5" maxlength="2000" /></label>
<label class="form-label w-100">{{ __('production_cancellation_owner.evidence') }}<x-forms.textarea name="evidence" required minlength="5" maxlength="2000" /></label>
<label class="form-label w-100"><x-forms.input type="checkbox" name="confirmed" value="1" required /> {{ __('production_cancellation_owner.confirmed') }}</label>
<button class="btn btn-warning" type="submit">{{ __('production_cancellation_owner.prepare') }}</button></form>
@endcan
@endif
@endforeach
@foreach($documents as $document)
@can('production.runs.cancel')@can('production.runs.correct')
<form method="POST" action="{{ route($prefix.'.cancellation-owners.prepare', $record) }}" class="border rounded p-3 mb-3">@csrf
<p>{{ __('production_cancellation_owner.material_document_error') }} — {{ $document->doc_num }}</p>
<x-forms.input type="hidden" name="fingerprint" :value="$fingerprint" /><x-forms.input type="hidden" name="inventory_document_id" :value="$document->id" /><x-forms.input type="hidden" name="treatment" value="material_document_error" />
<label class="form-label w-100">{{ __('production_cancellation_owner.reason') }}<x-forms.textarea name="reason" required minlength="5" maxlength="2000" /></label>
<label class="form-label w-100">{{ __('production_cancellation_owner.evidence') }}<x-forms.textarea name="evidence" required minlength="5" maxlength="2000" /></label>
<label class="form-label w-100"><x-forms.input type="checkbox" name="confirmed" value="1" required /> {{ __('production_cancellation_owner.material_confirmed') }}</label>
<button class="btn btn-warning" type="submit">{{ __('production_cancellation_owner.prepare') }}</button></form>
@endcan
@endcan
@endforeach
@foreach($owners as $owner)
<div class="border rounded p-3 mb-3"><p>#{{ $owner->id }} — {{ __('production_cancellation_owner.'.$owner->status) }} · {{ __('production_cancellation_owner.'.$owner->treatment) }} · {{ $owner->reason }} · {{ $owner->evidence }}</p>
@if($owner->status === 'prepared')@can('production.runs.correct_approve')
@if((int)$owner->prepared_by !== (int)auth()->id())<form method="POST" action="{{ route($prefix.'.cancellation-owners.approve', [$record, $owner->id]) }}">@csrf<button class="btn btn-danger" type="submit">{{ __('production_cancellation_owner.approve') }}</button></form>@endif
<form method="POST" action="{{ route($prefix.'.cancellation-owners.reject', [$record, $owner->id]) }}" class="mt-2">@csrf<button class="btn btn-falcon-default" type="submit">{{ __('production_cancellation_owner.reject') }}</button></form>
@endcan
@endif
</div>
@endforeach
<a class="btn btn-falcon-default" href="{{ route($prefix.'.show', $record) }}">{{ __('Back') }}</a>
</div></div>
@endsection

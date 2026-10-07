@extends('layouts.app')
@section('title', __('production_handover.warehouse_title').' — '.$record->doc_num)
@section('content')
<x-document-cancellation-review :record="$record" />
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
@php($dates = app(\Modules\Core\Services\DateFormatService::class))
<div class="card" data-production-warehouse-receipt-document>
    <div class="card-header d-flex justify-content-between"><div><h5>{{ __('production_handover.warehouse_title') }} — {{ $record->doc_num }}</h5><span class="badge bg-secondary">{{ __('production_handover.statuses.'.$record->status) }}</span></div><a class="btn btn-falcon-default btn-sm" href="{{ route('admin.inventory.production-receipts.index') }}">{{ __('common.actions.back') }}</a></div>
    <div class="card-body"><p>{{ __('production_handover.warehouse_help') }}</p><p>{{ $dates->formatDate($record->document_date) }} · {{ $record->branchStore?->name }}</p><a href="{{ route('admin.production.handovers.show', $handover) }}">{{ __('production_handover.handover') }}: {{ $handover->doc_num }}</a>@if($errors->any())<div class="alert alert-danger mt-3">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif</div>
    <div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('Product') }}</th><th>{{ __('Unit') }}</th><th>{{ __('production_handover.actual_quantity') }}</th></tr></thead><tbody>@foreach($record->lines as $line)<tr><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}@if($line->serialIdentity)<div>{{ $line->serialIdentity->serial_number }}</div>@endif</td><td>{{ $line->transactionUnit?->name }}</td><td dir="ltr">{{ $numbers->format($line->transaction_quantity) }}</td></tr>@endforeach</tbody></table></div>
    @if($record->status === 'draft')
    <div class="card-body">@can('inventory.production_receipts.approve')<form method="POST" action="{{ route('admin.inventory.production-receipts.approve', $record) }}">@csrf<x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" /><button class="btn btn-success">{{ __('production_handover.approve_receipt') }}</button></form>@endcan</div>
    @can('inventory.production_receipts.cancel')<div class="card-footer"><form class="row g-2" method="POST" action="{{ route('admin.inventory.production-receipts.cancel', $record) }}">@csrf<x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" /><div class="col-md-8"><x-forms.label :label="__('Cancellation reason')" /><x-forms.input name="reason" required maxlength="1000" /></div><div class="col-md-4 align-self-end"><button class="btn btn-outline-danger">{{ __('production_handover.cancel_receipt') }}</button></div></form></div>@endcan
    @endif
    @if($record->cancelled_at)<div class="card-footer">{{ $dates->formatDateTime($record->cancelled_at) }} — {{ $record->cancel_reason }}</div>@endif
    @if($record->status === 'posted')<div class="card-footer"><p class="mb-0">{{ __('production_handover.posted_receipt_correction_required') }}</p>@if(auth()->user()->canAny(['inventory.production_receipts.correct_prepare', 'inventory.production_receipts.correct_approve']))<a class="btn btn-outline-danger btn-sm mt-2" href="{{ route('admin.inventory.production-receipts.corrections.index', $record) }}">{{ __('production_handover.correct_receipt') }}</a>@endif</div>@endif
</div>
@endsection

@extends('layouts.app')
@section('title', __('production_handover.title').' — '.$record->doc_num)
@section('content')
<x-document-cancellation-review :record="$record" />
@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
@endphp
@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
@endphp
<div class="card mb-3" data-production-handover-document>
    <div class="card-header d-flex justify-content-between"><div><h5>{{ __('production_handover.title') }} — {{ $record->doc_num }}</h5><span class="badge bg-secondary">{{ __('production_handover.statuses.'.$record->status) }}</span></div><a class="btn btn-falcon-default btn-sm" href="{{ auth()->user()->can('production.handovers.view') ? route('admin.production.handovers.index') : route('admin.inventory.production-receipts.index') }}">{{ __('common.actions.back') }}</a></div>
    <div class="card-body"><p>{{ __('production_handover.handover_help') }}</p><p>{{ $dates->formatDate($record->document_date) }} · {{ $record->branchStore?->name }} · {{ $record->source_doc_num }}</p>@if($errors->any())<div class="alert alert-danger">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif</div>
    <div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('Product') }}</th><th>{{ __('Unit') }}</th><th>{{ __('production_handover.handed') }}</th><th>{{ __('production_handover.received') }}</th><th>{{ __('production_handover.remaining_receipt') }}</th></tr></thead><tbody>@foreach($lines as $line)<tr><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}@if($line->serialIdentity)<div>{{ $line->serialIdentity->serial_number }}</div>@endif</td><td>{{ $line->transactionUnit?->name }}</td>@foreach(['quantity', 'received_quantity', 'remaining_quantity'] as $field)<td dir="ltr">{{ $numbers->format(bcdiv((string) $line->{$field}, $line->conversion_factor, 8)) }}</td>@endforeach</tr>@endforeach</tbody></table></div>
    <div class="card-body d-flex flex-wrap gap-2">
    @if($record->status === 'draft')@can('production.handovers.approve')<form method="POST" action="{{ route('admin.production.handovers.approve', $record) }}">@csrf<x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" /><button class="btn btn-primary">{{ __('production_handover.approve') }}</button></form>@endcan @endif
    @if($record->status === 'approved' && $lines->contains(fn ($line) => bccomp((string) $line->remaining_quantity, '0', 8) > 0))@can('inventory.production_receipts.create')<a class="btn btn-success" href="{{ route('admin.inventory.production-receipts.create', $record) }}">{{ __('production_handover.warehouse_create') }}</a>@endcan @endif
    </div>
    @if(in_array($record->status, ['draft', 'approved'], true))@can('production.handovers.cancel')<div class="card-footer"><form class="row g-2" method="POST" action="{{ route('admin.production.handovers.cancel', $record) }}">@csrf<x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" /><div class="col-md-8"><x-forms.label :label="__('Cancellation reason')" /><x-forms.input name="reason" required maxlength="1000" /></div><div class="col-md-4 align-self-end"><button class="btn btn-outline-danger">{{ __('production_handover.cancel_handover') }}</button></div></form><p class="small mt-2 mb-0">{{ __('production_handover.receipts_must_be_corrected') }}</p></div>@endcan @endif
    @if($record->cancelled_at)<div class="card-footer">{{ $dates->formatDateTime($record->cancelled_at) }} — {{ $record->cancel_reason }}</div>@endif
</div>
<div class="card"><div class="card-header"><h5>{{ __('production_handover.receipts') }}</h5></div><div class="card-body">@forelse($receipts as $receipt)<div class="mb-2">@can('inventory.production_receipts.view')<a href="{{ route('admin.inventory.production-receipts.show', $receipt) }}">{{ $receipt->doc_num }}</a>@else {{ $receipt->doc_num }} @endcan — {{ __('production_handover.statuses.'.$receipt->status) }} · {{ $dates->formatDate($receipt->document_date) }}</div>@empty <p class="text-muted mb-0">{{ __('No records found.') }}</p>@endforelse</div></div>
@endsection

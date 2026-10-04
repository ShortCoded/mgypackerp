@extends('layouts.app')
@section('title', __('sales_return_plan.title'))
@section('content')
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
@php($dates = app(\Modules\Core\Services\DateFormatService::class))
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between flex-wrap gap-2"><h5 class="mb-0">{{ __('sales_return_plan.title') }} — {{ $return->doc_num }}</h5><a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.sales-returns.show', $return) }}">{{ __('Back') }}</a></div>
    <div class="card-body">
        <p>{{ __('sales_return_plan.help') }}</p>
        <div class="row g-3 mb-3"><div class="col-md-6">{{ __('sales_return_plan.source_period') }}: {{ $source_period->name }} — {{ $dates->formatDate($source_period->from_date) }} / {{ $dates->formatDate($source_period->to_date) }}</div><div class="col-md-6">{{ __('sales_return_plan.target_period') }}: {{ $target?->name ?? '—' }}</div></div>
        @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        <details class="mb-3"><summary>{{ __('sales_return_plan.journals') }} / {{ __('sales_return_plan.documents') }}</summary><div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('Document') }}</th><th>{{ __('Date') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>@foreach($source_journals as $journal)<tr><td>{{ $journal['doc_num'] }}</td><td>{{ $dates->formatDate($journal['entry_date']) }}</td><td>{{ __(str($journal['status'])->title()->toString()) }}</td></tr>@endforeach @foreach($source_documents as $document)<tr><td>{{ $document['doc_num'] }}</td><td>{{ $dates->formatDate($document['document_date']) }}</td><td>{{ __(str($document['status'])->title()->toString()) }}</td></tr>@endforeach</tbody></table></div></details>
        @if(in_array($return->status, ['received', 'inspected', 'closed'], true))
        @if($dependencies->isNotEmpty())
        <h6>{{ __('sales_return_plan.dependencies') }}</h6>
        @foreach($dependencies as $dependency)
        <div class="border rounded p-3 mb-3"><h6>{{ __('sales_return_plan.'.$dependency['operation']) }} — {{ $dependency['document'] }} — {{ $numbers->format($dependency['amount']) }}</h6>
        @can('sales_returns.correct_prepare')
        <form method="POST" action="{{ route('admin.sales.sales-returns.corrections.store', $return) }}" novalidate>@csrf
            <x-forms.input type="hidden" name="operation" :value="$dependency['operation']" /><x-forms.input type="hidden" name="source_id" :value="$dependency['id']" /><x-forms.input type="hidden" name="source_fingerprint" :value="$fingerprint" />
            <div class="row g-3"><div class="col-md-4"><x-forms.label :for="'recovery_date_'.$dependency['operation'].'_'.$dependency['id']" :label="__('sales_return_plan.posting_date')" required /><x-forms.date-input :id="'recovery_date_'.$dependency['operation'].'_'.$dependency['id']" name="posting_date" :value="old('posting_date', now()->toDateString())" /></div>
            @if($dependency['operation'] === 'refund')<div class="col-md-4"><x-forms.label :for="'reference_'.$dependency['id']" :label="__('sales_return_correction.refund_recovery_reference')" required /><x-forms.input :id="'reference_'.$dependency['id']" name="recovery_reference" :value="old('recovery_reference')" /></div>@endif
            <div class="col-md-4"><x-forms.label :for="'recovery_reason_'.$dependency['operation'].'_'.$dependency['id']" :label="__('sales_return_plan.reason')" required /><x-forms.textarea :id="'recovery_reason_'.$dependency['operation'].'_'.$dependency['id']" name="reason">{{ old('reason') }}</x-forms.textarea></div><div class="col-12"><button class="btn btn-primary">{{ __('sales_return_plan.prepare') }}</button></div></div>
        </form>@endcan</div>
        @endforeach
        @else
        @can('sales_returns.correct_prepare')
        <form method="POST" action="{{ route('admin.sales.sales-returns.corrections.store', $return) }}" novalidate>@csrf
            <x-forms.input type="hidden" name="operation" value="return" /><x-forms.input type="hidden" name="source_fingerprint" :value="$fingerprint" />
            <div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('Item') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('sales_return_plan.replacement_quantity') }}</th></tr></thead><tbody>@foreach($return->lines as $index => $line)<tr><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</td><td>{{ $numbers->format($line->quantity) }}</td><td><x-forms.input type="hidden" :name="'lines['.$index.'][sales_return_line_id]'" :value="$line->id" /><x-forms.label :for="'replacement_quantity_'.$line->id" :label="__('sales_return_plan.replacement_quantity').' '.$line->product?->doc_num" class="visually-hidden" /><x-forms.numeric-input :id="'replacement_quantity_'.$line->id" :name="'lines['.$index.'][quantity]'" :scale="8" :value="old('lines.'.$index.'.quantity', $line->quantity)" /></td></tr>@endforeach</tbody></table></div>
            <div class="row g-3"><div class="col-md-4"><x-forms.label for="correction_date" :label="__('sales_return_plan.posting_date')" required /><x-forms.date-input id="correction_date" name="posting_date" :value="old('posting_date', now()->toDateString())" /></div><div class="col-md-8"><x-forms.label for="correction_reason" :label="__('sales_return_plan.reason')" required /><x-forms.textarea id="correction_reason" name="reason">{{ old('reason') }}</x-forms.textarea></div><div class="col-12"><button class="btn btn-primary">{{ __('sales_return_plan.prepare') }}</button></div></div>
        </form>@endcan
        @endif
        @endif
    </div>
</div>
<div class="card"><div class="card-header">{{ __('sales_return_plan.history') }}</div><div class="card-body">
@foreach($history as $proposal)
<div class="border rounded p-3 mb-3"><h6>#{{ $proposal->id }} — {{ __('sales_return_plan.'.$proposal->operation) }} — {{ __('sales_return_plan.'.$proposal->status) }}</h6><p>{{ $dates->formatDate($proposal->posting_date) }} — {{ $proposal->reason }} @if($proposal->recovery_reference) — {{ $proposal->recovery_reference }} @endif</p><p>{{ __('sales_return_plan.preparer') }}: {{ $proposal->preparer?->name }} — {{ $dates->formatDateTime($proposal->created_at) }}</p>
@if($proposal->replacement_payload)<div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('Item') }}</th><th>{{ __('sales_return_plan.replacement_quantity') }}</th></tr></thead><tbody>@foreach($proposal->replacement_payload as $row)<tr><td>{{ $return->lines->firstWhere('id', $row['sales_return_line_id'])?->product?->name }}</td><td>{{ $numbers->format($row['quantity']) }}</td></tr>@endforeach</tbody></table></div>@endif
@if($proposal->status === 'prepared')
@can('sales_returns.correct_approve')
@if((int) $proposal->prepared_by !== (int) auth()->id())<form class="mb-2" method="POST" action="{{ route('admin.sales.sales-returns.corrections.approve', [$return, $proposal->id]) }}" novalidate>@csrf<x-forms.label :for="'approval_reason_'.$proposal->id" :label="__('sales_return_plan.approval_reason')" required /><x-forms.textarea :id="'approval_reason_'.$proposal->id" name="approval_reason" class="mb-2"></x-forms.textarea><button class="btn btn-success">{{ __('sales_return_plan.approve') }}</button></form>@endif
<form method="POST" action="{{ route('admin.sales.sales-returns.corrections.reject', [$return, $proposal->id]) }}" novalidate>@csrf<button class="btn btn-outline-danger">{{ __('sales_return_plan.reject') }}</button></form>@endcan
@elseif($proposal->status === 'approved')<p>{{ __('sales_return_plan.approver') }}: {{ $proposal->approver?->name }} — {{ $dates->formatDateTime($proposal->approved_at) }} — {{ $proposal->approval_reason }}</p>@if($proposal->replacementReturn)<a href="{{ route('admin.sales.sales-returns.show', $proposal->replacementReturn) }}">{{ __('sales_return_plan.replacement') }} — {{ $proposal->replacementReturn->doc_num }}</a>@endif
@endif
</div>
@endforeach
{{ $history->links() }}
</div></div>
@endsection

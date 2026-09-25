@extends('layouts.app')

@section('title', __('sales_issue.record_receipt'))

@section('content')
<div class="card">
    <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="mb-0">{{ __('sales_issue.record_receipt') }}</h5>
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.delivery-notes.show', $issue) }}">{{ __('Back') }}</a>
    </div>
    <div class="card-body">
        <div class="row g-2 mb-3">
            <div class="col-md-4"><strong>{{ __('sales_issue.warehouse_document') }}</strong><div>{{ $issue->doc_num }}</div></div>
            <div class="col-md-4"><strong>{{ __('sales_issue.invoice') }}</strong><div>{{ $issue->salesIssueOrder?->invoice?->doc_num }}</div></div>
            <div class="col-md-4"><strong>{{ __('sales_issue.customer') }}</strong><div>{{ $issue->salesIssueOrder?->invoice?->customer?->name }}</div></div>
        </div>
        @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <form method="POST" enctype="multipart/form-data" action="{{ route('admin.sales.delivery-receipts.store', $issue) }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-6"><x-forms.label for="delivery-recipient" :label="__('sales_issue.recipient_name')" required /><x-forms.input id="delivery-recipient" name="recipient_name" class="form-control" :value="old('recipient_name')" required /></div>
                <div class="col-md-6"><x-forms.label for="delivery-phone" :label="__('sales_issue.recipient_phone')" /><x-forms.input id="delivery-phone" name="recipient_phone" class="form-control" :value="old('recipient_phone')" /></div>
                <div class="col-12"><x-forms.label for="delivery-signature" :label="__('sales_issue.customer_signature')" required /><input id="delivery-signature" type="file" name="signature" class="form-control" accept=".jpg,.jpeg,.png,.pdf" required /><small class="text-600">{{ __('sales_issue.signature_help') }}</small></div>
                <div class="col-12"><x-forms.label for="delivery-receipt-notes" :label="__('sales_issue.notes')" /><x-forms.textarea id="delivery-receipt-notes" name="notes" class="form-control" rows="2">{{ old('notes') }}</x-forms.textarea></div>
            </div>
            <button class="btn btn-primary btn-sm mt-3" type="submit">{{ __('sales_issue.confirm_receipt') }}</button>
        </form>
    </div>
</div>
@endsection

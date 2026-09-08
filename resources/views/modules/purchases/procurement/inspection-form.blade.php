@extends('layouts.app')
@section('title', __('Incoming Quality Inspection'))
@section('content')
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
<form method="POST" action="{{ route('admin.purchases.goods-receipt-inspection.store', $record->doc_num) }}">
    @csrf
        <x-forms.line-item-cards />
    <div class="alert alert-info">{{ __('Quality disposition is quantity-based and contains no supplier price information.') }}</div>
    <div class="card mb-3"><div class="card-header py-2"><h5 class="mb-0">{{ __('Inspect Receipt :document', ['document' => $record->doc_num]) }}</h5></div><div class="card-body py-3">
        @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
        <div class="row g-3"><div class="col-md-3"><label class="form-label">{{ __('Inspection date/time') }}</label><input class="form-control" type="datetime-local" name="inspection_at" value="{{ now()->format('Y-m-d\TH:i') }}"></div><div class="col-md-9"><label class="form-label">{{ __('Observations') }}</label><input class="form-control" name="observations"></div></div>
    </div></div>
    <div class="card mb-3"><div class="card-body p-0"><div class="table-responsive procurement-lines-scroll"><table class="table table-sm align-middle mb-0 procurement-lines-table"><thead class="bg-100"><tr><th>{{ __('Item') }}</th><th>{{ __('Supplier lot') }}</th><th class="text-end">{{ __('Delivered') }}</th><th>{{ __('Accepted') }}</th><th>{{ __('Rejected') }}</th><th>{{ __('Disposition') }}</th><th>{{ __('Reason / observations') }}</th><th>{{ __('Attachments') }}</th></tr></thead><tbody>
        @foreach($record->lines->filter(fn($line) => $line->product?->requiresIncomingInspection()) as $index => $line)
            <tr><td>{{ $line->product?->name }}<input type="hidden" name="lines[{{ $index }}][receipt_line_public_id]" value="{{ $line->public_id }}"></td><td>{{ $line->supplier_lot_number ?: '—' }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->delivered_quantity) }}</td><td><x-forms.numeric-input name="lines[{{ $index }}][accepted_quantity]" :scale="8" min="0" step="0.00000001" :value="$line->delivered_quantity" required /></td><td><x-forms.numeric-input name="lines[{{ $index }}][rejected_quantity]" :scale="8" min="0" step="0.00000001" value="0" required /></td><td><select class="form-select" name="lines[{{ $index }}][disposition]"><option value="quarantine">{{ __('Quarantine') }}</option><option value="return_supplier">{{ __('Return to supplier') }}</option><option value="reinspect">{{ __('Reinspect') }}</option><option value="conditional_acceptance">{{ __('Conditional acceptance') }}</option></select></td><td><input class="form-control" name="lines[{{ $index }}][reason]"></td><td>@include('modules.purchases.procurement.line-attachments', ['attachmentLine' => null, 'attachmentCompanyId' => $record->company_id, 'index' => $index])</td></tr>
        @endforeach
    </tbody></table></div></div></div>
    @include('modules.purchases.procurement.attachments', [
        'attachmentRecord' => null,
        'attachmentsReadonly' => false,
        'attachmentCollection' => \Modules\Purchases\Models\GoodsReceiptInspection::AttachmentCollection,
    ])
    <div class="d-flex justify-content-end"><button class="btn btn-primary">{{ __('procurement.ui.finalize_quality_inspection') }}</button></div>
</form>
@endsection
@push('scripts')
    <script src="{{ asset('assets/js/modules/Purchases/procurement-cycle.js') }}"></script>
@endpush

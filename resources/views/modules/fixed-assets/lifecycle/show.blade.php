@extends('layouts.app')
@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $imageUrl = app(\Modules\FixedAssets\Services\FixedAssetImageResolver::class)->url($asset);
    $mapping = $displayMapping;
    $lastDepreciation = $asset->postedDepreciations->last();
    $recognized = $asset->hasPostedRecognition();
    $canTransact = $recognized && !$asset->isDisposed() && in_array($asset->status, ['active', 'suspended', 'fully_depreciated'], true);
    $recognitionLabel = __('fixed_assets.product.'.($asset->entry_type === 'opening_asset' ? 'open' : 'capitalize'));
    $purchaseLine = $asset->source_type === \Modules\FixedAssets\Services\FixedAssetPurchaseIntegrationService::SourceType ? $asset->purchaseInvoiceLine : null;
    $purchaseInvoice = $purchaseLine?->purchaseInvoice;
    $supplierPayments = $purchaseInvoice?->paymentAllocations?->pluck('paymentContext')->filter()->unique('id') ?? collect();
    $assetTabs = ['overview', 'financial', 'depreciation', 'movements', 'documents', 'audit'];
    $activeTab = in_array(request('tab'), $assetTabs, true) ? request('tab') : 'overview';
@endphp
@section('title', $asset->doc_num.' / '.$asset->asset_name)
@section('content')
<div class="fixed-asset-360" data-workflow="{{ old('_workflow') }}">
@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="card mb-3 fa-card-compact"><div class="card-body d-flex flex-wrap justify-content-between gap-3 align-items-center">
    <div><h5 class="mb-2">{{ $asset->doc_num }} / {{ $asset->asset_name }}</h5><span class="badge badge-subtle-{{ $asset->isDisposed() ? 'danger' : ($recognized ? 'success' : 'warning') }}">{{ __('fixed_assets.statuses.'.$asset->status) }}</span> <span class="small text-600">{{ $asset->entryTypeLabel() }}</span></div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.fixed-assets.assets.index') }}">{{ __('common.actions.back') }}</a>
        @can('fixed_assets.print')<a class="btn btn-falcon-default btn-sm" target="_blank" href="{{ route('admin.fixed-assets.prints.asset', $asset) }}">{{ __('common.actions.print') }}</a>@endcan
        @if($canEditBasicData)@can('fixed_assets.edit')<a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.fixed-assets.assets.edit', $asset) }}"><span class="fas fa-edit me-1" aria-hidden="true"></span>{{ __('common.actions.edit') }}</a>@endcan @endif
        @if($canEditMaster)@can('fixed_assets.delete')<button class="btn btn-falcon-danger btn-sm js-delete-record" type="button" data-doc-num="{{ $asset->doc_num }}" data-delete-url="{{ route('admin.fixed-assets.assets.destroy', $asset) }}" data-redirect-url="{{ route('admin.fixed-assets.assets.index') }}"><span class="fas fa-trash-alt me-1" aria-hidden="true"></span>{{ __('common.actions.delete') }}</button>@endcan @endif
    </div>
</div></div>
@if($purchaseInvoice)
<div class="card mb-3 border-primary fa-card-compact">
    <div class="card-header bg-light"><h6 class="mb-0">{{ __('fixed_assets.purchase_source.cycle_title') }}</h6></div>
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2">
            @if($purchaseLine->purchaseOrderLine?->purchaseOrder)
                <a class="badge badge-subtle-secondary p-2" href="{{ route('admin.purchases.purchase-orders.show', $purchaseLine->purchaseOrderLine->purchaseOrder->doc_num) }}">{{ $purchaseLine->purchaseOrderLine->purchaseOrder->doc_num }}</a><span class="fas fa-arrow-left text-500"></span>
            @endif
            @if($purchaseLine->receiptLine?->receipt)
                <a class="badge badge-subtle-info p-2" href="{{ route('admin.purchases.goods-receipt-notes.show', $purchaseLine->receiptLine->receipt->doc_num) }}">{{ $purchaseLine->receiptLine->receipt->doc_num }}</a><span class="fas fa-arrow-left text-500"></span>
            @endif
            <a class="badge badge-subtle-primary p-2" href="{{ route('admin.purchases.purchase-invoices.show', $purchaseInvoice->doc_num) }}">{{ $purchaseInvoice->doc_num }}</a>
            @foreach($supplierPayments as $payment)
                <span class="fas fa-arrow-left text-500"></span>
                <a class="badge badge-subtle-success p-2" href="{{ route('admin.purchases.supplier-payments.show', $payment->doc_num) }}">{{ $payment->doc_num }}</a>
            @endforeach
        </div>
        <div class="row g-3 mt-1">
            <div class="col-md-3"><div class="text-600 fs-10">{{ __('Supplier') }}</div><div class="fw-semibold">{{ $purchaseInvoice->supplier?->name }}</div></div>
            <div class="col-md-3"><div class="text-600 fs-10">{{ __('Purchase Invoice') }}</div><a href="{{ route('admin.purchases.purchase-invoices.show', $purchaseInvoice->doc_num) }}">{{ $purchaseInvoice->doc_num }}</a></div>
            <div class="col-md-3"><div class="text-600 fs-10">{{ __('fixed_assets.purchase_source.invoice_status') }}</div><div>{{ __('purchase_invoices.statuses.'.$purchaseInvoice->status) }}</div></div>
            <div class="col-md-3"><div class="text-600 fs-10">{{ __('purchase_invoices.attributes.payment_status') }}</div><div>{{ __('purchase_invoices.payment_statuses.'.$purchaseInvoice->payment_status) }}</div></div>
        </div>
        @if($supplierPayments->isNotEmpty())
            <div class="table-responsive mt-3"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Document number') }}</th><th>{{ __('Payment method') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead><tbody>
            @foreach($supplierPayments as $payment)
                @php
                    $voucher = $payment->cashVoucher ?: $payment->cheque;
                    $voucherRoute = $payment->cashVoucher ? 'admin.finance.cash-payment-vouchers.show' : ($payment->cheque ? 'admin.finance.cheques.show' : null);
                @endphp
                <tr><td><a href="{{ route('admin.purchases.supplier-payments.show', $payment->doc_num) }}">{{ $payment->doc_num }}</a></td><td>{{ __('procurement.statuses.'.$payment->payment_method) }}</td><td dir="ltr">{{ $numbers->format($payment->amount) }}</td><td>{{ __('procurement.statuses.'.$payment->status) }}</td><td>@if($voucher && $voucherRoute)<a href="{{ route($voucherRoute, $voucher->doc_num) }}">{{ $voucher->doc_num }}</a>@endif</td></tr>
            @endforeach
            </tbody></table></div>
        @endif
    </div>
</div>
@endif
@if($purchaseImprovements->isNotEmpty())
<div class="card mb-3 border-warning fa-card-compact">
    <div class="card-header bg-light"><h6 class="mb-0">{{ __('fixed_assets.purchase_source.purchase_improvements') }}</h6></div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>{{ __('fixed_assets.cycle.addition') }}</th><th>{{ __('Purchase Invoice') }}</th><th>{{ __('fixed_assets.purchase_source.effective_date') }}</th><th>{{ __('Amount') }}</th><th>{{ __('fixed_assets.purchase_source.actual_payments') }}</th></tr></thead>
            <tbody>
            @foreach($purchaseImprovements as $improvement)
                @php
                    $improvementLine = $improvement->source_type === \Modules\FixedAssets\Services\FixedAssetPurchaseIntegrationService::ImprovementSourceType ? $improvement->purchaseInvoiceLine : null;
                    $improvementInvoice = $improvementLine?->purchaseInvoice;
                    $improvementPayments = $improvementInvoice?->paymentAllocations?->pluck('paymentContext')->filter()->unique('id') ?? collect();
                @endphp
                <tr>
                    <td>@can('fixed_assets.print')<a href="{{ route('admin.fixed-assets.prints.movement', $improvement) }}" target="_blank">{{ $improvement->doc_num }}</a>@else{{ $improvement->doc_num }}@endcan<div class="small text-600">{{ __('fixed_assets.statuses.'.$improvement->status) }}</div></td>
                    <td>
                        @if($improvementLine?->purchaseOrderLine?->purchaseOrder)<a class="badge badge-subtle-secondary" href="{{ route('admin.purchases.purchase-orders.show', $improvementLine->purchaseOrderLine->purchaseOrder->doc_num) }}">{{ $improvementLine->purchaseOrderLine->purchaseOrder->doc_num }}</a>@endif
                        @if($improvementLine?->receiptLine?->receipt)<a class="badge badge-subtle-info" href="{{ route('admin.purchases.goods-receipt-notes.show', $improvementLine->receiptLine->receipt->doc_num) }}">{{ $improvementLine->receiptLine->receipt->doc_num }}</a>@endif
                        @if($improvementInvoice)<a class="badge badge-subtle-primary" href="{{ route('admin.purchases.purchase-invoices.show', $improvementInvoice->doc_num) }}">{{ $improvementInvoice->doc_num }}</a>@endif
                    </td>
                    <td dir="ltr">{{ $dates->formatDate($improvement->movement_date, '') }}</td>
                    <td dir="ltr">{{ $numbers->format($improvement->amount) }} {{ $asset->currency?->code }}</td>
                    <td>
                        @forelse($improvementPayments as $payment)
                            @php
                                $paymentDocument = $payment->cashVoucher ?: $payment->cheque;
                                $paymentRoute = $payment->cashVoucher ? 'admin.finance.cash-payment-vouchers.show' : ($payment->cheque ? 'admin.finance.cheques.show' : null);
                            @endphp
                            <a class="badge badge-subtle-success" href="{{ route('admin.purchases.supplier-payments.show', $payment->doc_num) }}">{{ $payment->doc_num }}</a>
                            @if($paymentDocument && $paymentRoute)<a class="badge badge-subtle-light" href="{{ route($paymentRoute, $paymentDocument->doc_num) }}">{{ $paymentDocument->doc_num }}</a>@endif
                        @empty
                            <span class="text-600">{{ __('common.empty_value') }}</span>
                        @endforelse
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
<div class="row g-3 mb-3">@foreach(['acquisition_cost' => 'cost', 'accumulated_depreciation' => 'accumulated_depreciation', 'net_book_value' => 'net_book_value'] as $key => $label)
<div class="col-12 col-md-4"><div class="card h-100 fa-card-compact"><div class="card-body"><div class="text-600 fs-10">{{ __('fixed_assets.reports.columns.'.$label) }}</div><div class="fs-7 fw-semibold" dir="ltr">{{ $numbers->format($asset->isDisposed() ? '0' : $position[$key]) }} {{ $asset->currency?->code }}</div></div></div></div>
@endforeach</div>
<div class="card mb-3 fa-card-compact"><div class="card-body"><div class="row g-3">
@foreach(['branch' => $asset->branch?->name, 'location_address' => trim(implode(' / ', array_filter([$asset->branchHall?->name, $asset->location_address]))), 'cost_center' => $asset->costCenter?->codeNameLabel()] as $label => $value)<div class="col-12 col-sm-6 col-xl-3"><div class="small text-600">{{ __('fixed_assets.attributes.'.$label) }}</div>{{ $value ?: __('common.empty_value') }}</div>@endforeach
<div class="col-12 col-sm-6 col-xl-3"><div class="small text-600">{{ __('fixed_assets.cycle.current_custodian') }}</div>{{ $custody?->destinationCustodian?->full_name ?: __('common.empty_value') }}</div>
</div></div></div>
@if($asset->hasLegacyRecognition())<div class="alert alert-info"><strong>{{ __('fixed_assets.prerequisites.legacy_title') }}</strong><div>{{ __('fixed_assets.prerequisites.legacy_help') }}</div></div>@endif
@if($canRecognize)<div class="alert alert-info d-flex flex-wrap justify-content-between gap-2 align-items-center"><span>{{ __('fixed_assets.product.next_recognition') }}</span>@can('fixed_assets.activate')<button class="btn btn-success" type="button" data-bs-toggle="modal" data-bs-target="#recognition-modal">{{ $recognitionLabel }}</button>@endcan</div>@endif
@if($canTransact)<div class="d-flex flex-wrap gap-2 mb-3">
@can('fixed_assets.depreciation.preview')@if($asset->is_depreciable && $asset->status !== 'suspended' && bccomp($position['remaining_depreciable_amount'], '0', 4) > 0)<a class="btn btn-falcon-primary" href="{{ route('admin.fixed-assets.depreciation.index', ['asset_doc_nums' => [$asset->doc_num]]) }}">{{ __('fixed_assets.cycle.depreciation') }}</a>@endif @endcan
@foreach(['addition' => 'fixed_assets.improvement.post', 'transfer' => 'fixed_assets.transfer', 'custody' => 'fixed_assets.custody.post', 'disposal' => 'fixed_assets.dispose'] as $workflow => $permission)@can($permission)<button class="btn btn-falcon-{{ $workflow === 'disposal' ? 'danger' : 'primary' }}" type="button" data-bs-toggle="modal" data-bs-target="#{{ $workflow }}-modal">{{ __('fixed_assets.cycle.'.$workflow) }}</button>@endcan @endforeach
</div>@endif
<ul class="nav nav-tabs flex-wrap mb-3" role="tablist">
@foreach($assetTabs as $tab)<li class="nav-item" role="presentation"><button class="nav-link @if($activeTab === $tab) active @endif" id="{{ $tab }}-tab" data-bs-toggle="tab" data-bs-target="#asset-{{ $tab }}" type="button" role="tab" aria-controls="asset-{{ $tab }}" aria-selected="{{ $activeTab === $tab ? 'true' : 'false' }}">{{ __('fixed_assets.product.tabs.'.$tab) }}</button></li>@endforeach
</ul>
<p class="mb-3"><strong>{{ __('fixed_assets.usability.depreciation_status') }}:</strong> {{ $depreciationReadiness }}</p>
<div class="tab-content">
<div class="tab-pane fade @if($activeTab === 'overview') show active @endif" id="asset-overview" role="tabpanel" aria-labelledby="overview-tab"><div class="card mb-3">        <div class="card-body">
            <div class="row g-3">
                @if($imageUrl)<div class="col-md-2"><img class="img-fluid rounded border" src="{{ $imageUrl }}" alt="{{ $asset->asset_name }}"></div>@endif
                <div class="col"><div class="row g-3">
                    @foreach([
                        __('fixed_assets.attributes.status') => __('fixed_assets.statuses.'.$asset->status),
                        __('fixed_assets.attributes.asset_group_account') => $asset->assetGroupAccount?->codeNameLabel(),
                        __('fixed_assets.attributes.serial_number') => $asset->serial_number,
                        __('fixed_assets.attributes.entry_type') => $asset->entryTypeLabel(),
                        __('fixed_assets.attributes.purchase_date') => $dates->formatDate($asset->purchase_date, ''),
                        __('fixed_assets.attributes.operation_date') => $dates->formatDate($asset->operation_date, ''),
                        __('fixed_assets.attributes.branch') => $asset->branch?->name,
                        __('fixed_assets.attributes.hall') => $asset->branchHall?->name,
                        __('fixed_assets.attributes.location_address') => $asset->location_address,
                        __('fixed_assets.attributes.cost_center') => $asset->costCenter?->codeNameLabel(),
                    ] as $label => $value)
                        <div class="col-md-4"><div class="text-600 fs-10">{{ $label }}</div><div class="fw-semibold">{{ $value ?: __('common.empty_value') }}</div></div>
                    @endforeach
                </div></div>
            </div>
        </div>
    </div>

<div class="card card-body mb-3">{{ $asset->description }}<p class="mb-0">{{ $asset->notes }}</p></div></div>
<div class="tab-pane fade @if($activeTab === 'financial') show active @endif" id="asset-financial" role="tabpanel" aria-labelledby="financial-tab">
@foreach($accountingWarnings as $warning)<div class="alert alert-warning">{{ $warning }}</div>@endforeach
@can('accounts.view')<a class="btn btn-link mb-2" href="{{ route('admin.accounting.accounts.show', $asset->account->doc_num) }}">{{ $asset->account->codeNameLabel() }}</a>@endcan
<div class="card card-body mb-3">{{ __('fixed_assets.product.original_cost') }}: {{ $numbers->format($position['original_cost']) }} — {{ __('fixed_assets.cycle.addition') }}: {{ $numbers->format($position['additions']) }}</div>
    <div class="card mb-3">
        <div class="card-header"><h6 class="mb-0">{{ __('fixed_assets.lifecycle.accounting_and_depreciation') }}</h6></div>
        <div class="card-body"><div class="row g-3">
            @foreach([
                __('fixed_assets.attributes.account') => $asset->account?->codeNameLabel(),
                __('fixed_assets.lifecycle.mapping_fields.accumulated_depreciation_account_doc_num') => $mapping?->accumulatedDepreciationAccount?->codeNameLabel(),
                __('fixed_assets.lifecycle.mapping_fields.depreciation_expense_account_doc_num') => $mapping?->depreciationExpenseAccount?->codeNameLabel(),
                __('fixed_assets.attributes.currency') => trim(implode(' / ', array_filter([$asset->currency?->code, $asset->currency?->name]))),
                __('fixed_assets.attributes.exchange_rate') => $asset->exchange_rate,
                __('fixed_assets.attributes.depreciation_method') => $asset->depreciationMethodLabel(),
                __('fixed_assets.attributes.depreciation_start_date') => $dates->formatDate($asset->depreciation_start_date, ''),
                __('fixed_assets.attributes.useful_life') => $asset->useful_life,
                __('fixed_assets.attributes.annual_depreciation_rate') => $asset->annual_depreciation_rate,
                ...($asset->entry_type === 'opening_asset' ? [__('fixed_assets.attributes.previous_depreciation') => $asset->previous_depreciation, __('fixed_assets.attributes.previous_depreciation_until_date') => $dates->formatDate($asset->previous_depreciation_until_date, '')] : []),
                __('fixed_assets.lifecycle.last_depreciation') => $lastDepreciation?->period_end ? $dates->formatDate($lastDepreciation->period_end, '').' / '.$lastDepreciation->journalEntry?->doc_num : null,
            ] as $label => $value)
                <div class="col-md-4"><div class="text-600 fs-10">{{ $label }}</div><div class="fw-semibold">{{ $value ?: __('common.empty_value') }}</div></div>
            @endforeach
        </div></div>
    </div>

</div><div class="tab-pane fade @if($activeTab === 'depreciation') show active @endif" id="asset-depreciation" role="tabpanel" aria-labelledby="depreciation-tab">    <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('fixed_assets.lifecycle.depreciation_schedule') }}</h6></div><div class="card-body p-0 table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>{{ __('fixed_assets.reports.columns.period') }}</th><th>{{ __('fixed_assets.reports.columns.opening_net_book_value') }}</th><th>{{ __('fixed_assets.reports.columns.period_depreciation') }}</th><th>{{ __('fixed_assets.reports.columns.accumulated_depreciation') }}</th><th>{{ __('fixed_assets.reports.columns.closing_net_book_value') }}</th><th>{{ __('fixed_assets.reports.columns.status') }}</th></tr></thead><tbody>@forelse($schedule['rows'] as $row)<tr><td>{{ $dates->formatDate($row['period_start'], '') }} - {{ $dates->formatDate($row['period_end'], '') }}</td><td dir="ltr">{{ $numbers->format($row['opening_net_book_value']) }}</td><td dir="ltr">{{ $numbers->format($row['period_depreciation']) }}</td><td dir="ltr">{{ $numbers->format($row['accumulated_depreciation']) }}</td><td dir="ltr">{{ $numbers->format($row['closing_net_book_value']) }}</td><td><span class="badge badge-subtle-{{ $row['status'] === 'posted' ? 'success' : 'info' }}">{{ __('fixed_assets.lifecycle.statuses.'.$row['status']) }}</span></td></tr>@empty<tr><td colspan="6" class="text-center text-600 py-4">{{ __('common.empty_value') }}</td></tr>@endforelse</tbody></table></div></div>

    <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('fixed_assets.lifecycle.depreciation_history') }}</h6></div><div class="d-md-none alert alert-info rounded-0 border-0 mb-0 py-2 small"><span class="fas fa-arrows-alt-h me-1"></span>{{ __('fixed_assets.product.scroll_table_hint') }}</div><div class="card-body p-0 table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>{{ __('fixed_assets.reports.columns.period') }}</th><th>{{ __('fixed_assets.reports.columns.period_depreciation') }}</th><th>{{ __('fixed_assets.reports.columns.accumulated_after') }}</th><th>{{ __('fixed_assets.reports.columns.net_book_value') }}</th><th>{{ __('fixed_assets.attributes.cost_center') }}</th><th>{{ __('fixed_assets.reports.columns.journal_entry') }}</th><th>{{ __('fixed_assets.reports.columns.posted_by_date') }}</th></tr></thead><tbody>@forelse($asset->postedDepreciations as $depreciation)<tr><td>{{ $dates->formatDate($depreciation->period_start, '') }} - {{ $dates->formatDate($depreciation->period_end, '') }}</td><td dir="ltr">{{ $numbers->format($depreciation->period_depreciation) }}</td><td dir="ltr">{{ $numbers->format($depreciation->accumulated_after) }}</td><td dir="ltr">{{ $numbers->format($depreciation->closing_net_book_value) }}</td><td>{{ $depreciation->costCenter?->codeNameLabel() }}</td><td>@if($depreciation->journalEntry)@can('journal_entries.view')<a href="{{ route('admin.accounting.journal-entries.show', $depreciation->journalEntry) }}">{{ $depreciation->journalEntry->doc_num }}</a>@else{{ $depreciation->journalEntry->doc_num }}@endcan @endif</td><td>{{ trim(implode(' / ', array_filter([$depreciation->postedBy?->name, $dates->formatDateTime($depreciation->posted_at, '')]))) }}</td></tr>@empty<tr><td colspan="7" class="text-center text-600 py-4">{{ __('common.empty_value') }}</td></tr>@endforelse</tbody></table></div></div>

</div><div class="tab-pane fade @if($activeTab === 'movements') show active @endif" id="asset-movements" role="tabpanel" aria-labelledby="movements-tab">
@include('modules.fixed-assets.lifecycle.ledger')
    <div class="card"><div class="card-header"><h6 class="mb-0">{{ __('fixed_assets.lifecycle.disposal_history') }}</h6></div><div class="d-md-none alert alert-info rounded-0 border-0 mb-0 py-2 small"><span class="fas fa-arrows-alt-h me-1"></span>{{ __('fixed_assets.product.scroll_table_hint') }}</div><div class="card-body p-0 table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>{{ __('fixed_assets.reports.columns.document') }}</th><th>{{ __('fixed_assets.reports.columns.date') }}</th><th>{{ __('fixed_assets.lifecycle.disposition_type') }}</th><th>{{ __('fixed_assets.reports.columns.net_book_value') }}</th><th>{{ __('fixed_assets.lifecycle.proceeds') }}</th><th>{{ __('fixed_assets.lifecycle.gain_loss') }}</th><th>{{ __('fixed_assets.lifecycle.customer_invoice') }}</th><th>{{ __('fixed_assets.lifecycle.accounting_lineage') }}</th><th>{{ __('fixed_assets.reports.columns.status') }}</th><th></th></tr></thead><tbody>@forelse($asset->disposals as $disposal)<tr id="disposal-{{ $disposal->doc_num }}"><td>{{ $disposal->doc_num }}</td><td>{{ $dates->formatDate($disposal->disposal_date, '') }}</td><td>{{ __('fixed_assets.lifecycle.disposition_types.'.$disposal->disposition_type) }}</td><td dir="ltr">{{ $numbers->format($disposal->net_book_value) }}</td><td dir="ltr">{{ $numbers->format($disposal->proceeds) }}</td><td dir="ltr">{{ $numbers->format($disposal->gain_amount) }} / {{ $numbers->format($disposal->loss_amount) }}</td><td>{{ $disposal->customerInvoice?->doc_num ?? '—' }}</td><td><small>{{ __('fixed_assets.lifecycle.derecognition') }}: {{ $disposal->journalEntry?->doc_num ?? '—' }}<br>{{ __('fixed_assets.lifecycle.gain_loss_posting') }}: {{ $disposal->gainLossJournalEntry?->doc_num ?? '—' }}@if($disposal->reversalJournalEntry || $disposal->gainLossReversalJournalEntry)<br>{{ __('fixed_assets.lifecycle.reversal_entries') }}: {{ trim(implode(' / ', array_filter([$disposal->reversalJournalEntry?->doc_num, $disposal->gainLossReversalJournalEntry?->doc_num]))) }}@endif</small></td><td>{{ __('fixed_assets.lifecycle.statuses.'.$disposal->status) }}</td><td>@can('fixed_assets.print')<a target="_blank" href="{{ route('admin.fixed-assets.prints.disposal', $disposal) }}">{{ __('common.actions.print') }}</a>@endcan @if(app(\Modules\FixedAssets\Services\FixedAssetLifecycleService::class)->canReverseDisposal($disposal)) @can('fixed_assets.disposal.reverse')<form method="POST" action="{{ route('admin.fixed-assets.disposal.reverse', $disposal) }}" class="mt-2">@csrf<input class="form-control form-control-sm mb-1" name="reason" placeholder="{{ __('fixed_assets.lifecycle.reversal_reason') }}" required><button class="btn btn-falcon-danger btn-sm" type="submit">{{ __('fixed_assets.lifecycle.reverse') }}</button></form>@endcan @endif</td></tr>@empty<tr><td colspan="10" class="text-center text-600 py-4">{{ __('common.empty_value') }}</td></tr>@endforelse</tbody></table></div></div>
</div><div class="tab-pane fade @if($activeTab === 'documents') show active @endif" id="asset-documents" role="tabpanel" aria-labelledby="documents-tab">
@php
    $movementAttachmentTargets = $attachmentTargets->where('type', '!=', 'asset');
    $movementTargetsWithFiles = $movementAttachmentTargets->filter(fn ($target) => $target['usages']->contains(fn ($usage) => $usage->file));
    $attachmentCount = $attachmentTargets->sum(fn ($target) => $target['usages']->filter(fn ($usage) => $usage->file)->count());
@endphp
<div class="card mb-3 fa-card-compact">
    <div class="card-header py-2"><h6 class="mb-0">{{ __('fixed_assets.cycle.journal_entry') }}</h6></div>
    @if($journals->isEmpty())
        <div class="card-body text-center text-600 py-3">{{ __('fixed_assets.product.journal_empty') }}</div>
    @else
        <div class="list-group list-group-flush">
            @foreach($journals as $journal)
                <div class="list-group-item d-flex flex-column flex-sm-row justify-content-between gap-1 px-3 py-2">
                    <div>@can('journal_entries.view')<a class="fw-semibold" href="{{ route('admin.accounting.journal-entries.show', $journal) }}">{{ $journal->doc_num }}</a>@else {{ $journal->doc_num }} @endcan</div>
                    <div class="small text-600 text-break">{{ $journal->description }}</div>
                </div>
            @endforeach
        </div>
    @endif
</div>
<div class="card mb-3 fa-card-compact" id="asset-attachments">
    <div class="card-header py-2 d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div><h6 class="mb-0">{{ __('fixed_assets.cycle.documents') }}</h6><p class="small text-600 mb-0 mt-1">{{ __('fixed_assets.product.attachments_help') }}</p></div>
        <span class="badge rounded-pill badge-subtle-primary">{{ __('fixed_assets.product.attachments_count', ['count' => $attachmentCount]) }}</span>
    </div>
    <div class="card-body p-0">
        <section class="px-3 py-2 border-bottom" aria-labelledby="asset-document-heading">
            <h6 class="fs-10 text-700 mb-1" id="asset-document-heading">{{ __('fixed_assets.product.asset_documents') }}</h6>
            @include('modules.fixed-assets.lifecycle.attachment-list', ['usages' => $assetDocuments])
        </section>
        <section class="p-0" aria-labelledby="movement-document-heading">
            <div class="px-3 pt-3 pb-2"><h6 class="fs-10 text-700 mb-0" id="movement-document-heading">{{ __('fixed_assets.product.movement_documents') }}</h6></div>
            @forelse($movementTargetsWithFiles as $target)
                <article class="px-3 py-2 border-top" id="movement-documents-{{ $target['document'] }}">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1">
                        <div><span class="badge badge-subtle-info">{{ $target['label'] }}</span> <strong dir="ltr">{{ $target['document'] }}</strong></div>
                        <span class="small text-600">{{ $dates->formatDate($target['date'], '') }}</span>
                    </div>
                    @include('modules.fixed-assets.lifecycle.attachment-list', ['usages' => $target['usages']])
                </article>
            @empty
                <div class="text-center text-600 px-3 pb-3">{{ __('fixed_assets.product.no_documents') }}</div>
            @endforelse
        </section>
        @can('fixed_assets.edit')@can('file_manager.view')
        <form class="fa-attachment-zone m-3 p-3" method="POST" action="{{ route('admin.fixed-assets.movements.document', $asset) }}">@csrf
            <div class="row g-2 align-items-end">
                <div class="col-12 col-lg-5">
                    <label class="form-label" for="asset-document-target">{{ __('fixed_assets.product.attach_to') }}</label>
                    <select class="form-select js-asset-document-target" id="asset-document-target" name="document_target" required>
                        @foreach($attachmentTargets as $target)
                            <option value="{{ $target['type'] }}|{{ $target['document'] }}" @selected(request('attachment_target') === $target['type'].'|'.$target['document'])>{{ $target['document'] }} / {{ $target['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-lg">
                    <label class="form-label d-block">{{ __('fixed_assets.product.selected_document') }}</label>
                    <input type="hidden" id="asset-document-file" name="archive_file_doc_num" required>
                    <button type="button" class="btn btn-falcon-default btn-sm" data-file-picker data-picker-accept="document" data-picker-max="1" data-picker-target-input="#asset-document-file" data-picker-title="{{ __('fixed_assets.product.choose_document') }}" data-picker-collection="fixed_asset_documents" data-picker-allow-upload="{{ auth()->user()?->can('file_manager.upload') ? 'true' : 'false' }}"><span class="fas fa-paperclip me-1" aria-hidden="true"></span>{{ __('fixed_assets.product.choose_document') }}</button>
                    <span class="small text-600 ms-2 js-asset-document-selected">{{ __('fixed_assets.product.no_document_selected') }}</span>
                </div>
                <div class="col-12 col-sm-auto d-grid">
                    <button class="btn btn-success btn-sm js-asset-document-submit" type="submit" disabled><span class="fas fa-link me-1" aria-hidden="true"></span>{{ __('fixed_assets.product.attach_document') }}</button>
                </div>
            </div>
        </form>
        <x-file-picker-modal />
        @endcan @endcan
    </div>
</div>
</div><div class="tab-pane fade @if($activeTab === 'audit') show active @endif" id="asset-audit" role="tabpanel" aria-labelledby="audit-tab">
<div class="card mb-3 fa-card-compact">
    <div class="card-header py-2"><h6 class="mb-0">{{ __('fixed_assets.cycle.audit') }}</h6></div>
    <div class="d-md-none alert alert-info rounded-0 border-0 mb-0 py-2 small"><span class="fas fa-arrows-alt-h me-1"></span>{{ __('fixed_assets.product.scroll_table_hint') }}</div>
    <div class="card-body p-0 table-responsive"><table class="table table-sm align-middle mb-0"><tbody>@forelse($activities as $activity)<tr><td class="text-nowrap">{{ $dates->formatDateTime($activity->created_at, '') }}</td><td class="text-break">{{ __($activity->description) }}</td><td>{{ $activity->causer?->name ?: __('common.empty_value') }}</td></tr>@empty<tr><td class="text-center text-600 py-3">{{ __('common.empty_value') }}</td></tr>@endforelse</tbody></table></div>
</div>
</div></div>
@include('modules.fixed-assets.lifecycle.workflow-forms')
</div>
@endsection
@push('styles')
<style>
    .fixed-asset-360 .fa-card-compact > .card-body:not(.p-0),
    .fixed-asset-360 .tab-pane > .card > .card-body:not(.p-0) { padding: 1rem; }
    .fixed-asset-360 .tab-pane > .card > .card-header { padding-block: .65rem; }
    .fixed-asset-360 .nav-tabs { flex-wrap: nowrap !important; overflow-x: auto; overflow-y: hidden; scrollbar-width: thin; }
    .fixed-asset-360 .nav-tabs .nav-link { white-space: nowrap; }
    .fixed-asset-360 .fa-attachment-zone { border: 1px dashed var(--falcon-border-color, #d8e2ef); border-radius: .5rem; background: var(--falcon-gray-100, #f9fafd); }
    .fixed-asset-360 .fa-attachment-file-name { min-width: 0; overflow-wrap: anywhere; }
    .fixed-asset-360 .fa-attachment-list .list-group-item:last-child { border-bottom: 0; }
    @media (max-width: 575.98px) {
        .fixed-asset-360 .fa-card-compact > .card-body:not(.p-0),
        .fixed-asset-360 .tab-pane > .card > .card-body:not(.p-0) { padding: .875rem; }
        .fixed-asset-360 .fa-attachment-zone { margin: .75rem !important; padding: .875rem !important; }
        .fixed-asset-360 .fa-attachment-actions { width: 100%; }
        .fixed-asset-360 .fa-attachment-actions .btn { flex: 1 1 auto; }
    }
</style>
@endpush
@push('scripts')
<script>window.fixedAssetsMessages = @json(__('fixed_assets.js'));</script>
<script src="{{ asset('vendors/select2/select2.min.js') }}"></script>
<script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/FixedAssets/fixed-assets.js') }}"></script>
<script src="{{ asset('assets/js/modules/Core/file-picker.js') }}"></script>
<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/FixedAssets/fixed-asset-cycle.js') }}"></script>
@endpush

@extends('layouts.app')

@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $imageUrl = app(\Modules\FixedAssets\Services\FixedAssetImageResolver::class)->url($asset);
    $operational = in_array($asset->status, [\Modules\FixedAssets\Models\FixedAsset::StatusActive, \Modules\FixedAssets\Models\FixedAsset::StatusSuspended, \Modules\FixedAssets\Models\FixedAsset::StatusFullyDepreciated], true) && ! $asset->isDisposed();
    $mapping = $asset->categoryMapping;
    $lastDepreciation = $asset->postedDepreciations->last();
@endphp

@section('title', __('fixed_assets.lifecycle.asset_card').' '.$asset->doc_num)

@section('content')
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div><h5 class="mb-1">{{ __('fixed_assets.lifecycle.asset_card') }}</h5><span class="text-700">{{ $asset->doc_num }} / {{ $asset->asset_name }}</span></div>
            <div class="d-flex gap-2">
                @can('fixed_assets.print')<a class="btn btn-falcon-default btn-sm" target="_blank" href="{{ route('admin.fixed-assets.prints.asset', $asset) }}"><span class="fas fa-print me-1"></span>{{ __('common.actions.print') }}</a>@endcan
                @can('fixed_assets.edit')<a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.fixed-assets.assets.edit', $asset) }}">{{ __('common.actions.edit') }}</a>@endcan
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3">
                @if($imageUrl)<div class="col-md-2"><img class="img-fluid rounded border" src="{{ $imageUrl }}" alt="{{ $asset->asset_name }}"></div>@endif
                <div class="col"><div class="row g-3">
                    @foreach([
                        __('fixed_assets.attributes.status') => __('fixed_assets.statuses.'.$asset->status),
                        __('fixed_assets.attributes.asset_group_account') => $asset->assetGroupAccount?->codeNameLabel(),
                        __('fixed_assets.attributes.serial_number') => $asset->serial_number,
                        __('fixed_assets.attributes.entry_type') => $asset->entryTypeLabel(),
                        __('fixed_assets.attributes.source_document') => $asset->source_doc_num,
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
                __('fixed_assets.attributes.previous_depreciation') => $asset->previous_depreciation,
                __('fixed_assets.attributes.previous_depreciation_until_date') => $dates->formatDate($asset->previous_depreciation_until_date, ''),
                __('fixed_assets.lifecycle.last_depreciation') => $lastDepreciation?->period_end ? $dates->formatDate($lastDepreciation->period_end, '').' / '.$lastDepreciation->journalEntry?->doc_num : null,
            ] as $label => $value)
                <div class="col-md-4"><div class="text-600 fs-10">{{ $label }}</div><div class="fw-semibold">{{ $value ?: __('common.empty_value') }}</div></div>
            @endforeach
        </div></div>
    </div>

    <div class="row g-3 mb-3">
        @foreach([
            __('fixed_assets.reports.columns.cost') => $position['acquisition_cost'],
            __('fixed_assets.reports.columns.depreciation_base') => $position['depreciation_base'],
            __('fixed_assets.reports.columns.accumulated_depreciation') => $position['accumulated_depreciation'],
            __('fixed_assets.reports.columns.net_book_value') => $position['net_book_value'],
            __('fixed_assets.reports.columns.residual_value') => $position['residual_value'],
            __('fixed_assets.reports.columns.remaining_depreciable_amount') => $position['remaining_depreciable_amount'],
        ] as $label => $value)
            <div class="col-md-4 col-xl-2"><div class="card h-100"><div class="card-body py-3"><div class="text-600 fs-10">{{ $label }}</div><div class="fs-8 fw-semibold" dir="ltr">{{ $numbers->format($value) }} {{ $asset->currency?->code }}</div></div></div></div>
        @endforeach
    </div>

    @if($asset->status === \Modules\FixedAssets\Models\FixedAsset::StatusDraft)
        @can('fixed_assets.activate')
            <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('fixed_assets.lifecycle.activation') }}</h6></div><div class="card-body">
                <form method="POST" action="{{ route('admin.fixed-assets.lifecycle.activate', $asset) }}" class="row g-3 align-items-end">@csrf
                    <div class="col-md-4"><label class="form-label" for="activation_date">{{ __('fixed_assets.lifecycle.activation_date') }}</label><input class="form-control js-date-picker" id="activation_date" name="activation_date" value="{{ old('activation_date', $today) }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" required></div>
                    <div class="col-auto"><button class="btn btn-success" type="submit">{{ __('fixed_assets.lifecycle.activate') }}</button></div>
                </form>
            </div></div>
        @endcan
    @endif

    @if($operational)
        <div class="row g-3 mb-3">
            @can('fixed_assets.transfer')
                <div class="col-xl-6"><div class="card h-100"><div class="card-header"><h6 class="mb-0">{{ __('fixed_assets.lifecycle.transfer') }}</h6></div><div class="card-body">
                    <form method="POST" action="{{ route('admin.fixed-assets.lifecycle.transfer', $asset) }}" class="row g-3">@csrf
                        <div class="col-md-6"><label class="form-label">{{ __('fixed_assets.lifecycle.movement_date') }}</label><input class="form-control js-date-picker" name="movement_date" value="{{ old('movement_date', $today) }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" required></div>
                        <div class="col-md-6"><label class="form-label">{{ __('fixed_assets.lifecycle.destination_branch') }}</label><select class="form-select js-select2-ajax" id="destination_branch_doc_num" name="destination_branch_doc_num" data-url="{{ route('admin.fixed-assets.select2.branches') }}" required>@if($asset->branch)<option value="{{ $asset->branch->doc_num }}" selected>{{ $asset->branch->doc_num }} / {{ $asset->branch->name }}</option>@endif</select></div>
                        <div class="col-md-6"><label class="form-label">{{ __('fixed_assets.lifecycle.destination_hall') }}</label><select class="form-select js-select2-ajax" name="destination_branch_hall_uuid" data-url="{{ route('admin.fixed-assets.select2.branch-halls') }}" data-depends-on="#destination_branch_doc_num" data-dependent-param="branch_doc_num" data-allow-clear="true">@if($asset->branchHall)<option value="{{ $asset->branchHall->public_uuid }}" selected>{{ $asset->branchHall->name }}</option>@endif</select></div>
                        <div class="col-md-6"><label class="form-label">{{ __('fixed_assets.lifecycle.destination_cost_center') }}</label><select class="form-select js-select2-ajax" name="destination_cost_center_doc_num" data-url="{{ route('admin.fixed-assets.select2.cost-centers') }}" data-allow-clear="true">@if($asset->costCenter)<option value="{{ $asset->costCenter->doc_num }}" selected>{{ $asset->costCenter->codeNameLabel() }}</option>@endif</select></div>
                        <div class="col-12"><label class="form-label">{{ __('fixed_assets.attributes.location_address') }}</label><input class="form-control" name="destination_location_address" value="{{ old('destination_location_address', $asset->location_address) }}"></div>
                        <div class="col-12"><label class="form-label">{{ __('fixed_assets.lifecycle.reason') }}</label><textarea class="form-control" name="reason" required>{{ old('reason') }}</textarea></div>
                        <div class="col-12"><label class="form-label">{{ __('fixed_assets.attributes.notes') }}</label><textarea class="form-control" name="notes">{{ old('notes') }}</textarea></div>
                        <div class="col-12"><button class="btn btn-falcon-primary" type="submit">{{ __('fixed_assets.lifecycle.post_transfer') }}</button></div>
                    </form>
                </div></div></div>
            @endcan
            @can('fixed_assets.dispose')
                <div class="col-xl-6"><div class="card h-100"><div class="card-header"><h6 class="mb-0">{{ __('fixed_assets.lifecycle.disposal') }}</h6></div><div class="card-body">
                    <form method="POST" action="{{ route('admin.fixed-assets.lifecycle.dispose', $asset) }}" class="row g-3">@csrf
                        <div class="col-md-6"><label class="form-label">{{ __('fixed_assets.lifecycle.disposal_date') }}</label><input class="form-control js-date-picker" name="disposal_date" value="{{ old('disposal_date', $today) }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" required></div>
                        <div class="col-md-6"><label class="form-label">{{ __('fixed_assets.lifecycle.disposition_type') }}</label><select class="form-select" name="disposition_type" required>@foreach(\Modules\FixedAssets\Models\FixedAssetDisposal::types() as $type)<option value="{{ $type }}">{{ __('fixed_assets.lifecycle.disposition_types.'.$type) }}</option>@endforeach</select></div>
                        <div class="col-md-6"><label class="form-label">{{ __('fixed_assets.lifecycle.proceeds') }}</label><input class="form-control js-number-input" name="proceeds" value="{{ old('proceeds', '0') }}" inputmode="decimal"></div>
                        <div class="col-md-6"><label class="form-label">{{ __('fixed_assets.lifecycle.proceeds_account') }}</label><select class="form-select js-select2-ajax" name="proceeds_account_doc_num" data-url="{{ route('admin.fixed-assets.select2.credit-accounts') }}" data-allow-clear="true"></select></div>
                        <div class="col-md-6"><label class="form-label">{{ __('fixed_assets.lifecycle.customer') }}</label><select class="form-select js-select2-ajax" name="customer_doc_num" data-url="{{ route('admin.fixed-assets.select2.customers') }}" data-allow-clear="true"></select></div>
                        <div class="col-12"><label class="form-label">{{ __('fixed_assets.lifecycle.reason') }}</label><textarea class="form-control" name="reason" required>{{ old('reason') }}</textarea></div>
                        <div class="col-12"><label class="form-label">{{ __('fixed_assets.attributes.notes') }}</label><textarea class="form-control" name="notes">{{ old('notes') }}</textarea></div>
                        <div class="col-12"><button class="btn btn-danger" type="submit">{{ __('fixed_assets.lifecycle.post_disposal') }}</button></div>
                    </form>
                </div></div></div>
            @endcan
        </div>
    @endif

    <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('fixed_assets.lifecycle.depreciation_schedule') }}</h6></div><div class="card-body p-0 table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>{{ __('fixed_assets.reports.columns.period') }}</th><th>{{ __('fixed_assets.reports.columns.opening_net_book_value') }}</th><th>{{ __('fixed_assets.reports.columns.period_depreciation') }}</th><th>{{ __('fixed_assets.reports.columns.accumulated_depreciation') }}</th><th>{{ __('fixed_assets.reports.columns.closing_net_book_value') }}</th><th>{{ __('fixed_assets.reports.columns.status') }}</th></tr></thead><tbody>@forelse($schedule['rows'] as $row)<tr><td>{{ $dates->formatDate($row['period_start'], '') }} - {{ $dates->formatDate($row['period_end'], '') }}</td><td dir="ltr">{{ $numbers->format($row['opening_net_book_value']) }}</td><td dir="ltr">{{ $numbers->format($row['period_depreciation']) }}</td><td dir="ltr">{{ $numbers->format($row['accumulated_depreciation']) }}</td><td dir="ltr">{{ $numbers->format($row['closing_net_book_value']) }}</td><td><span class="badge badge-subtle-{{ $row['status'] === 'posted' ? 'success' : 'info' }}">{{ __('fixed_assets.lifecycle.statuses.'.$row['status']) }}</span></td></tr>@empty<tr><td colspan="6" class="text-center text-600 py-4">{{ __('common.empty_value') }}</td></tr>@endforelse</tbody></table></div></div>

    <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('fixed_assets.lifecycle.depreciation_history') }}</h6></div><div class="card-body p-0 table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>{{ __('fixed_assets.reports.columns.period') }}</th><th>{{ __('fixed_assets.reports.columns.period_depreciation') }}</th><th>{{ __('fixed_assets.reports.columns.accumulated_after') }}</th><th>{{ __('fixed_assets.reports.columns.net_book_value') }}</th><th>{{ __('fixed_assets.attributes.cost_center') }}</th><th>{{ __('fixed_assets.reports.columns.journal_entry') }}</th><th>{{ __('fixed_assets.reports.columns.posted_by_date') }}</th></tr></thead><tbody>@forelse($asset->postedDepreciations as $depreciation)<tr><td>{{ $dates->formatDate($depreciation->period_start, '') }} - {{ $dates->formatDate($depreciation->period_end, '') }}</td><td dir="ltr">{{ $numbers->format($depreciation->period_depreciation) }}</td><td dir="ltr">{{ $numbers->format($depreciation->accumulated_after) }}</td><td dir="ltr">{{ $numbers->format($depreciation->closing_net_book_value) }}</td><td>{{ $depreciation->costCenter?->codeNameLabel() }}</td><td>{{ $depreciation->journalEntry?->doc_num }}</td><td>{{ trim(implode(' / ', array_filter([$depreciation->postedBy?->name, $dates->formatDateTime($depreciation->posted_at, '')]))) }}</td></tr>@empty<tr><td colspan="7" class="text-center text-600 py-4">{{ __('common.empty_value') }}</td></tr>@endforelse</tbody></table></div></div>

    <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('fixed_assets.lifecycle.movement_history') }}</h6></div><div class="card-body p-0 table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>{{ __('fixed_assets.reports.columns.document') }}</th><th>{{ __('fixed_assets.reports.columns.date') }}</th><th>{{ __('fixed_assets.reports.columns.source') }}</th><th>{{ __('fixed_assets.reports.columns.destination') }}</th><th>{{ __('fixed_assets.lifecycle.reason') }}</th><th></th></tr></thead><tbody>@forelse($asset->movements as $movement)<tr><td>{{ $movement->doc_num }}</td><td>{{ $dates->formatDate($movement->movement_date, '') }}</td><td>{{ trim(implode(' / ', array_filter([$movement->sourceBranch?->name, $movement->sourceBranchHall?->name, $movement->source_location_address, $movement->sourceCostCenter?->codeNameLabel()]))) }}</td><td>{{ trim(implode(' / ', array_filter([$movement->destinationBranch?->name, $movement->destinationBranchHall?->name, $movement->destination_location_address, $movement->destinationCostCenter?->codeNameLabel()]))) }}</td><td>{{ $movement->reason }}</td><td>@can('fixed_assets.print')<a target="_blank" href="{{ route('admin.fixed-assets.prints.movement', $movement) }}">{{ __('common.actions.print') }}</a>@endcan</td></tr>@empty<tr><td colspan="6" class="text-center text-600 py-4">{{ __('common.empty_value') }}</td></tr>@endforelse</tbody></table></div></div>

    <div class="card"><div class="card-header"><h6 class="mb-0">{{ __('fixed_assets.lifecycle.disposal_history') }}</h6></div><div class="card-body p-0 table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>{{ __('fixed_assets.reports.columns.document') }}</th><th>{{ __('fixed_assets.reports.columns.date') }}</th><th>{{ __('fixed_assets.lifecycle.disposition_type') }}</th><th>{{ __('fixed_assets.reports.columns.net_book_value') }}</th><th>{{ __('fixed_assets.lifecycle.proceeds') }}</th><th>{{ __('fixed_assets.lifecycle.gain_loss') }}</th><th>{{ __('fixed_assets.reports.columns.journal_entry') }}</th><th>{{ __('fixed_assets.reports.columns.status') }}</th><th></th></tr></thead><tbody>@forelse($asset->disposals as $disposal)<tr><td>{{ $disposal->doc_num }}</td><td>{{ $dates->formatDate($disposal->disposal_date, '') }}</td><td>{{ __('fixed_assets.lifecycle.disposition_types.'.$disposal->disposition_type) }}</td><td dir="ltr">{{ $numbers->format($disposal->net_book_value) }}</td><td dir="ltr">{{ $numbers->format($disposal->proceeds) }}</td><td dir="ltr">{{ $numbers->format($disposal->gain_amount) }} / {{ $numbers->format($disposal->loss_amount) }}</td><td>{{ $disposal->journalEntry?->doc_num }}</td><td>{{ __('fixed_assets.lifecycle.statuses.'.$disposal->status) }}</td><td>@can('fixed_assets.print')<a target="_blank" href="{{ route('admin.fixed-assets.prints.disposal', $disposal) }}">{{ __('common.actions.print') }}</a>@endcan @if($disposal->status === \Modules\FixedAssets\Models\FixedAssetDisposal::StatusPosted) @can('fixed_assets.disposal.reverse')<form method="POST" action="{{ route('admin.fixed-assets.disposal.reverse', $disposal) }}" class="mt-2">@csrf<input class="form-control form-control-sm mb-1" name="reason" placeholder="{{ __('fixed_assets.lifecycle.reversal_reason') }}" required><button class="btn btn-falcon-danger btn-sm" type="submit">{{ __('fixed_assets.lifecycle.reverse') }}</button></form>@endcan @endif</td></tr>@empty<tr><td colspan="9" class="text-center text-600 py-4">{{ __('common.empty_value') }}</td></tr>@endforelse</tbody></table></div></div>
@endsection

@push('scripts')
    <script src="{{ asset('vendors/select2/select2.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/FixedAssets/fixed-assets.js') }}"></script>
@endpush

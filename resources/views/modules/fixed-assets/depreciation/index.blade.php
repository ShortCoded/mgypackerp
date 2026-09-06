@extends('layouts.app')

@php($dates = app(\Modules\Core\Services\DateFormatService::class))
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))

@section('title', __('fixed_assets.lifecycle.depreciation_run'))

@section('content')
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <div class="card mb-3">
        <div class="card-header"><h5 class="mb-0">{{ __('fixed_assets.lifecycle.depreciation_run') }}</h5><p class="text-600 mb-0 mt-1">{{ __('fixed_assets.lifecycle.depreciation_policy', ['basis' => config('fixed_assets.day_basis')]) }}</p></div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.fixed-assets.depreciation.preview') }}" class="row g-3 align-items-end">@csrf
                <div class="col-md-4 col-xl-3"><label class="form-label" for="depreciation-financial_period_doc_num">{{ __('fixed_assets.lifecycle.financial_period') }}</label><select id="depreciation-financial_period_doc_num" class="form-select js-select2-ajax" name="financial_period_doc_num" data-url="{{ route('admin.select2.financial-periods') }}" required>@if($financialPeriod)<option value="{{ $financialPeriod->doc_num }}" selected>{{ $financialPeriod->doc_num }} / {{ $financialPeriod->name }}</option>@endif</select></div>
                <div class="col-md-4 col-xl-3"><label class="form-label" for="depreciation-posting_date">{{ __('fixed_assets.lifecycle.posting_date') }}</label><input id="depreciation-posting_date" class="form-control js-date-picker" name="posting_date" value="{{ $dates->formatDate($dates->normalizeForStorage(old('posting_date', $postingDate)), '') }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" required></div>
                <div class="col-md-4 col-xl-3"><label class="form-label" for="depreciation-branch_doc_num">{{ __('fixed_assets.attributes.branch') }}</label><select id="depreciation-branch_doc_num" class="form-select js-select2-ajax" name="branch_doc_num" data-url="{{ route('admin.fixed-assets.select2.branches') }}" data-allow-clear="true"></select></div>
                <div class="col-md-4 col-xl-3"><label class="form-label" for="depreciation-asset_group_account_doc_num">{{ __('fixed_assets.attributes.asset_group_account') }}</label><select id="depreciation-asset_group_account_doc_num" class="form-select js-select2-ajax" name="asset_group_account_doc_num" data-url="{{ route('admin.fixed-assets.select2.asset-categories') }}" data-allow-clear="true"></select></div>
                <div class="col-md-4 col-xl-3"><label class="form-label" for="depreciation-cost_center_doc_num">{{ __('fixed_assets.attributes.cost_center') }}</label><select id="depreciation-cost_center_doc_num" class="form-select js-select2-ajax" name="cost_center_doc_num" data-url="{{ route('admin.fixed-assets.select2.cost-centers') }}" data-allow-clear="true"></select></div>
                <div class="col-md-8 col-xl-6"><label class="form-label">{{ __('fixed_assets.reports.columns.asset') }}</label><select class="form-select js-select2-ajax" name="asset_doc_nums[]" multiple data-url="{{ route('admin.fixed-assets.select2.assets') }}" data-allow-clear="true">@foreach($selectedAssets as $selectedAsset)<option value="{{ $selectedAsset->doc_num }}" selected>{{ $selectedAsset->doc_num }} / {{ $selectedAsset->asset_name }}</option>@endforeach</select></div>
                <div class="col-auto"><button class="btn btn-falcon-primary" type="submit"><span class="fas fa-search me-1"></span>{{ __('fixed_assets.lifecycle.preview') }}</button></div>
            </form>
        </div>
    </div>

    @if($preview)
        <form method="POST" action="{{ route('admin.fixed-assets.depreciation.post') }}" class="js-depreciation-post-form" data-preview-url="{{ route('admin.fixed-assets.depreciation.preview') }}" data-confirm-title="{{ __('fixed_assets.lifecycle.confirm_depreciation_title') }}" data-confirm-text="{{ __('fixed_assets.lifecycle.confirm_depreciation_text') }}" data-confirm-yes="{{ __('fixed_assets.lifecycle.confirm_depreciation_yes') }}" data-confirm-cancel="{{ __('fixed_assets.js.cancel') }}">@csrf
            <input type="hidden" name="financial_period_doc_num" value="{{ $preview['financialPeriod']->doc_num }}"><input type="hidden" name="posting_date" value="{{ $preview['postingDate']->toDateString() }}">
            @foreach(['branch_doc_num', 'asset_group_account_doc_num', 'cost_center_doc_num'] as $filter)<input type="hidden" name="{{ $filter }}" value="{{ request($filter) }}">@endforeach
            <div class="card mb-3"><div class="card-header d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2"><h6 class="mb-0">{{ __('fixed_assets.lifecycle.eligible_assets') }} ({{ count($preview['eligible']) }})</h6>@can('fixed_assets.depreciation.post')<button class="btn btn-success btn-sm" type="submit" @disabled(count($preview['eligible']) === 0)>{{ __('fixed_assets.lifecycle.post_depreciation') }}</button>@endcan</div><div class="d-md-none alert alert-info rounded-0 border-0 mb-0 py-2 small"><span class="fas fa-arrows-alt-h me-1"></span>{{ __('fixed_assets.product.scroll_table_hint') }}</div><div class="card-body p-0 table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th></th><th>{{ __('fixed_assets.reports.columns.asset') }}</th><th>{{ __('fixed_assets.reports.columns.cost') }}</th><th>{{ __('fixed_assets.reports.columns.accumulated_before') }}</th><th>{{ __('fixed_assets.reports.columns.period_depreciation') }}</th><th>{{ __('fixed_assets.reports.columns.accumulated_after') }}</th><th>{{ __('fixed_assets.reports.columns.net_book_value') }}</th><th>{{ __('fixed_assets.attributes.cost_center') }}</th></tr></thead><tbody>
                @forelse($preview['eligible'] as $row)<tr data-asset="{{ $row['asset']->doc_num }}"><td><input class="form-check-input" type="checkbox" name="asset_doc_nums[]" value="{{ $row['asset']->doc_num }}" checked></td><td>{{ $row['asset']->doc_num }} / {{ $row['asset']->asset_name }} @if($row['asset']->depreciation_method === \Modules\FixedAssets\Models\FixedAsset::DepreciationMethodUnitsOfProduction)<input class="form-control form-control-sm mt-1" name="usage_units[{{ $row['asset']->doc_num }}]" value="{{ $row['usage_units'] }}" placeholder="{{ __('fixed_assets.cycle.actual_usage') }}" required>@endif</td><td dir="ltr" data-value="cost">{{ $numbers->format($row['acquisition_cost']) }}</td><td dir="ltr" data-value="accumulated_before">{{ $numbers->format($row['accumulated_before']) }}</td><td dir="ltr" data-value="period_depreciation">{{ $numbers->format($row['period_depreciation']) }}</td><td dir="ltr" data-value="accumulated_after">{{ $numbers->format($row['accumulated_after']) }}</td><td dir="ltr" data-value="closing_net_book_value">{{ $numbers->format($row['closing_net_book_value']) }}</td><td>{{ $row['effective_cost_center']?->codeNameLabel() }}</td></tr>@empty<tr><td colspan="8" class="text-center py-4">{{ __('common.empty_value') }}</td></tr>@endforelse
            </tbody><tfoot><tr class="fw-bold"><th colspan="2">{{ __('common.total') }}</th>@foreach(['cost' => 'acquisition_cost', 'accumulated_before' => 'accumulated_before', 'period_depreciation' => 'period_depreciation', 'accumulated_after' => 'accumulated_after', 'closing_net_book_value' => 'closing_net_book_value'] as $key => $source)<td dir="ltr" data-total="{{ $key }}">{{ $numbers->format(collect($preview['eligible'])->reduce(fn ($sum, $row) => bcadd($sum, $row[$source], 4), '0.0000')) }}</td>@endforeach<td></td></tr></tfoot></table></div></div>
            <div class="js-depreciation-error alert alert-danger d-none" aria-live="polite"></div>
        </form>
        @foreach([true, false] as $actionable)
        @php($rows = collect($preview['excluded'])->filter(fn ($row) => ($row['actionable'] ?? false) === $actionable))
        @if($rows->isNotEmpty())
        <details class="card mb-3" @if($actionable) open @endif>
            <summary class="card-header">{{ __($actionable ? 'fixed_assets.usability.needs_action' : 'fixed_assets.lifecycle.excluded_assets') }} ({{ $rows->count() }})</summary>
            <div class="d-md-none alert alert-light rounded-0 border-0 mb-0 py-2 small"><span class="fas fa-arrows-alt-h me-1"></span>{{ __('fixed_assets.product.scroll_table_hint') }}</div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('fixed_assets.reports.columns.asset') }}</th><th>{{ __('fixed_assets.reports.columns.reason') }}</th><th></th></tr></thead><tbody>
            @foreach($rows as $row)<tr><td><a href="{{ route('admin.fixed-assets.assets.show', $row['asset']) }}">{{ $row['asset']->doc_num }} / {{ $row['asset']->asset_name }}</a></td><td class="{{ $actionable ? 'text-warning' : 'text-600' }}">{{ $row['reason'] }}</td><td>
                @if($actionable)
                    @if($row['next_date'] ?? null)
                    <form method="POST" action="{{ route('admin.fixed-assets.depreciation.preview') }}">@csrf<input type="hidden" name="financial_period_doc_num" value="{{ $preview['financialPeriod']->doc_num }}"><input type="hidden" name="posting_date" value="{{ $row['next_date'] }}"><input type="hidden" name="asset_doc_nums[]" value="{{ $row['asset']->doc_num }}"><button class="btn btn-falcon-primary btn-sm" type="submit">{{ __('fixed_assets.usability.open_period') }}</button></form>
                    @else<a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.fixed-assets.assets.show', $row['asset']) }}">{{ __('fixed_assets.usability.open_asset') }}</a>@endif
                @endif
            </td></tr>@endforeach
            </tbody></table></div>
        </details>
        @endif
        @endforeach
    @endif

    <div class="card mt-3">
        <div class="card-header"><h6 class="mb-1">{{ __('fixed_assets.lifecycle.recent_runs') }}</h6><p class="text-600 mb-0">{{ __('fixed_assets.lifecycle.recent_runs_help') }}</p></div>
        @if($recentRuns->isEmpty())
            <div class="card-body text-center text-600 py-4">{{ __('common.empty_value') }}</div>
        @else
            <div class="table-responsive d-none d-md-block">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead><tr><th>{{ __('fixed_assets.reports.columns.document') }}</th><th>{{ __('fixed_assets.reports.columns.period') }}</th><th>{{ __('fixed_assets.lifecycle.posting_date') }}</th><th>{{ __('fixed_assets.lifecycle.run_lines') }}</th><th>{{ __('fixed_assets.reports.columns.period_depreciation') }}</th><th>{{ __('fixed_assets.reports.columns.journal_entry') }}</th><th>{{ __('fixed_assets.reports.columns.status') }}</th><th></th></tr></thead>
                    <tbody>@foreach($recentRuns as $run)<tr><td><a class="fw-semibold" href="{{ route('admin.fixed-assets.depreciation.show', $run) }}">{{ $run->doc_num }}</a></td><td>{{ $dates->formatDate($run->period_start, '') }} - {{ $dates->formatDate($run->period_end, '') }}</td><td>{{ $dates->formatDate($run->posting_date, '') }}</td><td dir="ltr">{{ $run->lines_count }}</td><td dir="ltr">{{ $numbers->format($run->base_total_depreciation) }}</td><td>@if($run->journalEntry)@can('journal_entries.view')<a href="{{ route('admin.accounting.journal-entries.show', $run->journalEntry) }}">{{ $run->journalEntry->doc_num }}</a>@else{{ $run->journalEntry->doc_num }}@endcan @else{{ __('common.empty_value') }}@endif</td><td><span class="badge badge-subtle-{{ $run->status === 'posted' ? 'success' : 'secondary' }}">{{ __('fixed_assets.lifecycle.statuses.'.$run->status) }}</span></td><td><a class="btn btn-falcon-default btn-sm" href="{{ route('admin.fixed-assets.depreciation.show', $run) }}">{{ __('fixed_assets.lifecycle.open_run') }}</a></td></tr>@endforeach</tbody>
                </table>
            </div>
            <div class="d-md-none">@foreach($recentRuns as $run)<article class="p-3 border-bottom"><div class="d-flex justify-content-between align-items-start gap-2 mb-2"><a class="fw-bold" href="{{ route('admin.fixed-assets.depreciation.show', $run) }}">{{ $run->doc_num }}</a><span class="badge badge-subtle-{{ $run->status === 'posted' ? 'success' : 'secondary' }}">{{ __('fixed_assets.lifecycle.statuses.'.$run->status) }}</span></div><div class="small text-600 mb-1">{{ $dates->formatDate($run->period_start, '') }} - {{ $dates->formatDate($run->period_end, '') }}</div><div class="d-flex justify-content-between"><span>{{ __('fixed_assets.lifecycle.run_lines') }}</span><strong dir="ltr">{{ $run->lines_count }}</strong></div><div class="d-flex justify-content-between"><span>{{ __('fixed_assets.reports.columns.period_depreciation') }}</span><strong dir="ltr">{{ $numbers->format($run->base_total_depreciation) }}</strong></div><a class="btn btn-falcon-default btn-sm w-100 mt-3" href="{{ route('admin.fixed-assets.depreciation.show', $run) }}">{{ __('fixed_assets.lifecycle.open_run') }}</a></article>@endforeach</div>
        @endif
    </div>
@endsection

@push('scripts')
    <script>window.fixedAssetsMessages = @json(__('fixed_assets.js'));</script>
    <script src="{{ asset('vendors/select2/select2.min.js') }}"></script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/FixedAssets/fixed-assets.js') }}"></script>
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/FixedAssets/fixed-asset-cycle.js') }}"></script>
@endpush

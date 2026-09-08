@extends('layouts.app')

@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $movementTone = static fn (string $type): string => match (true) {
        str_contains($type, 'reversal') => 'danger',
        $type === 'disposal' => 'danger',
        $type === 'depreciation' => 'primary',
        $type === 'addition' => 'success',
        in_array($type, ['transfer', 'custody'], true) => 'info',
        default => 'secondary',
    };
@endphp

@section('title', __('fixed_assets.product.movements'))

@section('content')
    <div class="card mb-3">
        <div class="card-header pb-0">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
                <div>
                    <h5 class="mb-1">{{ __('fixed_assets.product.movements') }}</h5>
                    <p class="text-600 mb-0">{{ __('fixed_assets.product.movements_help') }}</p>
                </div>
                <span class="badge rounded-pill badge-subtle-primary fs-10">
                    {{ trans_choice('fixed_assets.product.movement_count', $rows->total(), ['count' => $rows->total()]) }}
                </span>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end" autocomplete="off">
                <div class="col-12 col-md-6 col-xl-4">
                    <label class="form-label" for="movement-asset">{{ __('fixed_assets.reports.columns.asset') }}</label>
                    <select id="movement-asset" class="form-select js-select2-ajax" name="asset_doc_num" data-url="{{ route('admin.fixed-assets.select2.assets') }}" data-placeholder="{{ __('fixed_assets.placeholders.asset') }}" data-allow-clear="true">
                        @if($filters['asset_doc_num'] ?? null)<option selected value="{{ $filters['asset_doc_num'] }}">{{ $filters['asset_doc_num'] }}</option>@endif
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-xl-2">
                    <label class="form-label" for="movement-type">{{ __('fixed_assets.cycle.type') }}</label>
                    <select id="movement-type" class="form-select" name="movement_type">
                        <option value="">{{ __('fixed_assets.product.all_movements') }}</option>
                        @foreach($movementTypes as $type)<option value="{{ $type }}" @selected(($filters['movement_type'] ?? '') === $type)>{{ __('fixed_assets.cycle.'.$type) }}</option>@endforeach
                    </select>
                </div>
                @foreach(['from_date', 'to_date'] as $date)
                    <div class="col-12 col-sm-6 col-xl-2">
                        <label class="form-label" for="movement-{{ $date }}">{{ __('fixed_assets.reports.'.$date) }}</label>
                        <input id="movement-{{ $date }}" class="form-control js-date-picker" name="{{ $date }}" value="{{ isset($filters[$date]) ? $dates->formatDate($filters[$date], '') : '' }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}">
                    </div>
                @endforeach
                <div class="col-12 col-sm-6 col-xl-2">
                    <label class="form-label" for="movement-branch">{{ __('fixed_assets.attributes.branch') }}</label>
                    <select id="movement-branch" class="form-select js-select2-ajax" name="branch_doc_num" data-url="{{ route('admin.fixed-assets.select2.branches') }}" data-placeholder="{{ __('fixed_assets.placeholders.branch') }}" data-allow-clear="true">
                        @if($filters['branch_doc_num'] ?? null)<option selected value="{{ $filters['branch_doc_num'] }}">{{ $filters['branch_doc_num'] }}</option>@endif
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-xl-3">
                    <label class="form-label" for="movement-user">{{ __('fixed_assets.cycle.user') }}</label>
                    <input id="movement-user" class="form-control" name="user" value="{{ $filters['user'] ?? '' }}" placeholder="{{ __('fixed_assets.product.user_filter_help') }}">
                </div>
                <div class="col-12 col-sm-auto d-grid d-sm-block">
                    <button class="btn btn-falcon-primary" type="submit"><span class="fas fa-filter me-1" aria-hidden="true"></span>{{ __('common.actions.apply') }}</button>
                </div>
                <div class="col-12 col-sm-auto d-grid d-sm-block">
                    <a class="btn btn-falcon-default" href="{{ route('admin.fixed-assets.movements.index') }}"><span class="fas fa-undo me-1" aria-hidden="true"></span>{{ __('common.actions.reset') }}</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
            <h6 class="mb-0">{{ __('fixed_assets.product.movement_history') }}</h6>
            @if($rows->total() > 0)
                <span class="small text-600">{{ __('fixed_assets.product.pagination_summary', ['from' => $rows->firstItem(), 'to' => $rows->lastItem(), 'total' => $rows->total()]) }}</span>
            @endif
        </div>

        @if($rows->isEmpty())
            <div class="card-body text-center py-5">
                <span class="fas fa-exchange-alt fs-5 text-300 mb-3" aria-hidden="true"></span>
                <h6>{{ __('fixed_assets.product.no_movements') }}</h6>
                <p class="text-600 mb-0">{{ __('fixed_assets.product.no_movements_help') }}</p>
            </div>
        @else
            <div class="table-responsive d-none d-lg-block">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-100 text-900">
                        <tr>
                            <th>{{ __('fixed_assets.reports.columns.date') }} / {{ __('fixed_assets.reports.columns.document') }}</th>
                            <th>{{ __('fixed_assets.reports.columns.asset') }}</th>
                            <th style="min-width: 18rem">{{ __('fixed_assets.reports.columns.movement_type') }}</th>
                            <th class="text-end">{{ __('fixed_assets.reports.columns.amount') }}</th>
                            <th>{{ __('fixed_assets.reports.columns.status') }} / {{ __('fixed_assets.reports.columns.user') }}</th>
                            <th>{{ __('fixed_assets.reports.columns.journal_entry') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr class="{{ $row['_reversed'] ? 'bg-danger-subtle' : '' }}">
                                <td class="text-nowrap">
                                    <div>{{ $dates->formatDate($row['date'], '') }}</div>
                                    <a class="fw-semibold" href="{{ $row['_url'] }}">{{ $row['document'] }}</a>
                                </td>
                                <td style="min-width: 13rem"><a href="{{ $row['_asset_url'] }}">{{ $row['asset'] }}</a></td>
                                <td>
                                    <span class="badge rounded-pill badge-subtle-{{ $movementTone($row['_type']) }}">{{ $row['movement_type'] }}</span>
                                    @if($row['reason'])<div class="small text-600 mt-1 text-break" data-erp-long-text>{{ $row['reason'] }}</div>@endif
                                </td>
                                <td class="text-end text-nowrap" dir="ltr">{{ $row['amount'] === null || $row['amount'] === '' ? '—' : $numbers->format($row['amount']).' '.$row['currency'] }}</td>
                                <td>
                                    <span class="badge rounded-pill badge-subtle-{{ $row['_reversed'] ? 'danger' : 'success' }}">{{ $row['status'] }}</span>
                                    @if($row['user'])<div class="small text-600 mt-1">{{ $row['user'] }}</div>@endif
                                </td>
                                <td>@if($row['_journal_url'])@can('journal_entries.view')<a class="text-nowrap" href="{{ $row['_journal_url'] }}">{{ $row['journal_entry'] }}</a>@else {{ $row['journal_entry'] }} @endcan @else — @endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="d-lg-none">
                @foreach($rows as $row)
                    <article class="p-3 border-bottom {{ $row['_reversed'] ? 'bg-danger-subtle' : '' }}">
                        <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                            <div>
                                <a class="fw-semibold" href="{{ $row['_url'] }}">{{ $row['document'] }}</a>
                                <div class="small text-600">{{ $dates->formatDate($row['date'], '') }}</div>
                            </div>
                            <span class="badge rounded-pill badge-subtle-{{ $row['_reversed'] ? 'danger' : 'success' }}">{{ $row['status'] }}</span>
                        </div>
                        <a class="d-block mb-2" href="{{ $row['_asset_url'] }}">{{ $row['asset'] }}</a>
                        <span class="badge rounded-pill badge-subtle-{{ $movementTone($row['_type']) }}">{{ $row['movement_type'] }}</span>
                        @if($row['reason'])<p class="small text-600 text-break my-2" data-erp-long-text>{{ $row['reason'] }}</p>@endif
                        <div class="row g-2 small mt-1">
                            <div class="col-6"><span class="text-600">{{ __('fixed_assets.reports.columns.amount') }}:</span><br><span dir="ltr">{{ $row['amount'] === null || $row['amount'] === '' ? '—' : $numbers->format($row['amount']).' '.$row['currency'] }}</span></div>
                            <div class="col-6"><span class="text-600">{{ __('fixed_assets.reports.columns.user') }}:</span><br>{{ $row['user'] ?: '—' }}</div>
                            @if($row['_journal_url'])<div class="col-12"><span class="text-600">{{ __('fixed_assets.reports.columns.journal_entry') }}:</span> @can('journal_entries.view')<a href="{{ $row['_journal_url'] }}">{{ $row['journal_entry'] }}</a>@else {{ $row['journal_entry'] }} @endcan</div>@endif
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

        @if($rows->hasPages())
            <div class="card-footer pb-0">
                {{ $rows->onEachSide(1)->links('modules.fixed-assets.partials.pagination') }}
            </div>
        @endif
    </div>
@endsection

@push('scripts')
    <script>window.fixedAssetsMessages = @json(__('fixed_assets.js'));</script>
    <script src="{{ asset('vendors/select2/select2.min.js') }}"></script>
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/FixedAssets/fixed-assets.js') }}"></script>
@endpush

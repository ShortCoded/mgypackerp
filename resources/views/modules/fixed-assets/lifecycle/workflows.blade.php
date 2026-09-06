@extends('layouts.app')
@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
@endphp
@section('title', __('fixed_assets.product.movements'))
@section('content')
<div class="card mb-3"><div class="card-header"><h5 class="mb-0">{{ __('fixed_assets.product.movements') }}</h5></div><div class="card-body">
<form method="GET" class="row g-3 align-items-end" autocomplete="off">
    <div class="col-12 col-sm-6 col-xl-3"><label class="form-label" for="movement-asset">{{ __('fixed_assets.reports.columns.asset') }}</label><select id="movement-asset" class="form-select js-select2-ajax" name="asset_doc_num" data-url="{{ route('admin.fixed-assets.select2.assets') }}" data-allow-clear="true">@if($filters['asset_doc_num'] ?? null)<option selected value="{{ $filters['asset_doc_num'] }}">{{ $filters['asset_doc_num'] }}</option>@endif</select></div>
    <div class="col-12 col-sm-6 col-xl-3"><label class="form-label" for="movement-type">{{ __('fixed_assets.cycle.type') }}</label><select id="movement-type" class="form-select" name="movement_type"><option value=""></option>@foreach($movementTypes as $type)<option value="{{ $type }}" @selected(($filters['movement_type'] ?? '') === $type)>{{ __('fixed_assets.cycle.'.$type) }}</option>@endforeach</select></div>
    @foreach(['from_date', 'to_date'] as $date)<div class="col-12 col-sm-6 col-xl-3"><label class="form-label" for="movement-{{ $date }}">{{ __('fixed_assets.reports.'.$date) }}</label><input id="movement-{{ $date }}" class="form-control js-date-picker" name="{{ $date }}" value="{{ isset($filters[$date]) ? $dates->formatDate($filters[$date], '') : '' }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}"></div>@endforeach
    <div class="col-12 col-sm-6 col-xl-3"><label class="form-label" for="movement-branch">{{ __('fixed_assets.attributes.branch') }}</label><select id="movement-branch" class="form-select js-select2-ajax" name="branch_doc_num" data-url="{{ route('admin.fixed-assets.select2.branches') }}" data-allow-clear="true">@if($filters['branch_doc_num'] ?? null)<option selected value="{{ $filters['branch_doc_num'] }}">{{ $filters['branch_doc_num'] }}</option>@endif</select></div>
    <div class="col-12 col-sm-6 col-xl-3"><label class="form-label" for="movement-user">{{ __('fixed_assets.cycle.user') }}</label><input id="movement-user" class="form-control" name="user" value="{{ $filters['user'] ?? '' }}"></div>
    <div class="col-auto"><button class="btn btn-falcon-primary">{{ __('common.actions.apply') }}</button></div><div class="col-auto"><a class="btn btn-falcon-default" href="{{ route('admin.fixed-assets.movements.index') }}">{{ __('common.actions.reset') }}</a></div>
</form></div></div>
<div class="card"><div class="card-body table-responsive"><table class="table table-sm align-middle" style="min-width:60rem"><thead><tr>
@foreach(['date', 'document', 'asset', 'movement_type', 'amount', 'status', 'user', 'journal_entry'] as $column)<th>{{ __('fixed_assets.reports.columns.'.$column) }}</th>@endforeach
</tr></thead><tbody>
@forelse($rows as $row)<tr><td class="text-nowrap">{{ $dates->formatDate($row['date'], '') }}</td><td class="text-nowrap"><a href="{{ $row['_url'] }}">{{ $row['document'] }}</a></td><td><a href="{{ $row['_asset_url'] }}">{{ $row['asset'] }}</a></td><td><span class="badge badge-subtle-info">{{ $row['movement_type'] }}</span><div class="small text-600">{{ $row['reason'] }}</div></td><td dir="ltr">{{ $row['amount'] === null || $row['amount'] === '' ? '—' : $numbers->format($row['amount']).' '.$row['currency'] }}</td><td>{{ $row['status'] }}</td><td>{{ $row['user'] }}</td><td>@if($row['_journal_url'])@can('journal_entries.view')<a href="{{ $row['_journal_url'] }}">{{ $row['journal_entry'] }}</a>@else {{ $row['journal_entry'] }} @endcan @endif</td></tr>@empty<tr><td colspan="8" class="text-center py-4">{{ __('fixed_assets.product.no_movements') }}</td></tr>@endforelse
</tbody></table>{{ $rows->links() }}</div></div>
@endsection
@push('scripts')
<script src="{{ asset('vendors/select2/select2.min.js') }}"></script>
<script src="{{ asset('assets/js/modules/FixedAssets/fixed-assets.js') }}"></script>
@endpush

@extends('layouts.app')

@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $route = request()->route()?->getName() ?? '';
    $exportBase = str_replace('.index', '.export', $route);
@endphp
@section('title', $report['title'])
@section('content')
<div data-financial-analytics="{{ $report['type'] }}">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
        <div><h1 class="h4 mb-1">{{ $report['title'] }}</h1><p class="text-600 mb-0">{{ $report['description'] }}</p></div>
        <div class="d-flex gap-2"><a class="btn btn-falcon-success btn-sm" href="{{ route($exportBase, request()->query() + ['financial_analytics_format' => 'excel']) }}">{{ __('reports.export_excel') }}</a><a class="btn btn-falcon-default btn-sm" href="{{ route($exportBase, request()->query() + ['financial_analytics_format' => 'csv']) }}">{{ __('reports.export_csv') }}</a><a class="btn btn-falcon-default btn-sm" target="_blank" href="{{ route($exportBase, request()->query() + ['financial_analytics_format' => 'pdf']) }}">{{ __('reports.export_pdf') }}</a></div>
    </div>
    <div class="card mb-3"><div class="card-header"><h2 class="h6 mb-0">{{ __('financial_analytics.filters_title') }}</h2></div><div class="card-body"><form method="GET" action="{{ url()->current() }}" class="row g-3">
        @foreach(['from_date', 'to_date', 'comparison_from_date', 'comparison_to_date'] as $field)<div class="col-6 col-xl-2"><label class="form-label">{{ __('financial_analytics.filters.'.$field) }}</label><x-forms.input class="form-control" type="date" name="{{ $field }}" value="{{ $filters[$field] ?? '' }}" /></div>@endforeach
        @if($report['type'] === \Modules\Accounting\Services\FinancialAnalyticsReportService::ExpenseAnalysis)
        <div class="col-6 col-xl-2"><label class="form-label">{{ __('financial_analytics.filters.view_mode') }}</label><x-forms.select class="form-select" name="view_mode"><option value="summary" @selected($filters['view_mode'] === 'summary')>{{ __('financial_analytics.values.summary') }}</option><option value="detail" @selected($filters['view_mode'] === 'detail')>{{ __('financial_analytics.values.detail') }}</option></x-forms.select></div>
        @foreach(['accounts' => 'account_doc_num', 'classifications' => 'classification_code', 'cost_centers' => 'cost_center_doc_num', 'branches' => 'branch_doc_num', 'currencies' => 'currency_doc_num'] as $optionKey => $field)<div class="col-12 col-md-4"><label class="form-label">{{ __('financial_analytics.filters.'.$field) }}</label><x-forms.select class="form-select js-select2-local" name="{{ $field }}" data-allow-clear="true"><option value=""></option>@foreach($filterOptions[$optionKey] as $option)@php($value = $option->{$field === 'classification_code' ? 'code' : 'doc_num'} ?? '')<option value="{{ $value }}" @selected(($filters[$field] ?? '') === $value)>{{ implode(' / ', array_filter([$value, $option->account_code ?? $option->cost_center_code ?? null, $option->code ?? null, app()->getLocale() === 'en' ? ($option->name_en ?? $option->name) : $option->name])) }}</option>@endforeach</x-forms.select></div>@endforeach
        <div class="col-12 col-md-4"><label class="form-label">{{ __('financial_analytics.filters.source_type') }}</label><x-forms.input class="form-control" name="source_type" value="{{ $filters['source_type'] ?? '' }}" /></div><div class="col-12 col-md-4"><label class="form-label">{{ __('financial_analytics.filters.source_doc_num') }}</label><x-forms.input class="form-control" name="source_doc_num" value="{{ $filters['source_doc_num'] ?? '' }}" /></div>
        @endif
        <div class="col-12 d-flex gap-2"><button class="btn btn-falcon-primary" type="submit">{{ __('common.actions.apply') }}</button><a class="btn btn-falcon-default" href="{{ url()->current() }}">{{ __('common.actions.reset') }}</a></div>
    </form></div></div>
    @foreach($report['notices'] as $notice)<div class="alert alert-info py-2">{{ $notice }}</div>@endforeach
    @if($report['comparison_total'] !== null)<div class="alert alert-secondary">{{ __('financial_analytics.comparison_total') }}: <span dir="ltr">{{ $numbers->format($report['comparison_total']) }}</span></div>@endif
    <div class="card"><div class="table-responsive"><table class="table table-sm table-striped table-hover mb-0"><thead><tr>@foreach($report['columns'] as $label)<th>{{ $label }}</th>@endforeach</tr></thead><tbody>@forelse($report['rows'] as $row)<tr>@foreach($report['columns'] as $key => $label)@php($value = $row[$key] ?? '')<td class="{{ in_array($key, $report['numeric_columns'], true) ? 'text-end' : '' }}">@if($key === 'journal' && filled($row['_url'] ?? null))<a href="{{ $row['_url'] }}">{{ $value }}</a>@elseif(in_array($key, $report['numeric_columns'], true) && is_numeric($value))<span dir="ltr">{{ $numbers->format($value) }}</span>@else{{ $value }}@endif</td>@endforeach</tr>@empty<tr><td colspan="{{ count($report['columns']) }}" class="text-center text-600 py-4">{{ __('financial_analytics.no_results') }}</td></tr>@endforelse</tbody>@if($report['totals'])<tfoot><tr><th>{{ __('common.total') }}</th>@foreach(array_slice(array_keys($report['columns']), 1) as $key)<th class="text-end">{{ isset($report['totals'][$key]) ? $numbers->format($report['totals'][$key]) : '' }}</th>@endforeach</tr></tfoot>@endif</table></div></div>
</div>
@endsection

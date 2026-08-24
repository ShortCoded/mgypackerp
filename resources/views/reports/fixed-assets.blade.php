@extends('reports.fixed-assets.layouts.document')

@section('fixed_asset_content')
    <table class="fa-pdf-document-heading">
        <tr><td><div class="fa-pdf-document-title">{{ $report['title'] }}</div></td></tr>
    </table>
    @include('reports.fixed-assets.partials.filter-summary')
    @include('reports.fixed-assets.partials.report-table')
    @include('reports.fixed-assets.partials.totals')
@endsection

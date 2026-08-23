@extends('reports.layouts.pdf')

@section('report')
    <h3>{{ $report['title'] }}</h3>
    <x-fixed-asset-report-table :report="$report" />
@endsection

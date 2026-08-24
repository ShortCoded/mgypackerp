@extends('reports.layouts.pdf')

@section('report')
    @include('reports.fixed-assets.partials.company-identity')
    @yield('fixed_asset_content')
@endsection

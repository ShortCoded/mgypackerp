@extends('reports.layouts.pdf')

@section('report')
    <table class="report-table">
        <thead>
            <tr>
                @foreach ($headings as $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($headings) }}">{{ __('reports.no_data') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection

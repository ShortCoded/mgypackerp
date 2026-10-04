<div class="report-print" dir="{{ $direction ?? 'ltr' }}">
<div class="document-title-row"><h1>{{ $reportTitle }}</h1></div>
@foreach($sections as $section)
    @unless($loop->first)<h2 style="font-size:13px; margin:14px 0 6px; color:#17374b;">{{ $section['title'] }}</h2>@endunless
    <table dir="{{ $direction ?? 'ltr' }}" class="report-table">
        <thead><tr>@foreach($section['headings'] as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse($section['rows'] as $row)
            <tr>@foreach($row as $value)<td @if(preg_match('/^-?\d+\.\d{8}$/D', $value)) class="number" dir="ltr" @endif>{{ $value === '' ? '—' : $value }}</td>@endforeach</tr>
        @empty
            <tr><td colspan="{{ count($section['headings']) }}">{{ __('reports.no_data') }}</td></tr>
        @endforelse
        </tbody>
    </table>
@endforeach
</div>

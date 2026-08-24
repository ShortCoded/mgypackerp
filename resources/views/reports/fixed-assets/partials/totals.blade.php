@if ($report['totals'] !== [])
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    <div class="fa-pdf-totals">
        <table class="fa-pdf-totals-table">
            @foreach ($report['totals'] as $label => $value)
                <tr><th>{{ $label }}</th><td class="fa-pdf-number">{{ $numbers->format($value) }}</td></tr>
            @endforeach
        </table>
    </div>
@endif

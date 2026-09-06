@php
    $wordsAmount = $amount ?? $record->total_amount ?? $record->amount ?? null;
    $wordsCurrency = $currency ?? $record->currency;
    $amountInWords = null;
    if ($wordsAmount !== null && class_exists(\NumberFormatter::class)) {
        $formatter = new \NumberFormatter(app()->getLocale(), \NumberFormatter::SPELLOUT);
        $roundedAmount = number_format((float) $wordsAmount, 2, '.', '');
        [$major, $minor] = explode('.', $roundedAmount);
        $amountInWords = $formatter->format((int) $major).' '.($wordsCurrency?->name ?? $wordsCurrency?->code ?? '');
        if ((int) $minor > 0) {
            $amountInWords .= ' '.__('and').' '.$formatter->format((int) $minor).' '.($wordsCurrency?->minor_unit_name ?? '/100');
        }
    }
@endphp
@if($amountInWords)<div class="document-amount-words"><strong>{{ __('Amount in words') }}:</strong> {{ $amountInWords }}</div>@endif

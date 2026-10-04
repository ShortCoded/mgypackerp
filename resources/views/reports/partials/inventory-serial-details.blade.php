@if($transaction->serialNumbers() !== [])
    <div class="document-item-details"><span>{{ __('inventory_serial.numbers') }}:</span> <span dir="ltr">{{ implode(', ', $transaction->serialNumbers()) }}</span></div>
@endif

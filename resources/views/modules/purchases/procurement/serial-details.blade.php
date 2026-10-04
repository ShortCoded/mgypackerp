@php
    $serialDetails = $line->relationLoaded('serialDisplayNumbers') ? $line->serialDisplayNumbers->all() : ($line->serial_numbers ?? []);
@endphp
@if($serialDetails !== [])
    <div class="document-item-details"><span>{{ __('inventory_serial.numbers') }}:</span> <span dir="ltr">{{ implode(', ', $serialDetails) }}</span></div>
@endif

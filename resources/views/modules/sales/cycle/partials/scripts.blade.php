@php
    $salesCycleMessages = [
        'priceUrl' => route('admin.sales.price-suggestion'),
        'lastPrice' => __('Suggested price'),
        'actionFailed' => __('The action could not be completed.'),
        'saved' => __('Saved successfully.'),
        'unexpectedError' => __('Unexpected browser error.'),
    ];
@endphp
<script>
    window.salesCycleMessages = @json($salesCycleMessages);
</script>
<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Sales/sales-cycle.js') }}"></script>

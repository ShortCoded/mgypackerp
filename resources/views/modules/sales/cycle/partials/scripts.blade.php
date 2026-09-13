@php
    $salesCycleMessages = [
        'priceUrl' => route('admin.sales.price-suggestion'),
        'customerPriceList' => __('price_lists.customer_source'),
        'generalPriceList' => __('price_lists.general_source'),
        'emptyPrice' => __('price_lists.not_selected'),
        'actionFailed' => __('The action could not be completed.'),
        'saved' => __('Saved successfully.'),
        'unexpectedError' => __('Unexpected browser error.'),
    ];
@endphp
<script>
    window.salesCycleMessages = @json($salesCycleMessages);
</script>
<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Sales/sales-cycle.js') }}"></script>

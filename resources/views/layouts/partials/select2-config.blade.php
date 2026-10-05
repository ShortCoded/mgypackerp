@php
    $appSelect2 = [
        'perPage' => (int) config('select2.pagination.per_page', 25),
        'delay' => (int) config('select2.delay', 250),
        'minimumInputLength' => (int) config('select2.minimum_input_length', 0),
        'placeholder' => __('common.placeholders.select'),
        'clearAllLabel' => __('common.actions.clear_all'),
        'messages' => __('common.select2'),
    ];
@endphp
<script>window.AppSelect2 = @json($appSelect2);</script>

@php
    $erpAsset = $erpAsset ?? app(\Modules\Core\Services\AssetVersionService::class);
@endphp
<script src="{{ $erpAsset->url('assets/js/modules/Core/falcon-defaults.js') }}"></script>
<script src="{{ $erpAsset->url('assets/js/config.js') }}"></script>
<script>
    window.ErpFalconDefaults.apply();
</script>

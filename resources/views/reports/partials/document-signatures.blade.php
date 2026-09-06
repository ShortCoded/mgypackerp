@php
    $signatureRoles = match ($signatureType ?? $type ?? $kind ?? '') {
        'purchase-requisition' => [__('Requested By') => $record->requested_by, __('Warehouse keeper') => null, __('Approved By') => $record->approved_by],
        'goods-receipt', 'inventory' => [__('Prepared by') => $record->created_by, __('Warehouse keeper') => null, __('Received by') => $record->received_by, __('Approved By') => $record->approved_by ?? $record->posted_by],
        'payment' => [__('Prepared by') => $record->created_by, __('Treasury') => null, __('Received by') => null, __('Approved By') => $record->approved_by],
        default => [__('Prepared by') => $record->created_by, __('Reviewed by') => null, __('Approved By') => $record->approved_by],
    };
    $signatureUsers = isset($signatureNames) ? collect() : \App\Models\User::query()->whereIn('id', array_filter($signatureRoles))->pluck('name', 'id');
    if (isset($signatureNames)) { $signatureRoles = $signatureNames; }
@endphp
<table class="document-signatures"><tr>
    @foreach($signatureRoles as $label => $userId)<td style="width:{{ 100 / count($signatureRoles) }}%"><strong>{{ $label }}</strong><br>{{ isset($signatureNames) ? $userId : ($signatureUsers[$userId] ?? '') }}<div class="document-signature-space"></div></td>@endforeach
</tr></table>

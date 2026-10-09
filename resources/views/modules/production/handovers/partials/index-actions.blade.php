@php
    $prefix = $warehouse ? 'admin.inventory.production-receipts' : 'admin.production.handovers';
@endphp
<div class="dropstart font-sans-serif position-static d-inline-block">
    <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}"><span class="fas fa-ellipsis-h fs-10"></span></button>
    <div class="py-2 border dropdown-menu dropdown-menu-end">
        <a class="dropdown-item" href="{{ route($prefix.'.show', $record) }}">{{ __('common.actions.view') }}</a>
        <x-document-owner-actions :record="$record" />
    </div>
</div>

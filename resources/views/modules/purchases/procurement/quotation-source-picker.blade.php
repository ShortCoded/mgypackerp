@extends('layouts.app')

@section('title', __('Create Supplier Quotation'))

@section('content')
    <div class="card mb-3">
        <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h5 class="mb-1">{{ __('Create Supplier Quotation') }}</h5>
                <p class="text-600 mb-0">{{ __('Choose the approved document whose items will be priced by the supplier.') }}</p>
            </div>
            <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.supplier-quotation-entry.index') }}">{{ __('common.actions.back') }}</a>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-xl-6">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex gap-3 mb-3">
                        <span class="fas fa-clipboard-check text-primary fs-4"></span>
                        <div>
                            <h6 class="mb-1">{{ __('From Purchase Request') }}</h6>
                            <p class="text-600 mb-0">{{ __('Use approved requested quantities as quotation lines.') }}</p>
                        </div>
                    </div>
                    <form data-procurement-source-picker data-destination="{{ route('admin.purchases.supplier-quotation-entry.create-source', [\Modules\Purchases\Models\SupplierQuotation::SourcePurchaseRequisition, '__DOCUMENT__']) }}" class="row g-3 align-items-end">
                        <div class="col-12 col-md-8">
                            <label class="form-label" for="quotation_purchase_request">{{ __('Purchase Request') }}</label>
                            <x-forms.select id="quotation_purchase_request" class="form-select js-select2-ajax" data-url="{{ route('admin.purchases.select2.requisitions', ['purpose' => 'supplier_quotation']) }}" data-placeholder="{{ __('Select') }}" required></x-forms.select>
                        </div>
                        <div class="col-12 col-md-4 d-grid">
                            <button class="btn btn-primary">{{ __('procurement.ui.load_lines') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-6">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex gap-3 mb-3">
                        <span class="fas fa-file-signature text-primary fs-4"></span>
                        <div>
                            <h6 class="mb-1">{{ __('From Purchase Order') }}</h6>
                            <p class="text-600 mb-0">{{ __('Use approved purchase order quantities and its current supplier as defaults.') }}</p>
                        </div>
                    </div>
                    <form data-procurement-source-picker data-destination="{{ route('admin.purchases.supplier-quotation-entry.create-source', [\Modules\Purchases\Models\SupplierQuotation::SourcePurchaseOrder, '__DOCUMENT__']) }}" class="row g-3 align-items-end">
                        <div class="col-12 col-md-8">
                            <label class="form-label" for="quotation_purchase_order">{{ __('Purchase Order') }}</label>
                            <x-forms.select id="quotation_purchase_order" class="form-select js-select2-ajax" data-url="{{ route('admin.purchases.select2.purchase-orders', ['purpose' => 'supplier_quotation']) }}" data-placeholder="{{ __('Select') }}" required></x-forms.select>
                        </div>
                        <div class="col-12 col-md-4 d-grid">
                            <button class="btn btn-primary">{{ __('procurement.ui.load_lines') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Purchases/procurement-cycle.js') }}"></script>
@endpush

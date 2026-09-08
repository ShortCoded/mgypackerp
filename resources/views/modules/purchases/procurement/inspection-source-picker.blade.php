@extends('layouts.app')

@section('title', __('Purchase Inspection'))

@section('content')
    <div class="card shadow-none border">
        <div class="card-header py-2 d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <div>
                <h5 class="mb-1">{{ __('Create Purchase Inspection') }}</h5>
                <p class="text-600 fs-10 mb-0">{{ __('Quality records the delivered, accepted, and rejected quantities before the warehouse creates a goods receipt.') }}</p>
            </div>
            <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.goods-receipt-inspection.index') }}">
                <span class="fas fa-arrow-right me-1"></span>{{ __('common.actions.back') }}
            </a>
        </div>
        <div class="card-body">
            <div class="row g-3">
                @foreach([
                    [
                        'id' => 'inspection_purchase_order',
                        'title' => __('From Purchase Order'),
                        'help' => __('Use an approved purchase order when no separate supply order was issued.'),
                        'lookup' => 'purchase-orders',
                    ],
                    [
                        'id' => 'inspection_supply_order',
                        'title' => __('From Supply Order'),
                        'help' => __('Use the supplier-facing supply order when it exists.'),
                        'lookup' => 'supply-orders',
                    ],
                ] as $option)
                    <div class="col-12 col-xl-6">
                        <form
                            class="h-100 border rounded-3 p-3 d-flex flex-column gap-3"
                            data-procurement-source-picker
                            data-destination="{{ route('admin.purchases.goods-receipt-inspection.create', '__DOCUMENT__') }}"
                        >
                            <div>
                                <h6 class="mb-1">{{ $option['title'] }}</h6>
                                <p class="text-600 fs-10 mb-0">{{ $option['help'] }}</p>
                            </div>
                            <div>
                                <label class="form-label" for="{{ $option['id'] }}">{{ __('Source document') }}</label>
                                <select
                                    id="{{ $option['id'] }}"
                                    class="form-select js-select2-ajax"
                                    data-url="{{ route('admin.purchases.select2.'.$option['lookup'], ['purpose' => 'inspection']) }}"
                                    data-placeholder="{{ __('Select') }}"
                                    required
                                ></select>
                            </div>
                            <button class="btn btn-primary mt-auto" type="submit">
                                <span class="fas fa-list-check me-1"></span>{{ __('procurement.ui.load_lines') }}
                            </button>
                        </form>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Purchases/procurement-cycle.js') }}"></script>
@endpush

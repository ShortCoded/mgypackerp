@extends('layouts.app')

@section('title', __('inventory_accounting.title'))

@section('content')
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    <div class="card">
        <div class="card-header">
            <h5 class="mb-1">{{ __('inventory_accounting.title') }}</h5>
            <p class="text-600 mb-0">{{ __('inventory_accounting.help') }}</p>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <strong>{{ __('inventory_accounting.fields.valuation_method') }}:</strong>
                {{ __('inventory_accounting.valuation_methods.moving_average') }}
            </div>
            <form method="POST" action="{{ route('admin.inventory.accounting.store') }}" class="row g-3">
                @csrf
                @php
                    $fields = [
                        'raw_material_inventory_account_doc_num' => $mapping?->rawMaterialInventoryAccount,
                        'packaging_inventory_account_doc_num' => $mapping?->packagingInventoryAccount,
                        'semi_finished_inventory_account_doc_num' => $mapping?->semiFinishedInventoryAccount,
                        'finished_goods_inventory_account_doc_num' => $mapping?->finishedGoodsInventoryAccount,
                        'wip_account_doc_num' => $mapping?->wipAccount,
                        'production_waste_account_doc_num' => $mapping?->productionWasteAccount,
                        'recoverable_scrap_inventory_account_doc_num' => $mapping?->recoverableScrapInventoryAccount,
                        'warehouse_damage_loss_account_doc_num' => $mapping?->warehouseDamageLossAccount,
                        'inventory_adjustment_gain_account_doc_num' => $mapping?->inventoryAdjustmentGainAccount,
                        'inventory_adjustment_loss_account_doc_num' => $mapping?->inventoryAdjustmentLossAccount,
                        'production_variance_account_doc_num' => $mapping?->productionVarianceAccount,
                    ];
                @endphp
                @foreach($fields as $field => $account)
                    <div class="col-md-6">
                        <label class="form-label" for="{{ $field }}">{{ __('inventory_accounting.fields.'.$field) }}</label>
                        <select class="form-select js-select2-ajax" id="{{ $field }}" name="{{ $field }}" data-url="{{ route('admin.inventory.accounting.accounts') }}" data-placeholder="{{ __('common.placeholders.select') }}" data-allow-clear="{{ in_array($field, ['recoverable_scrap_inventory_account_doc_num', 'production_variance_account_doc_num'], true) ? 'true' : 'false' }}" @if(! in_array($field, ['recoverable_scrap_inventory_account_doc_num', 'production_variance_account_doc_num'], true)) required @endif>
                            @if($account)<option value="{{ $account->doc_num }}" selected>{{ $account->codeNameLabel() }}{{ $account->status !== 'active' || $account->trashed() ? ' — '.__('inventory_accounting.historical') : '' }}</option>@endif
                        </select>
                    </div>
                @endforeach
                <div class="col-md-6">
                    <label class="form-label" for="production_cost_center_doc_num">{{ __('inventory_accounting.fields.production_cost_center_doc_num') }}</label>
                    <select class="form-select js-select2-ajax" id="production_cost_center_doc_num" name="production_cost_center_doc_num" data-url="{{ route('admin.inventory.accounting.cost-centers') }}" data-placeholder="{{ __('common.placeholders.select') }}" data-allow-clear="true">
                        @if($mapping?->productionCostCenter)<option value="{{ $mapping->productionCostCenter->doc_num }}" selected>{{ $mapping->productionCostCenter->codeNameLabel() }}</option>@endif
                    </select>
                </div>
                @can('inventory.accounting.configure')
                    <div class="col-12"><button class="btn btn-primary" type="submit"><span class="fas fa-save me-1"></span>{{ __('common.actions.save') }}</button></div>
                @endcan
            </form>
        </div>
    </div>
@endsection

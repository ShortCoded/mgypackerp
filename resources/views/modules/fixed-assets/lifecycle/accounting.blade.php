@extends('layouts.app')

@section('title', __('fixed_assets.lifecycle.accounting_mappings'))

@section('content')
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    @if($setupError)<div class="alert alert-warning" role="alert">{{ $setupError }}</div>@endif
    <div class="card">
        <div class="card-header"><h5 class="mb-0">{{ __('fixed_assets.lifecycle.accounting_mappings') }}</h5><p class="text-600 mb-0 mt-1">{{ __('fixed_assets.lifecycle.accounting_mappings_help') }}</p></div>
        <div class="card-body">
            <div class="accordion" id="fixed-asset-accounting-mappings">
                @foreach($categories as $category)
                    @php($mapping = $mappings->get($category->getKey()))
                    <div class="accordion-item">
                        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#mapping-{{ $category->getKey() }}">{{ $category->codeNameLabel() }} @if($mapping)<span class="badge badge-subtle-success ms-2">{{ __('fixed_assets.lifecycle.configured') }}</span>@endif</button></h2>
                        <div class="accordion-collapse collapse" id="mapping-{{ $category->getKey() }}" data-bs-parent="#fixed-asset-accounting-mappings"><div class="accordion-body">
                            <form method="POST" action="{{ route('admin.fixed-assets.accounting.store') }}" class="row g-3">@csrf
                                <input type="hidden" name="asset_group_account_doc_num" value="{{ $category->doc_num }}">
                                @foreach([
                                    'accumulated_depreciation_account_doc_num' => $mapping?->accumulatedDepreciationAccount,
                                    'depreciation_expense_account_doc_num' => $mapping?->depreciationExpenseAccount,
                                    'disposal_gain_account_doc_num' => $mapping?->disposalGainAccount,
                                    'disposal_loss_account_doc_num' => $mapping?->disposalLossAccount,
                                    'disposal_clearing_account_doc_num' => $mapping?->disposalClearingAccount,
                                ] as $field => $account)
                                    <div class="col-md-6"><label class="form-label">{{ __('fixed_assets.lifecycle.mapping_fields.'.$field) }}</label><select class="form-select js-select2-ajax" name="{{ $field }}" data-url="{{ route('admin.fixed-assets.select2.credit-accounts') }}" data-placeholder="{{ __('fixed_assets.placeholders.account') }}" data-allow-clear="true">@if($account)<option value="{{ $account->doc_num }}" selected>{{ $account->codeNameLabel() }}</option>@endif</select></div>
                                @endforeach
                                <div class="col-12"><button class="btn btn-falcon-primary" type="submit"><span class="fas fa-save me-1"></span>{{ __('common.actions.save') }}</button></div>
                            </form>
                        </div></div>
                    </div>
                @endforeach
            </div>
            @if(! $setupError && $categories->isEmpty())
                <div class="alert alert-info mb-0">{{ __('fixed_assets.lifecycle.errors.no_asset_categories') }}</div>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script>window.fixedAssetsMessages = @json(__('fixed_assets.js'));</script>
    <script src="{{ asset('vendors/select2/select2.min.js') }}"></script>
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/FixedAssets/fixed-assets.js') }}"></script>
@endpush

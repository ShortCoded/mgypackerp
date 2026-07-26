@extends('layouts.app')

@php
    use Modules\Core\Services\FilePickerService;
    use Modules\Core\Services\NumericFormatService;
    use Modules\Core\Services\OperatingCompanyContextService;
    use Modules\FixedAssets\Services\FixedAssetImageResolver;

    $isView = $mode === 'view';
    $isClone = $mode === 'clone';
    $isCreateLike = in_array($mode, ['create', 'clone'], true);
    $title = __("fixed_assets.{$mode}");
    $fixedAssetClass = \Modules\FixedAssets\Models\FixedAsset::class;
    $dateFormatService = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(NumericFormatService::class);
    $formatDate = fn ($date) => $dateFormatService->formatDate($date, '');
    $value = fn ($field, $default = '') => old($field, $record?->{$field} ?? $default);
    $linkedAccount = $record?->account;
    $groupAccount = $record?->assetGroupAccount;
    if (! $groupAccount && $linkedAccount) {
        $groupAccount = app(\Modules\Accounting\Services\BusinessPartnerAccountService::class)
            ->linkedAccountGroup(\Modules\Accounting\Services\BusinessPartnerAccountService::FixedAsset, $linkedAccount);
    }
    $accountOption = fn ($account) => $account ? ['id' => $account->doc_num, 'text' => $account->codeNameLabel()] : null;
    $branchOption = $record?->branch ? ['id' => $record->branch->doc_num, 'text' => trim(implode(' / ', array_filter([$record->branch->doc_num, $record->branch->name])))] : null;
    $hallOption = $record?->branchHall ? ['id' => $record->branchHall->public_uuid, 'text' => $record->branchHall->name] : null;
    $costCenterOption = $record?->costCenter ? ['id' => $record->costCenter->doc_num, 'text' => $record->costCenter->codeNameLabel()] : null;
    $currencyOption = $record?->currency ? ['id' => $record->currency->doc_num, 'text' => trim(implode(' / ', array_filter([$record->currency->code, $record->currency->name])))] : ($defaults['currency_option'] ?? null);
    $documentNumberValue = old('doc_number', ! $isCreateLike ? $record?->doc_number : '');
    $dateValue = fn ($field) => old($field, $record?->{$field} ? $formatDate($record->{$field}) : ($field === 'asset_date' ? ($defaults['asset_date'] ?? '') : ''));
    $numericValue = fn ($field, $default = '') => old($field, $record?->{$field} ?? $default);
    $entryTypeValue = old('entry_type', $record?->entry_type ?? $fixedAssetClass::EntryTypeNewAsset);
    $isDepreciableValue = old('is_depreciable', ($record?->is_depreciable ?? true) ? '1' : '0');
    $isDepreciableSelected = (string) $isDepreciableValue === '1';
    $depreciationMethodValue = old('depreciation_method', $record?->depreciation_method ?? ($isDepreciableSelected ? $fixedAssetClass::DepreciationMethodStraightLine : ''));
    $usefulLifeValue = $numericValue('useful_life');
    $annualDepreciationRateValue = $numericValue('annual_depreciation_rate');
    $expectedUsageUnitsValue = $numericValue('expected_usage_units');
    $requiresUsefulLife = $isDepreciableSelected
        && in_array($depreciationMethodValue, [
            $fixedAssetClass::DepreciationMethodStraightLine,
            $fixedAssetClass::DepreciationMethodDoubleDecliningBalance,
            $fixedAssetClass::DepreciationMethodSumOfYearsDigits,
        ], true)
        && ($depreciationMethodValue !== $fixedAssetClass::DepreciationMethodStraightLine || $annualDepreciationRateValue === '');
    $requiresAnnualDepreciationRate = $isDepreciableSelected && $depreciationMethodValue === $fixedAssetClass::DepreciationMethodDecliningBalance;
    $requiresExpectedUsageUnits = $isDepreciableSelected && $depreciationMethodValue === $fixedAssetClass::DepreciationMethodUnitsOfProduction;
    $previousDepreciationRaw = old('previous_depreciation', $record?->previous_depreciation ?? '');
    $normalizedPreviousDepreciation = $numbers->normalizeForValidation($previousDepreciationRaw);
    $previousDepreciationNumeric = is_numeric($normalizedPreviousDepreciation) ? (float) $normalizedPreviousDepreciation : 0.0;
    $hasPreviousDepreciation = $previousDepreciationNumeric > 0;
    $selectedImagePublicId = ! $isView ? trim((string) old('image_archive_file_doc_num', '')) : '';
    $removeImageRequested = ! $isView && filter_var(old('remove_image', false), FILTER_VALIDATE_BOOL);
    $selectedImageFile = null;
    if ($selectedImagePublicId !== '') {
        $selectedImageCompanyId = app(OperatingCompanyContextService::class)->currentCompanyId();
        $selectedImageFile = $selectedImageCompanyId
            ? app(FilePickerService::class)->selectableFileByPublicId($selectedImagePublicId, $selectedImageCompanyId, FilePickerService::AcceptImage)
            : null;
    }
    $existingImageUrl = $record && ! $isClone ? app(FixedAssetImageResolver::class)->url($record) : null;
    $imageUrl = $removeImageRequested && ! $selectedImageFile
        ? null
        : ($selectedImageFile
            ? route('admin.file-manager.files.preview', $selectedImageFile->doc_num)
            : $existingImageUrl);
    $imageFileLabel = $selectedImageFile
        ? $selectedImageFile->original_name
        : ($imageUrl ? __('fixed_assets.image.existing_file') : __('fixed_assets.image.no_file_selected'));
@endphp

@section('title', $title)

@push('styles')
    <style>
        .fixed-asset-form-card .fixed-asset-select-control {
            display: flex;
            align-items: flex-start;
            gap: .5rem;
        }

        .fixed-asset-form-card .fixed-asset-select-control .select2-container {
            flex: 1 1 auto;
            min-width: 0;
        }

        .fixed-asset-form-card .fixed-asset-select-control .btn {
            flex: 0 0 auto;
            white-space: nowrap;
        }

        .fixed-asset-form-card .select2-selection.is-invalid {
            border-color: var(--falcon-danger, #e63757);
        }

        .fixed-asset-form-card .fixed-asset-disabled-field {
            opacity: .65;
        }

        .fixed-asset-form-card .fixed-asset-hidden-field {
            display: none !important;
        }

        .fixed-asset-form-card .fixed-asset-image-field .fixed-asset-image-picker-panel {
            min-height: 100%;
        }

        .fixed-asset-form-card .fixed-asset-image-preview-frame {
            width: 6.75rem !important;
            height: 6.75rem !important;
            min-width: 6.75rem !important;
            aspect-ratio: 1 / 1;
        }

        .fixed-asset-form-card .fixed-asset-image-preview-frame .js-fixed-asset-image-preview-image {
            display: block;
            width: 100%;
            height: 100%;
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
    </style>
@endpush

@section('content')
    <form class="js-fixed-asset-form js-crud-form" action="{{ $action }}" method="{{ $method }}" data-primary-focus="asset_name" data-mode="{{ $mode }}" data-main-currency-doc-num="{{ $defaults['main_currency_doc_num'] ?? '' }}" novalidate>
        @csrf
        @if($method !== 'POST')
            @method($method)
        @endif
        <input type="hidden" name="submit_action" value="save">
        @if($cloneSourceToken)
            <input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}">
        @endif

        <div class="mb-3 card fixed-asset-form-card">
            <div class="card-header">
                <div class="row flex-between-center g-2">
                    <div class="col"><h5 class="mb-0">{{ $title }}</h5></div>
                    <div class="col-auto">@include('modules.finance.partials.form-actions', ['resource' => 'fixed_assets', 'routePrefix' => 'admin.fixed-assets.assets'])</div>
                </div>
            </div>
            <div class="card-body">
                <div class="alert d-none js-form-alert"><div class="js-form-alert-message"></div></div>
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#fixed-asset-basic-tab" type="button" role="tab">{{ __('fixed_assets.tabs.basic_data') }}</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#fixed-asset-financial-tab" type="button" role="tab">{{ __('fixed_assets.tabs.financial_data') }}</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#fixed-asset-location-tab" type="button" role="tab">{{ __('fixed_assets.tabs.location_notes') }}</button></li>
                </ul>
                <div class="p-3 tab-content border-x border-bottom">
                    <div class="tab-pane fade show active" id="fixed-asset-basic-tab" role="tabpanel">
                        <div class="row g-3">
                            @if($canControlDocumentNumber)
                                <div class="col-lg-3" data-layout-row="basic-identity">
                                    <label class="form-label" for="doc_number">{{ __('fixed_assets.attributes.doc_number') }}</label>
                                    @if($isView)
                                        <x-forms.view-field for="doc_number" as="display" :value="$documentNumberValue" input-class="text-center" />
                                    @else
                                        <input class="text-center form-control" id="doc_number" name="doc_number" type="number" min="0" step="1" inputmode="numeric" value="{{ $documentNumberValue }}" placeholder="{{ __('item_lookups.document_number_control.placeholder') }}">
                                    @endif
                                    <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                                </div>
                            @elseif(! $isCreateLike)
                                <div class="col-lg-3" data-layout-row="basic-identity">
                                    <x-forms.view-field for="doc_num" :label="__('fixed_assets.attributes.doc_num')" :value="$record?->doc_num" input-class="text-center" />
                                </div>
                            @endif

                            <div class="col-lg-3" data-layout-row="basic-identity">
                                <x-forms.label for="entry_type" :label="__('fixed_assets.attributes.entry_type')" required />
                                @if($isView)
                                    <x-forms.view-field for="entry_type" :value="$record?->entryTypeLabel()" />
                                @else
                                    <select class="form-select js-fixed-asset-entry-type" id="entry_type" name="entry_type" required>
                                        @foreach($fixedAssetClass::entryTypes() as $entryType)
                                            <option value="{{ $entryType }}" @selected($entryTypeValue === $entryType)>{{ __("fixed_assets.entry_types.{$entryType}") }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <div class="invalid-feedback" data-error-for="entry_type"></div>
                            </div>

                            <div class="col-lg-3" data-layout-row="basic-identity">
                                <x-forms.label for="asset_date" :label="__('fixed_assets.attributes.asset_date')" required />
                                @if($isView)
                                    <x-forms.view-field for="asset_date" :value="$formatDate($record?->asset_date)" />
                                @else
                                    <input class="form-control js-date-picker" id="asset_date" name="asset_date" type="text" value="{{ $dateValue('asset_date') }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ __('common.placeholders.select_date') }}" autocomplete="off" dir="ltr" required>
                                @endif
                                <div class="invalid-feedback" data-error-for="asset_date"></div>
                            </div>

                            <div class="{{ $canControlDocumentNumber || ! $isCreateLike ? 'col-lg-3' : 'col-lg-6' }}" data-layout-row="basic-identity">
                                <x-forms.label for="asset_name" :label="__('fixed_assets.attributes.asset_name')" required />
                                @if($isView)
                                    <x-forms.view-field for="asset_name" :value="$value('asset_name')" />
                                @else
                                    <input class="form-control" id="asset_name" name="asset_name" value="{{ $value('asset_name') }}" autofocus required>
                                @endif
                                <div class="invalid-feedback" data-error-for="asset_name"></div>
                            </div>

                            <div class="mb-4 col-12 fixed-asset-image-field" data-layout-row="basic-image">
                                <label class="form-label" for="{{ $isView ? 'fixed-asset-image-preview' : 'fixed-asset-image-picker-button' }}">{{ __('fixed_assets.attributes.image') }}</label>
                                @unless($isView)
                                    <input type="hidden" id="fixed-asset-image-archive-file" name="image_archive_file_doc_num" value="{{ $selectedImagePublicId }}">
                                    <input type="hidden" id="fixed-asset-remove-image" name="remove_image" value="{{ $removeImageRequested && ! $selectedImageFile ? '1' : '0' }}">
                                @endunless
                                <div id="fixed-asset-image-picker-field"
                                    class="border rounded-2 bg-body-tertiary p-3 fixed-asset-image-picker-panel js-fixed-asset-image-picker-field @if($isView) opacity-75 @endif"
                                    data-current-url="{{ $imageUrl ?? '' }}"
                                    data-existing-url="{{ $existingImageUrl ?? '' }}"
                                    data-existing-label="{{ __('fixed_assets.image.existing_file') }}"
                                    data-no-image-label="{{ __('fixed_assets.image.no_file_selected') }}">
                                    <div class="gap-3 d-flex flex-column flex-md-row align-items-start">
                                        <div id="fixed-asset-image-preview" class="overflow-hidden flex-shrink-0 bg-white border d-flex align-items-center justify-content-center rounded-2 fixed-asset-image-preview-frame">
                                            <img class="h-100 w-100 object-fit-contain js-fixed-asset-image-preview-image @if(! $imageUrl) d-none @endif"
                                                src="{{ $imageUrl ?? '' }}"
                                                alt="{{ __('fixed_assets.attributes.image') }}">
                                            <span class="fas fa-image text-400 fs-5 js-fixed-asset-image-placeholder @if($imageUrl) d-none @endif"></span>
                                        </div>

                                        <div class="flex-1">
                                            <div class="fw-semibold js-fixed-asset-image-file-name">{{ $imageFileLabel }}</div>
                                            <div class="mt-1 small text-600">
                                                {{ __('fixed_assets.image.help', ['size' => (int) config('archive.logo.max_file_size_mib', 2)]) }}
                                            </div>
                                            @unless($isView)
                                                @can('file_manager.view')
                                                    <div class="flex-wrap gap-2 mt-2 d-flex">
                                                        <button type="button"
                                                            id="fixed-asset-image-picker-button"
                                                            class="btn btn-falcon-primary btn-sm js-fixed-asset-image-picker-trigger"
                                                            data-file-picker
                                                            data-picker-accept="image"
                                                            data-picker-max="1"
                                                            data-picker-title="{{ __('fixed_assets.image.select_from_file_manager') }}"
                                                            data-picker-target-input="#fixed-asset-image-archive-file"
                                                            data-picker-uploader="#fixed-asset-image-picker-field"
                                                            data-picker-collection="fixed_asset_image"
                                                            data-picker-allow-upload="{{ auth()->user()?->can('file_manager.upload') ? 'true' : 'false' }}"
                                                            data-picker-allow-create-folder="{{ auth()->user()?->can('file_manager.folders.create') ? 'true' : 'false' }}">
                                                            <span class="fas fa-images me-1"></span>{{ __('fixed_assets.image.select') }}
                                                        </button>
                                                        <button type="button"
                                                            class="btn btn-falcon-default btn-sm text-danger js-fixed-asset-image-remove @if(! $imageUrl) d-none @endif">
                                                            <span class="fas fa-times me-1"></span>{{ __('fixed_assets.image.remove') }}
                                                        </button>
                                                    </div>
                                                @endcan
                                            @endunless
                                        </div>
                                    </div>
                                </div>
                                <div class="invalid-feedback d-block" data-error-for="image"></div>
                            </div>

                            @foreach(['purchase_date', 'acquisition_date', 'operation_date'] as $dateField)
                                <div class="col-xl-3 col-lg-6" data-layout-row="basic-dates">
                                    @if($dateField === 'purchase_date')
                                        <x-forms.label :for="$dateField" :label="__('fixed_assets.attributes.purchase_date')" required />
                                    @elseif($dateField === 'operation_date')
                                        <label class="form-label" for="{{ $dateField }}">
                                            {{ __("fixed_assets.attributes.{$dateField}") }}
                                            <span @class(['text-danger ms-1 js-depreciable-required-marker', 'd-none' => ! $isDepreciableSelected])>*</span>
                                        </label>
                                    @else
                                        <label class="form-label" for="{{ $dateField }}">{{ __("fixed_assets.attributes.{$dateField}") }}</label>
                                    @endif
                                    @if($isView)
                                        <x-forms.view-field :for="$dateField" :value="$formatDate($record?->{$dateField})" />
                                    @else
                                        <input class="form-control js-date-picker {{ $dateField === 'operation_date' ? 'js-fixed-asset-operation-date' : '' }}" id="{{ $dateField }}" name="{{ $dateField }}" type="text" value="{{ $dateValue($dateField) }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ __('common.placeholders.select_date') }}" autocomplete="off" dir="ltr" @required($dateField === 'purchase_date' || ($dateField === 'operation_date' && $isDepreciableSelected))>
                                    @endif
                                    <div class="invalid-feedback" data-error-for="{{ $dateField }}"></div>
                                </div>
                            @endforeach

                            <div class="col-xl-3 col-lg-6" data-layout-row="basic-dates">
                                <label class="form-label" for="serial_number">{{ __('fixed_assets.attributes.serial_number') }}</label>
                                @if($isView)
                                    <x-forms.view-field for="serial_number" :value="$value('serial_number')" />
                                @else
                                    <input class="form-control" id="serial_number" name="serial_number" value="{{ $value('serial_number') }}">
                                @endif
                                <div class="invalid-feedback" data-error-for="serial_number"></div>
                            </div>

                            <div class="col-xl-3 col-lg-6" data-layout-row="basic-accounts">
                                <x-forms.label for="asset_group_account_doc_num" :label="__('fixed_assets.attributes.asset_group_account')" required />
                                @if($isView)
                                    <x-forms.view-field for="asset_group_account_doc_num" :value="$accountOption($groupAccount)['text'] ?? null" />
                                @else
                                    <div class="fixed-asset-select-control">
                                        <select class="form-select js-select2-ajax" id="asset_group_account_doc_num" name="asset_group_account_doc_num" data-url="{{ route('admin.fixed-assets.select2.asset-categories') }}" data-placeholder="{{ __('fixed_assets.placeholders.asset_category') }}" data-allow-clear="true" required>
                                            @if($accountOption($groupAccount))
                                                <option value="{{ $accountOption($groupAccount)['id'] }}" selected>{{ $accountOption($groupAccount)['text'] }}</option>
                                            @endif
                                        </select>
                                        @if($canCreateAccounts)
                                            <button class="btn btn-falcon-default btn-sm js-fixed-asset-inline-create" type="button" data-modal="#fixed-asset-category-modal">
                                                <span class="fas fa-plus"></span><span class="ms-1">{{ __('fixed_assets.actions.add_category') }}</span>
                                            </button>
                                        @endif
                                    </div>
                                @endif
                                <div class="invalid-feedback d-block" data-error-for="asset_group_account_doc_num"></div>
                            </div>

                            <div class="col-xl-3 col-lg-6" data-layout-row="basic-accounts">
                                <x-forms.label for="credit_account_doc_num" :label="__('fixed_assets.attributes.credit_account')" required />
                                @if($isView)
                                    <x-forms.view-field for="credit_account_doc_num" :value="$accountOption($record?->creditAccount)['text'] ?? null" />
                                @else
                                    <select class="form-select js-select2-ajax" id="credit_account_doc_num" name="credit_account_doc_num" data-url="{{ route('admin.fixed-assets.select2.credit-accounts') }}" data-placeholder="{{ __('fixed_assets.placeholders.credit_account') }}" data-allow-clear="true" required>
                                        @if($accountOption($record?->creditAccount))
                                            <option value="{{ $accountOption($record?->creditAccount)['id'] }}" selected>{{ $accountOption($record?->creditAccount)['text'] }}</option>
                                        @endif
                                    </select>
                                @endif
                                <div class="invalid-feedback d-block" data-error-for="credit_account_doc_num"></div>
                            </div>

                            <div class="col-xl-3 col-lg-6" data-layout-row="basic-accounts">
                                <label class="form-label" for="cost_center_doc_num">{{ __('fixed_assets.attributes.cost_center') }}</label>
                                @if($isView)
                                    <x-forms.view-field for="cost_center_doc_num" :value="$costCenterOption['text'] ?? null" />
                                @else
                                    <select class="form-select js-select2-ajax" id="cost_center_doc_num" name="cost_center_doc_num" data-url="{{ route('admin.fixed-assets.select2.cost-centers') }}" data-placeholder="{{ __('fixed_assets.placeholders.cost_center') }}" data-allow-clear="true">
                                        @if($costCenterOption)<option value="{{ $costCenterOption['id'] }}" selected>{{ $costCenterOption['text'] }}</option>@endif
                                    </select>
                                @endif
                                <div class="invalid-feedback d-block" data-error-for="cost_center_doc_num"></div>
                            </div>

                            <div class="col-xl-3 col-lg-6" data-layout-row="basic-status">
                                <x-forms.label for="status" :label="__('fixed_assets.attributes.status')" required />
                                @if($isView)
                                    <x-forms.view-field for="status" :value="__('fixed_assets.statuses.'.($record?->status ?? 'active'))" />
                                @else
                                    <select class="form-select" id="status" name="status" required>
                                        <option value="active" @selected($value('status', 'active') === 'active')>{{ __('fixed_assets.statuses.active') }}</option>
                                        <option value="inactive" @selected($value('status') === 'inactive')>{{ __('fixed_assets.statuses.inactive') }}</option>
                                    </select>
                                @endif
                                <div class="invalid-feedback" data-error-for="status"></div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="fixed-asset-financial-tab" role="tabpanel">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <x-forms.label for="purchase_value" :label="__('fixed_assets.attributes.purchase_value')" required />
                                @if($isView)
                                    <x-forms.view-field for="purchase_value" :value="$numbers->format($numericValue('purchase_value'))" input-class="text-center" />
                                @else
                                    <x-forms.numeric-input class="text-center js-fixed-asset-money" id="purchase_value" name="purchase_value" :value="$numericValue('purchase_value')" :scale="4" min="0.0001" step="0.0001" required />
                                @endif
                                <div class="invalid-feedback" data-error-for="purchase_value"></div>
                            </div>

                            <div class="col-md-4">
                                <x-forms.label for="currency_doc_num" :label="__('fixed_assets.attributes.currency')" required />
                                @if($isView)
                                    <x-forms.view-field for="currency_doc_num" :value="$currencyOption['text'] ?? null" />
                                @else
                                    <select class="form-select js-select2-ajax js-fixed-asset-currency" id="currency_doc_num" name="currency_doc_num" data-url="{{ route('admin.fixed-assets.select2.currencies') }}" data-placeholder="{{ __('fixed_assets.placeholders.currency') }}" data-allow-clear="true" required>
                                        @if($currencyOption)<option value="{{ $currencyOption['id'] }}" @if(($currencyOption['id'] ?? null) === ($defaults['main_currency_doc_num'] ?? null)) data-is-main="1" @endif selected>{{ $currencyOption['text'] }}</option>@endif
                                    </select>
                                @endif
                                <div class="invalid-feedback d-block" data-error-for="currency_doc_num"></div>
                            </div>

                            <div class="col-md-4">
                                <x-forms.label for="exchange_rate" :label="__('fixed_assets.attributes.exchange_rate')" required />
                                @if($isView)
                                    <x-forms.view-field for="exchange_rate" :value="$numbers->format($numericValue('exchange_rate', $defaults['exchange_rate'] ?? ''))" input-class="text-center" />
                                @else
                                    <x-forms.numeric-input class="text-center js-fixed-asset-exchange-rate" id="exchange_rate" name="exchange_rate" :value="$numericValue('exchange_rate', $defaults['exchange_rate'] ?? '')" :scale="6" min="0.000001" step="0.000001" required />
                                @endif
                                <div class="invalid-feedback" data-error-for="exchange_rate"></div>
                            </div>

                            <div class="col-md-4">
                                <x-forms.label for="is_depreciable" :label="__('fixed_assets.attributes.is_depreciable')" required />
                                @if($isView)
                                    <x-forms.view-field for="is_depreciable" :value="__('fixed_assets.booleans.'.(($record?->is_depreciable ?? true) ? 'yes' : 'no'))" />
                                @else
                                    <select class="form-select js-fixed-asset-is-depreciable" id="is_depreciable" name="is_depreciable" required>
                                        <option value="1" @selected($isDepreciableSelected)>{{ __('fixed_assets.booleans.yes') }}</option>
                                        <option value="0" @selected(! $isDepreciableSelected)>{{ __('fixed_assets.booleans.no') }}</option>
                                    </select>
                                @endif
                                <div class="invalid-feedback" data-error-for="is_depreciable"></div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label" for="depreciation_method">
                                    {{ __('fixed_assets.attributes.depreciation_method') }}
                                    <span @class(['text-danger ms-1 js-depreciable-required-marker', 'd-none' => ! $isDepreciableSelected])>*</span>
                                </label>
                                @if($isView)
                                    <x-forms.view-field for="depreciation_method" :value="$record?->depreciationMethodLabel()" />
                                @else
                                    <select class="form-select js-fixed-asset-depreciation-control js-fixed-asset-depreciation-method" id="depreciation_method" name="depreciation_method" data-default-method="{{ $fixedAssetClass::DepreciationMethodStraightLine }}" @required($isDepreciableSelected) @disabled(! $isDepreciableSelected)>
                                        @foreach($fixedAssetClass::depreciationMethods() as $method)
                                            <option value="{{ $method }}" @selected($depreciationMethodValue === $method)>{{ __("fixed_assets.depreciation_methods.{$method}") }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <div class="invalid-feedback" data-error-for="depreciation_method"></div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label" for="salvage_value">
                                    {{ __('fixed_assets.attributes.salvage_value') }}
                                    <span @class(['text-danger ms-1 js-depreciable-required-marker', 'd-none' => ! $isDepreciableSelected])>*</span>
                                </label>
                                @if($isView)
                                    <x-forms.view-field for="salvage_value" :value="$numbers->format($numericValue('salvage_value', 0))" input-class="text-center" />
                                @else
                                    <x-forms.numeric-input class="text-center js-fixed-asset-money js-fixed-asset-depreciation-control js-fixed-asset-salvage-value" id="salvage_value" name="salvage_value" :value="$numericValue('salvage_value', 0)" :scale="4" min="0" step="0.0001" :required="$isDepreciableSelected" :disabled="! $isDepreciableSelected" />
                                @endif
                                <div class="invalid-feedback" data-error-for="salvage_value"></div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label" for="previous_depreciation">
                                    {{ __('fixed_assets.attributes.previous_depreciation') }}
                                    <span @class(['text-danger ms-1 js-opening-asset-required-marker', 'd-none' => ! $isDepreciableSelected || $entryTypeValue !== $fixedAssetClass::EntryTypeOpeningAsset])>*</span>
                                </label>
                                @if($isView)
                                    <x-forms.view-field for="previous_depreciation" :value="$numbers->format($numericValue('previous_depreciation'))" input-class="text-center" />
                                @else
                                    <x-forms.numeric-input class="text-center js-fixed-asset-money js-fixed-asset-depreciation-control js-fixed-asset-previous-depreciation" id="previous_depreciation" name="previous_depreciation" :value="$numericValue('previous_depreciation')" :scale="4" min="0" step="0.0001" :required="$isDepreciableSelected && $entryTypeValue === $fixedAssetClass::EntryTypeOpeningAsset" :disabled="! $isDepreciableSelected" />
                                @endif
                                <div class="invalid-feedback" data-error-for="previous_depreciation"></div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label" for="previous_depreciation_until_date">
                                    {{ __('fixed_assets.attributes.previous_depreciation_until_date') }}
                                    <span @class(['text-danger ms-1 js-previous-depreciation-date-required-marker', 'd-none' => ! $isDepreciableSelected || ! $hasPreviousDepreciation])>*</span>
                                </label>
                                @if($isView)
                                    <x-forms.view-field for="previous_depreciation_until_date" :value="$formatDate($record?->previous_depreciation_until_date)" />
                                @else
                                    <input class="form-control js-date-picker js-fixed-asset-depreciation-control js-fixed-asset-previous-depreciation-until-date" id="previous_depreciation_until_date" name="previous_depreciation_until_date" type="text" value="{{ $dateValue('previous_depreciation_until_date') }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ __('common.placeholders.select_date') }}" autocomplete="off" dir="ltr" @required($isDepreciableSelected && $hasPreviousDepreciation) @disabled(! $isDepreciableSelected)>
                                @endif
                                <div class="invalid-feedback" data-error-for="previous_depreciation_until_date"></div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label" for="net_value">{{ __('fixed_assets.attributes.net_value') }}</label>
                                <input class="text-center form-control js-fixed-asset-net-value" id="net_value" type="text" value="{{ $numbers->format($numericValue('net_value')) }}" dir="ltr" readonly>
                            </div>

                            @if($isView)
                                <div class="col-md-4">
                                    <x-forms.view-field for="depreciation_start_date" :label="__('fixed_assets.attributes.depreciation_start_date')" :value="$formatDate($record?->depreciation_start_date)" />
                                </div>
                            @endif

                            <div @class([
                                'col-md-4 js-fixed-asset-method-field-container',
                                'fixed-asset-hidden-field' => ! $isDepreciableSelected || ! in_array($depreciationMethodValue, [$fixedAssetClass::DepreciationMethodStraightLine, $fixedAssetClass::DepreciationMethodDoubleDecliningBalance, $fixedAssetClass::DepreciationMethodSumOfYearsDigits], true),
                            ]) data-depreciation-methods="{{ implode(',', [$fixedAssetClass::DepreciationMethodStraightLine, $fixedAssetClass::DepreciationMethodDoubleDecliningBalance, $fixedAssetClass::DepreciationMethodSumOfYearsDigits]) }}">
                                <label class="form-label" for="useful_life">
                                    {{ __('fixed_assets.attributes.useful_life') }}
                                    <span @class(['text-danger ms-1 js-fixed-asset-useful-life-required-marker', 'd-none' => ! $requiresUsefulLife])>*</span>
                                </label>
                                @if($isView)
                                    <x-forms.view-field for="useful_life" :value="$usefulLifeValue" input-class="text-center" />
                                @else
                                    <x-forms.numeric-input class="text-center js-fixed-asset-useful-life js-fixed-asset-depreciation-control js-fixed-asset-method-control" id="useful_life" name="useful_life" :value="$usefulLifeValue" :scale="2" min="0.01" step="0.01" :required="$requiresUsefulLife" :disabled="! $isDepreciableSelected || ! in_array($depreciationMethodValue, [$fixedAssetClass::DepreciationMethodStraightLine, $fixedAssetClass::DepreciationMethodDoubleDecliningBalance, $fixedAssetClass::DepreciationMethodSumOfYearsDigits], true)" />
                                @endif
                                <div class="invalid-feedback" data-error-for="useful_life"></div>
                            </div>

                            <div @class([
                                'col-md-4 js-fixed-asset-method-field-container',
                                'fixed-asset-hidden-field' => ! $isDepreciableSelected || ! in_array($depreciationMethodValue, [$fixedAssetClass::DepreciationMethodStraightLine, $fixedAssetClass::DepreciationMethodDecliningBalance], true),
                            ]) data-depreciation-methods="{{ implode(',', [$fixedAssetClass::DepreciationMethodStraightLine, $fixedAssetClass::DepreciationMethodDecliningBalance]) }}">
                                <label class="form-label" for="annual_depreciation_rate">
                                    {{ __('fixed_assets.attributes.annual_depreciation_rate') }}
                                    <span @class(['text-danger ms-1 js-fixed-asset-annual-rate-required-marker', 'd-none' => ! $requiresAnnualDepreciationRate])>*</span>
                                </label>
                                @if($isView)
                                    <x-forms.view-field for="annual_depreciation_rate" :value="$annualDepreciationRateValue" input-class="text-center" />
                                @else
                                    <x-forms.numeric-input class="text-center js-fixed-asset-depreciation-rate js-fixed-asset-depreciation-control js-fixed-asset-method-control" id="annual_depreciation_rate" name="annual_depreciation_rate" :value="$annualDepreciationRateValue" :scale="4" min="0.0001" max="100" step="0.0001" :required="$requiresAnnualDepreciationRate" :disabled="! $isDepreciableSelected || ! in_array($depreciationMethodValue, [$fixedAssetClass::DepreciationMethodStraightLine, $fixedAssetClass::DepreciationMethodDecliningBalance], true)" />
                                @endif
                                <div class="invalid-feedback" data-error-for="annual_depreciation_rate"></div>
                            </div>

                            <div @class([
                                'col-md-4 js-fixed-asset-method-field-container',
                                'fixed-asset-hidden-field' => ! $isDepreciableSelected || $depreciationMethodValue !== $fixedAssetClass::DepreciationMethodUnitsOfProduction,
                            ]) data-depreciation-methods="{{ $fixedAssetClass::DepreciationMethodUnitsOfProduction }}">
                                <label class="form-label" for="expected_usage_units">
                                    {{ __('fixed_assets.attributes.expected_usage_units') }}
                                    <span @class(['text-danger ms-1 js-fixed-asset-usage-units-required-marker', 'd-none' => ! $requiresExpectedUsageUnits])>*</span>
                                </label>
                                @if($isView)
                                    <x-forms.view-field for="expected_usage_units" :value="$expectedUsageUnitsValue" input-class="text-center" />
                                @else
                                    <x-forms.numeric-input class="text-center js-fixed-asset-usage-units js-fixed-asset-depreciation-control js-fixed-asset-method-control" id="expected_usage_units" name="expected_usage_units" :value="$expectedUsageUnitsValue" :scale="4" min="0.0001" step="0.0001" :required="$requiresExpectedUsageUnits" :disabled="! $isDepreciableSelected || $depreciationMethodValue !== $fixedAssetClass::DepreciationMethodUnitsOfProduction" />
                                @endif
                                <div class="invalid-feedback" data-error-for="expected_usage_units"></div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="fixed-asset-location-tab" role="tabpanel">
                        <div class="row g-3">
                            <div class="col-lg-6">
                                <x-forms.label for="branch_doc_num" :label="__('fixed_assets.attributes.branch')" required />
                                @if($isView)
                                    <x-forms.view-field for="branch_doc_num" :value="$branchOption['text'] ?? null" />
                                @else
                                    <select class="form-select js-select2-ajax js-fixed-asset-branch" id="branch_doc_num" name="branch_doc_num" data-url="{{ route('admin.fixed-assets.select2.branches') }}" data-placeholder="{{ __('fixed_assets.placeholders.branch') }}" data-allow-clear="true" required>
                                        @if($branchOption)<option value="{{ $branchOption['id'] }}" selected>{{ $branchOption['text'] }}</option>@endif
                                    </select>
                                @endif
                                <div class="invalid-feedback d-block" data-error-for="branch_doc_num"></div>
                            </div>

                            <div class="col-lg-6">
                                <label class="form-label" for="branch_hall_uuid">{{ __('fixed_assets.attributes.hall') }}</label>
                                @if($isView)
                                    <x-forms.view-field for="branch_hall_uuid" :value="$hallOption['text'] ?? null" />
                                @else
                                    <select class="form-select js-select2-ajax js-fixed-asset-branch-hall" id="branch_hall_uuid" name="branch_hall_uuid" data-url="{{ route('admin.fixed-assets.select2.branch-halls') }}" data-placeholder="{{ __('fixed_assets.placeholders.hall') }}" data-allow-clear="true" data-depends-on="#branch_doc_num" data-dependent-param="branch_doc_num" data-disable-when-dependency-empty="true" @disabled(! $branchOption)>
                                        @if($hallOption)<option value="{{ $hallOption['id'] }}" selected>{{ $hallOption['text'] }}</option>@endif
                                    </select>
                                @endif
                                <div class="invalid-feedback d-block" data-error-for="branch_hall_uuid"></div>
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="location_address">{{ __('fixed_assets.attributes.location_address') }}</label>
                                @if($isView)
                                    <x-forms.view-field for="location_address" as="textarea" :value="$value('location_address')" rows="2" />
                                @else
                                    <textarea class="form-control" id="location_address" name="location_address" rows="2">{{ $value('location_address') }}</textarea>
                                @endif
                                <div class="invalid-feedback" data-error-for="location_address"></div>
                            </div>

                            <div class="col-12">
                                <x-forms.label for="description" :label="__('fixed_assets.attributes.description')" required />
                                @if($isView)
                                    <x-forms.view-field for="description" as="textarea" :value="$value('description')" rows="3" />
                                @else
                                    <textarea class="form-control" id="description" name="description" rows="3" required>{{ $value('description') }}</textarea>
                                @endif
                                <div class="invalid-feedback" data-error-for="description"></div>
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="notes">{{ __('fixed_assets.attributes.notes') }}</label>
                                @if($isView)
                                    <x-forms.view-field for="notes" as="textarea" :value="$value('notes')" rows="3" />
                                @else
                                    <textarea class="form-control" id="notes" name="notes" rows="3">{{ $value('notes') }}</textarea>
                                @endif
                                <div class="invalid-feedback" data-error-for="notes"></div>
                            </div>
                        </div>
                    </div>
                </div>

                @if($mode === 'edit' || $isView)
                    <x-audit-fields-row
                        :metadata="$metadata"
                        :show-deleted="$isView && ($record?->trashed() ?? false)"
                        :show-restored="$isView && ! ($record?->trashed() ?? false) && (($record?->restored_at ?? null) || ($record?->restored_by ?? null))"
                    />
                @endif
            </div>
            <div class="card-footer">@include('modules.finance.partials.form-actions', ['resource' => 'fixed_assets', 'routePrefix' => 'admin.fixed-assets.assets'])</div>
        </div>
    </form>

    @unless($isView)
        <x-file-picker-modal />

        <div class="modal fade" id="fixed-asset-category-modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form class="modal-content js-fixed-asset-inline-form" action="{{ route('admin.fixed-assets.assets.asset-categories.store') }}" method="POST" data-target-select="#asset_group_account_doc_num" novalidate>
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('fixed_assets.actions.add_category') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('common.actions.close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert d-none js-form-alert"><div class="js-form-alert-message"></div></div>
                        <div class="mb-3">
                            <x-forms.label for="fixed_asset_category_name" :label="__('fixed_assets.attributes.asset_category_name')" required />
                            <input class="form-control" id="fixed_asset_category_name" name="name" type="text" required>
                            <div class="invalid-feedback" data-error-for="name"></div>
                        </div>
                        <div>
                            <label class="form-label" for="fixed_asset_category_notes">{{ __('fixed_assets.attributes.notes') }}</label>
                            <textarea class="form-control" id="fixed_asset_category_notes" name="notes" rows="3"></textarea>
                            <div class="invalid-feedback" data-error-for="notes"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-falcon-default" data-bs-dismiss="modal">{{ __('common.actions.cancel') }}</button>
                        <button type="submit" class="btn btn-primary"><span class="fas fa-save me-1"></span>{{ __('common.actions.save') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endunless
@endsection

@push('scripts')
    <script>
        window.fixedAssetsMessages = @json(__('fixed_assets.js'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('vendors/select2/select2.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Core/file-picker.js') }}"></script>
    <script src="{{ asset('assets/js/modules/FixedAssets/fixed-assets.js') }}"></script>
@endpush

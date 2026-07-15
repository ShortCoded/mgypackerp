@extends('layouts.app')

@php
    use Illuminate\Support\Facades\Storage;
    use Modules\Core\Services\DateFormatService;

    $isView = $mode === 'view';
    $isEdit = $mode === 'edit';
    $isClone = $mode === 'clone';
    $title = match ($mode) {
        'edit' => __('companies.edit'),
        'view' => __('companies.view'),
        'clone' => __('companies.titles.clone'),
        default => __('companies.create'),
    };
    $dateFormatService = app(DateFormatService::class);
    $companyName = $isClone && $company ? __('companies.defaults.clone_name', ['name' => $company->name]) : $company?->name;
    $documentNumberValue = old('doc_number', ($isEdit || $isView) ? $company?->doc_number : '');
    $fieldValue = fn (string $field, mixed $default = '') => old($field, $isClone && in_array($field, ['doc_number', 'doc_num', 'logo', 'favicon'], true) ? $default : ($company?->{$field} ?? $default));
    $dateValue = fn (string $field) => old($field, $company?->{$field} ? $dateFormatService->formatDate($company->{$field}, '') : '');
    $statusValue = old('status', $company?->status ?? 'active');
    $isMainValue = (bool) old('is_main', $company?->is_main ?? false);
    $logoPath = $company && ! $isClone && $company->logo ? Storage::disk('public')->url($company->logo) : null;
    $faviconPath = $company && ! $isClone && $company->favicon ? Storage::disk('public')->url($company->favicon) : null;
    $locationSelectedUrl = $company && ! $isView ? route('admin.companies.location-selected', $company->doc_num) : null;
    $relationDocNum = fn (string $relation) => $company ? ($company->{$relation}()->value('doc_num') ?: '') : '';
    $relationName = fn (string $relation, string $legacyField) => $company ? ($company->{$relation}()->value('name') ?: $fieldValue($legacyField, '')) : '';
    $locationValues = [
        'country_doc_num' => old('country_doc_num', $company && ($isEdit || $isClone) ? $relationDocNum('country') : ''),
        'governorate_doc_num' => old('governorate_doc_num', $company && ($isEdit || $isClone) ? $relationDocNum('governorate') : ''),
        'city_doc_num' => old('city_doc_num', $company && ($isEdit || $isClone) ? $relationDocNum('city') : ''),
        'area_doc_num' => old('area_doc_num', $company && ($isEdit || $isClone) ? $relationDocNum('area') : ''),
    ];
    $locationNames = [
        'country' => $relationName('country', 'country'),
        'governorate' => $relationName('governorate', 'governorate'),
        'city' => $relationName('city', 'city'),
        'area' => $relationName('area', 'area'),
    ];
    $showsDocumentNumberColumn = $canControlDocumentNumber || (($isEdit || $isView) && ! $canControlDocumentNumber);
    $nameColumnClass = $showsDocumentNumberColumn ? 'col-md-6 col-xl-7' : 'col-md-8 col-xl-9';
    $statusColumnClass = $showsDocumentNumberColumn ? 'col-md-3 col-xl-3' : 'col-md-4 col-xl-3';
    $originalCompanyData = [
        'name' => $companyName,
        'doc_number' => $canControlDocumentNumber ? (($isEdit || $isView) ? $company?->doc_number : '') : null,
        'legal_name' => $fieldValue('legal_name'),
        'commercial_name' => $fieldValue('commercial_name'),
        'status' => $statusValue,
        'is_main' => $canControlMainCompany ? $isMainValue : null,
        'notes' => $fieldValue('notes'),
        'commercial_register_number' => $fieldValue('commercial_register_number'),
        'commercial_register_office' => $fieldValue('commercial_register_office'),
        'commercial_register_date' => $dateValue('commercial_register_date'),
        'commercial_register_expiry_date' => $dateValue('commercial_register_expiry_date'),
        'tax_card_number' => $fieldValue('tax_card_number'),
        'tax_file_number' => $fieldValue('tax_file_number'),
        'tax_office' => $fieldValue('tax_office'),
        'vat_registration_number' => $fieldValue('vat_registration_number'),
        'industrial_register_number' => $fieldValue('industrial_register_number'),
        'import_card_number' => $fieldValue('import_card_number'),
        'export_card_number' => $fieldValue('export_card_number'),
        'phone' => $fieldValue('phone'),
        'mobile' => $fieldValue('mobile'),
        'hotline' => $fieldValue('hotline'),
        'fax' => $fieldValue('fax'),
        'email' => $fieldValue('email'),
        'website' => $fieldValue('website'),
        'country_doc_num' => $locationValues['country_doc_num'],
        'governorate_doc_num' => $locationValues['governorate_doc_num'],
        'city_doc_num' => $locationValues['city_doc_num'],
        'area_doc_num' => $locationValues['area_doc_num'],
        'address' => $fieldValue('address'),
        'postal_code' => $fieldValue('postal_code'),
        'map_url' => $fieldValue('map_url'),
        'industry' => $fieldValue('industry'),
        'activity_type' => $fieldValue('activity_type'),
        'business_description' => $fieldValue('business_description'),
    ];
@endphp

@section('title', $title)

@section('content')
    <form id="company-form" class="js-company-form" action="{{ $action }}" method="POST" enctype="multipart/form-data" data-mode="{{ $mode }}" data-original='@json($originalCompanyData)' novalidate>
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif
        <input type="hidden" name="submit_action" value="save">
        @if ($isClone && $cloneSourceToken)
            <input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}">
        @endif

        <div class="card mb-3">
            @include('modules.core.companies.partials.form-header')
            <div class="card-body js-company-form-body">
                <div class="alert alert-danger alert-dismissible fade show d-none js-company-alert" role="alert">
                    <span class="js-company-alert-message"></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('common.actions.close') }}"></button>
                </div>

                <h6 class="mb-3 text-700">{{ __('companies.sections.basic_information') }}</h6>
                <div class="row g-3 align-items-start">
                    @if ($canControlDocumentNumber)
                        <div class="col-md-3 col-lg-2">
                            <label class="form-label" for="company-doc-number">{{ __('common.fields.document_number') }}</label>
                            @if ($isView)
                                <x-forms.view-field for="company-doc-number" as="display" :value="$documentNumberValue" input-class="text-center js-company-doc-number" />
                            @else
                                <input id="company-doc-number" name="doc_number" type="number" min="0" step="1" inputmode="numeric" class="text-center form-control js-company-doc-number" value="{{ $documentNumberValue }}" placeholder="{{ __('companies.document_number_control.placeholder') }}">
                            @endif
                            <div class="form-text">{{ __('companies.document_number_control.helper') }}</div>
                            <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                        </div>
                    @elseif ($isEdit || $isView)
                        <div class="col-md-3 col-lg-2">
                            <x-forms.view-field
                                for="company-doc-number-display"
                                as="display"
                                :label="__('common.fields.doc_number')"
                                :value="$company?->doc_number"
                                input-class="text-center"
                            />
                        </div>
                    @endif

                    <div class="{{ $nameColumnClass }}">
                        <x-forms.label for="company-name" :label="__('common.fields.name')" required />
                        @if ($isView)
                            <x-forms.view-field for="company-name" :value="old('name', $companyName)" />
                        @else
                            <input id="company-name" autofocus name="name" type="text" class="form-control" value="{{ old('name', $companyName) }}" required>
                        @endif
                        <div class="invalid-feedback" data-error-for="name"></div>

                        @if ($canControlMainCompany && ! $isView)
                            <input type="hidden" name="is_main" value="0">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" id="company-is-main" name="is_main" type="checkbox" value="1" @checked($isMainValue)>
                                <label class="form-check-label" for="company-is-main">{{ __('companies.fields.main_company') }}</label>
                            </div>
                            <div class="invalid-feedback d-block" data-error-for="is_main"></div>
                        @elseif ($isView)
                            <div class="mt-2">
                                {{-- <span class="me-2 text-600">{{ __('companies.fields.main_company') }}</span> --}}
                                @include('modules.core.companies.partials.main-badge', ['company' => $company ?? new \Modules\Core\Models\Company(['is_main' => $isMainValue])])
                            </div>
                        @endif
                    </div>

                    <div class="{{ $statusColumnClass }}">
                        @if ($isView)
                            <x-forms.view-field
                                for="company-status"
                                :label="__('common.fields.status')"
                                :value="$statusValue ? __('companies.statuses.'.$statusValue) : null"
                                required
                            />
                        @else
                            <x-forms.label for="company-status" :label="__('common.fields.status')" required />
                            <select id="company-status" name="status" class="form-select" required>
                                @foreach (['active', 'inactive'] as $status)
                                    <option value="{{ $status }}" @selected($statusValue === $status)>{{ __("companies.statuses.{$status}") }}</option>
                                @endforeach
                            </select>
                        @endif
                        <div class="invalid-feedback" data-error-for="status"></div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="company-legal-name">{{ __('companies.fields.legal_name') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="company-legal-name" :value="$fieldValue('legal_name')" />
                        @else
                            <input id="company-legal-name" name="legal_name" type="text" class="form-control" value="{{ $fieldValue('legal_name') }}">
                        @endif
                        <div class="invalid-feedback" data-error-for="legal_name"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="company-commercial-name">{{ __('companies.fields.commercial_name') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="company-commercial-name" :value="$fieldValue('commercial_name')" />
                        @else
                            <input id="company-commercial-name" name="commercial_name" type="text" class="form-control" value="{{ $fieldValue('commercial_name') }}">
                        @endif
                        <div class="invalid-feedback" data-error-for="commercial_name"></div>
                    </div>
                    <div class="col-lg-8">
                        <label class="form-label" for="company-logo">{{ __('companies.fields.logo') }}</label>
                        @include('modules.core.companies.partials.logo-uploader', [
                            'inputId' => 'company-logo',
                            'inputName' => 'logo',
                            'currentUrl' => $logoPath,
                            'disabled' => $isView,
                        ])
                        @if ($logoPath && ! $isView)
                            <div class="form-check mt-2">
                                <input class="form-check-input" id="company-remove-logo" name="remove_logo" type="checkbox" value="1">
                                <label class="form-check-label" for="company-remove-logo">{{ __('companies.fields.remove_logo') }}</label>
                            </div>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="logo"></div>
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label" for="company-favicon">{{ __('companies.fields.favicon') }}</label>
                        @include('modules.core.companies.partials.logo-uploader', [
                            'inputId' => 'company-favicon',
                            'inputName' => 'favicon',
                            'currentUrl' => $faviconPath,
                            'disabled' => $isView,
                            'acceptedExtensions' => config('archive.favicon.allowed_extensions', ['ico', 'png', 'jpg', 'jpeg', 'webp']),
                            'maxFileSize' => (int) config('archive.favicon.max_file_size_mib', 1),
                            'existingLabel' => __('companies.favicon.existing_file'),
                            'emptyLabel' => __('companies.favicon.no_file_selected'),
                            'uploadLabel' => __('companies.fields.upload_favicon'),
                            'altLabel' => __('companies.fields.company_favicon'),
                            'helpText' => __('companies.favicon.help'),
                        ])
                        @if ($faviconPath && ! $isView)
                            <div class="form-check mt-2">
                                <input class="form-check-input" id="company-remove-favicon" name="remove_favicon" type="checkbox" value="1">
                                <label class="form-check-label" for="company-remove-favicon">{{ __('companies.fields.remove_favicon') }}</label>
                            </div>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="favicon"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h6 class="mb-3 text-700">{{ __('companies.sections.legal_tax_information') }}</h6>
                <div class="row g-3">
                    @foreach ([
                        ['commercial_register_number', 'text', 'col-md-6 col-xl-3'],
                        ['commercial_register_office', 'text', 'col-md-6 col-xl-3'],
                        ['commercial_register_date', 'date', 'col-md-6 col-xl-3'],
                        ['commercial_register_expiry_date', 'date', 'col-md-6 col-xl-3'],
                        ['tax_card_number', 'text', 'col-md-6 col-xl-3'],
                        ['tax_file_number', 'text', 'col-md-6 col-xl-3'],
                        ['tax_office', 'text', 'col-md-6 col-xl-3'],
                        ['vat_registration_number', 'text', 'col-md-6 col-xl-3'],
                        ['industrial_register_number', 'text', 'col-md-4'],
                        ['import_card_number', 'text', 'col-md-4'],
                        ['export_card_number', 'text', 'col-md-4'],
                    ] as [$field, $type, $columnClass])
                        <div class="{{ $columnClass }}">
                            @if ($isView && $type === 'date')
                                <x-forms.view-field
                                    :for="'company-'.str_replace('_', '-', $field)"
                                    :label="__('companies.fields.'.$field)"
                                    :value="$dateValue($field)"
                                    dir="ltr"
                                    input-class="date-value"
                                    :error-for="$field"
                                />
                            @elseif ($isView)
                                <x-forms.view-field
                                    :for="'company-'.str_replace('_', '-', $field)"
                                    :label="__('companies.fields.'.$field)"
                                    :value="$fieldValue($field)"
                                    :error-for="$field"
                                />
                            @else
                                <label class="form-label" for="company-{{ str_replace('_', '-', $field) }}">{{ __("companies.fields.{$field}") }}</label>
                                <input id="company-{{ str_replace('_', '-', $field) }}"
                                       name="{{ $field }}"
                                       type="{{ $type === 'date' ? 'text' : $type }}"
                                       class="form-control {{ $type === 'date' ? 'js-date-picker' : '' }}"
                                       value="{{ $type === 'date' ? $dateValue($field) : $fieldValue($field) }}"
                                       @if ($type === 'date')
                                           data-date-format="{{ $dateFormatService->jsDateFormat() }}"
                                           data-locale="{{ app()->getLocale() }}"
                                           placeholder="{{ __('common.placeholders.select_date') }}"
                                           autocomplete="off"
                                           dir="ltr"
                                       @endif>
                                <div class="invalid-feedback" data-error-for="{{ $field }}"></div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h6 class="mb-3 text-700">{{ __('companies.sections.contact_information') }}</h6>
                <div class="row g-3">
                    @foreach ([
                        ['phone', __('common.fields.phone'), 'tel', 'col-md-4'],
                        ['mobile', __('companies.fields.mobile'), 'tel', 'col-md-4'],
                        ['hotline', __('companies.fields.hotline'), 'tel', 'col-md-4'],
                        ['fax', __('companies.fields.fax'), 'text', 'col-md-4'],
                        ['email', __('common.fields.email'), 'email', 'col-md-4'],
                        ['website', __('companies.fields.website'), 'url', 'col-md-4'],
                    ] as [$field, $label, $type, $columnClass])
                        @php
                            $value = $fieldValue($field);
                        @endphp
                        <div class="{{ $columnClass }}">
                            @if ($isView && in_array($field, ['phone', 'mobile', 'hotline'], true) && filled($value))
                                <x-forms.view-field :for="'company-'.$field" :label="$label" as="display" :error-for="$field">
                                    <x-contact.phone-actions :phone="$value" />
                                </x-forms.view-field>
                            @elseif ($isView && $field === 'email' && filled($value))
                                <x-forms.view-field :for="'company-'.$field" :label="$label" as="display" :error-for="$field">
                                    <x-contact.email-link :email="$value" />
                                </x-forms.view-field>
                            @elseif ($isView && $field === 'website')
                                <x-forms.view-field :for="'company-'.$field" :label="$label" :value="$value" link :error-for="$field" />
                            @elseif ($isView)
                                <x-forms.view-field :for="'company-'.$field" :label="$label" :value="$value" :error-for="$field" />
                            @else
                                <label class="form-label" for="company-{{ $field }}">{{ $label }}</label>
                                <input id="company-{{ $field }}" name="{{ $field }}" type="{{ $type }}" class="form-control" value="{{ $value }}">
                                <div class="invalid-feedback" data-error-for="{{ $field }}"></div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h6 class="mb-3 text-700">{{ __('companies.sections.address') }}</h6>
                <div class="row g-3">
                    @foreach ([
                        ['country_doc_num', 'country', 'countries', 'country'],
                        ['governorate_doc_num', 'governorate', 'governorates', 'governorate'],
                        ['city_doc_num', 'city', 'cities', 'city'],
                        ['area_doc_num', 'area', 'areas', 'area'],
                    ] as [$field, $labelKey, $routeKey, $selectedKey])
                        <div class="col-md-6 col-xl-3">
                            <label class="form-label" for="company-{{ str_replace('_', '-', $field) }}">{{ __("companies.fields.{$labelKey}") }}</label>
                            @if ($isView)
                                <x-forms.view-field :for="'company-'.str_replace('_', '-', $field)" :value="$locationNames[$labelKey]" />
                            @else
                                <select id="company-{{ str_replace('_', '-', $field) }}"
                                        name="{{ $field }}"
                                        class="form-select js-select2-ajax js-company-location-select"
                                        data-url="{{ route("admin.select2.{$routeKey}") }}"
                                        @if ($locationSelectedUrl)
                                            data-selected-url="{{ $locationSelectedUrl }}"
                                            data-selected-key="{{ $selectedKey }}"
                                        @endif
                                        data-placeholder="{{ __("companies.placeholders.{$labelKey}") }}"
                                        data-allow-clear="true"></select>
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="{{ $field }}"></div>
                        </div>
                    @endforeach
                    <div class="col-lg-12">
                        <label class="form-label" for="company-address">{{ __('companies.fields.address') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="company-address" as="textarea" :value="$fieldValue('address')" rows="3" />
                        @else
                            <textarea id="company-address" name="address" class="form-control" rows="3">{{ $fieldValue('address') }}</textarea>
                        @endif
                        <div class="invalid-feedback" data-error-for="address"></div>
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label" for="company-postal-code">{{ __('companies.fields.postal_code') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="company-postal-code" :value="$fieldValue('postal_code')" />
                        @else
                            <input id="company-postal-code" name="postal_code" type="text" class="form-control" value="{{ $fieldValue('postal_code') }}">
                        @endif
                        <div class="invalid-feedback" data-error-for="postal_code"></div>
                    </div>
                    <div class="col-8">
                        <label class="form-label" for="company-map-url">{{ __('companies.fields.map_url') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="company-map-url" :value="$fieldValue('map_url')" link />
                        @else
                            <input id="company-map-url" name="map_url" type="url" class="form-control" value="{{ $fieldValue('map_url') }}">
                        @endif
                        <div class="invalid-feedback" data-error-for="map_url"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h6 class="mb-3 text-700">{{ __('companies.sections.business_activity') }}</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="company-industry">{{ __('companies.fields.industry') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="company-industry" :value="$fieldValue('industry')" />
                        @else
                            <input id="company-industry" name="industry" type="text" class="form-control" value="{{ $fieldValue('industry') }}">
                        @endif
                        <div class="invalid-feedback" data-error-for="industry"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="company-activity-type">{{ __('companies.fields.activity_type') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="company-activity-type" :value="$fieldValue('activity_type')" />
                        @else
                            <input id="company-activity-type" name="activity_type" type="text" class="form-control" value="{{ $fieldValue('activity_type') }}">
                        @endif
                        <div class="invalid-feedback" data-error-for="activity_type"></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="company-business-description">{{ __('companies.fields.business_description') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="company-business-description" as="textarea" :value="$fieldValue('business_description')" rows="4" />
                        @else
                            <textarea id="company-business-description" name="business_description" class="form-control" rows="4">{{ $fieldValue('business_description') }}</textarea>
                        @endif
                        <div class="invalid-feedback" data-error-for="business_description"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h6 class="mb-3 text-700">{{ __('companies.sections.notes') }}</h6>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label" for="company-notes">{{ __('common.fields.notes') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="company-notes" as="textarea" :value="$fieldValue('notes')" rows="4" />
                        @else
                            <textarea id="company-notes" name="notes" class="form-control" rows="4">{{ $fieldValue('notes') }}</textarea>
                        @endif
                        <div class="invalid-feedback" data-error-for="notes"></div>
                    </div>

                    @if ($isEdit || $isView)
                        <div class="col-12">
                            <x-audit-fields-row
                                class="mt-0"
                                :metadata="$metadata"
                                :show-deleted="$isView && ($company?->trashed() ?? false)"
                                :show-restored="$isView && ! ($company?->trashed() ?? false) && (($company?->restored_at ?? null) || ($company?->restored_by ?? null))"
                            />
                        </div>
                    @endif
                </div>
            </div>
            @include('modules.core.companies.partials.form-footer')
        </div>
    </form>

@endsection

@push('scripts')
    @php
        $companiesMessages = [
            'noChanges' => __('common.messages.no_changes'),
            'validationSummary' => __('common.messages.validation_failed'),
            'unexpectedError' => __('auth.ajax.unexpected_error'),
            'close' => __('common.actions.close'),
            'yes' => __('common.actions.yes'),
            'no' => __('common.actions.no'),
            'loading' => __('common.messages.loading'),
            'invalidLogoType' => __('archive.invalid_file_type'),
            'logoTooLarge' => __('archive.logo_file_too_large', ['size' => (int) config('archive.logo.max_file_size_mib', 2)]),
            'faviconTooLarge' => __('companies.validation.favicon_too_large', ['size' => (int) config('archive.favicon.max_file_size_mib', 1)]),
        ];
    @endphp
    <script>
        window.companiesMessages = @json($companiesMessages);
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Core/companies.js') }}"></script>
@endpush

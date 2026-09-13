@extends('layouts.app')

@php
    $record = $branch ?? null;
    $isView = $mode === 'view';
    $isEdit = $mode === 'edit';
    $isCreate = in_array($mode, ['create', 'clone'], true);
    $companyOption = $selectedCompanyOption ?? null;
    $title = match ($mode) {
        'edit' => __('branches.edit'),
        'view' => __('branches.view'),
        'clone' => __('branches.titles.clone'),
        default => __('branches.create'),
    };
    $fieldValue = fn (string $field, mixed $default = '') => old($field, $record?->{$field} ?? $default);
    $documentNumberValue = old('doc_number', ! $isCreate ? $record?->doc_number : '');
    $branchTypeValue = old('type', $record?->type ?? 'administrative');
    $branchStatusValue = old('status', $record?->status ?? 'active');
    $storedStationHalls = $record instanceof \Modules\Core\Models\Branch
        && $record->type === \Modules\Core\Models\Branch::TypeFactory
        ? $record->halls->map(fn ($hall): array => [
            'key' => $hall->public_uuid,
            'name' => $hall->name,
        ])->values()->all()
        : [];
    $oldStationHalls = old('station_halls', $storedStationHalls);
    $stationHallRows = is_array($oldStationHalls)
        ? array_values(array_map(fn (mixed $hall): array => is_array($hall)
            ? ['key' => $hall['key'] ?? null, 'name' => trim((string) ($hall['name'] ?? ''))]
            : ['key' => null, 'name' => trim((string) $hall)], $oldStationHalls))
        : [];
    $stationHallNames = $stationHallRows !== []
        ? array_values(array_filter(array_map(fn (array $hall): string => $hall['name'], $stationHallRows), fn (string $hall): bool => $hall !== ''))
        : [];
    $showStationHalls = $branchTypeValue === \Modules\Core\Models\Branch::TypeFactory;
    $storedBranchStores = $record instanceof \Modules\Core\Models\Branch
        ? $record->stores->map(fn ($store): array => [
            'key' => $store->public_uuid,
            'name' => $store->name,
            'classification' => $store->classification,
        ])->values()->all()
        : [];
    $oldBranchStores = old('branch_stores', $storedBranchStores);
    $branchStoreRows = is_array($oldBranchStores)
        ? array_values(array_map(fn (mixed $store): array => is_array($store)
            ? [
                'key' => $store['key'] ?? null,
                'name' => trim((string) ($store['name'] ?? '')),
                'classification' => trim((string) ($store['classification'] ?? '')),
            ]
            : ['key' => null, 'name' => trim((string) $store), 'classification' => ''], $oldBranchStores))
        : [];
    $branchStoreClassificationLabel = fn (string $classification): string => $classification === \Modules\Core\Models\BranchStore::ClassificationGeneral
        ? __('branches.branch_stores.classifications.general')
        : __("products.classifications.{$classification}");
    $branchStoreDisplays = $branchStoreRows !== []
        ? array_values(array_filter(array_map(fn (array $store): ?array => $store['name'] !== '' ? $store : null, $branchStoreRows)))
        : [];
    $originalBranchData = [
        'doc_number' => $canControlDocumentNumber ? (! $isCreate ? $record?->doc_number : '') : null,
        'company_doc_num' => $companyOption['id'] ?? '',
        'name' => $fieldValue('name'),
        'type' => $branchTypeValue,
        'address' => $fieldValue('address'),
        'attendance_latitude' => $fieldValue('attendance_latitude'),
        'attendance_longitude' => $fieldValue('attendance_longitude'),
        'attendance_radius_meters' => $fieldValue('attendance_radius_meters', 200),
        'attendance_max_accuracy_meters' => $fieldValue('attendance_max_accuracy_meters', 100),
        'attendance_location_policy' => $fieldValue('attendance_location_policy', 'warn'),
        'camera_url' => $fieldValue('camera_url'),
        'phone' => $fieldValue('phone'),
        'mobile' => $fieldValue('mobile'),
        'email' => $fieldValue('email'),
        'hotline' => $fieldValue('hotline'),
        'contact_person' => $fieldValue('contact_person'),
        'notes' => $fieldValue('notes'),
        'status' => $branchStatusValue,
        'station_halls' => $storedStationHalls,
        'branch_stores' => $storedBranchStores,
    ];
    $showsDocumentNumberColumn = $canControlDocumentNumber || (($isEdit || $isView) && ! $canControlDocumentNumber);
@endphp

@section('title', $title)

@section('content')
    <form id="branch-form" class="js-branch-form" action="{{ $action }}" method="POST" data-mode="{{ $mode }}" data-branch-type="{{ $branchTypeValue }}" data-create-url="{{ route('admin.branches.store') }}" data-original='@json($originalBranchData)' novalidate>
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif
        <x-forms.input type="hidden" name="submit_action" value="save" />
        @if (! empty($cloneSourceToken))
            <x-forms.input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}" />
        @endif

        <div class="card mb-3">
            @include('modules.core.branches.partials.form-header')

            <div class="card-body js-branch-form-body">
                <div class="alert alert-danger alert-dismissible fade show d-none js-branch-alert" role="alert">
                    <span class="js-branch-alert-message"></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('common.actions.close') }}"></button>
                </div>

                <ul class="nav nav-tabs" id="branch-form-tabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <a class="nav-link active" id="branch-basic-tab" data-bs-toggle="tab" href="#branch-basic-pane" role="tab" aria-controls="branch-basic-pane" aria-selected="true">
                            {{ __('branches.tabs.basic') }}
                        </a>
                    </li>
                    <li class="nav-item js-station-halls-tab-item @unless ($showStationHalls) d-none @endunless" role="presentation">
                        <a class="nav-link" id="branch-station-halls-tab" data-bs-toggle="tab" href="#branch-station-halls-pane" role="tab" aria-controls="branch-station-halls-pane" aria-selected="false">
                            {{ __('branches.station_halls.title') }}
                        </a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a class="nav-link" id="branch-stores-tab" data-bs-toggle="tab" href="#branch-stores-pane" role="tab" aria-controls="branch-stores-pane" aria-selected="false">
                            {{ __('branches.branch_stores.title') }}
                        </a>
                    </li>
                </ul>

                <div class="tab-content border border-top-0 p-3" id="branch-form-tab-content">
                    <div class="tab-pane fade show active" id="branch-basic-pane" role="tabpanel" aria-labelledby="branch-basic-tab">
                <div class="row g-3 align-items-start">
                    @if ($canControlDocumentNumber)
                        <div class="col-md-3 col-lg-2">
                            <label class="form-label" for="doc_number">{{ __('branches.attributes.doc_number') }}</label>
                            @if ($isView)
                                <x-forms.view-field for="doc_number" as="display" :value="$documentNumberValue" input-class="text-center js-branch-doc-number" />
                            @else
                                <x-forms.input id="doc_number" class="text-center form-control js-branch-doc-number" name="doc_number" type="number" min="0" step="1" inputmode="numeric" value="{{ $documentNumberValue }}" />
                            @endif
                            <div class="form-text">{{ __('branches.document_number_control.helper') }}</div>
                            <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                        </div>
                    @elseif ($isEdit || $isView)
                        <div class="col-md-3 col-lg-2">
                            <x-forms.view-field
                                for="doc_number_display"
                                as="display"
                                :label="__('branches.attributes.doc_num')"
                                :value="$record?->doc_num"
                                input-class="text-center"
                            />
                        </div>
                    @endif

                    <div class="{{ $showsDocumentNumberColumn ? 'col-md-4 col-lg-5' : 'col-md-6' }} js-select2-field">
                        <x-forms.label for="company_doc_num" :label="__('branches.attributes.company')" required />
                        @if ($isView)
                            <x-forms.view-field for="company_doc_num" :value="$companyOption['text'] ?? null" />
                        @else
                            <x-forms.select class="form-select js-select2-ajax" id="company_doc_num" name="company_doc_num" data-url="{{ route('admin.select2.companies', ['access_scope' => 'branch_form']) }}" data-placeholder="{{ __('branches.placeholders.company') }}" data-allow-clear="true" required>
                                @if ($companyOption)
                                    <option value="{{ $companyOption['id'] }}" selected>{{ $companyOption['text'] }}</option>
                                @endif
                            </x-forms.select>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="company_doc_num"></div>
                    </div>

                    <div class="{{ $showsDocumentNumberColumn ? 'col-md-5 col-lg-5' : 'col-md-6' }}">
                        <x-forms.label for="name" :label="__('branches.attributes.name')" required />
                        @if ($isView)
                            <x-forms.view-field for="name" :value="$fieldValue('name')" />
                        @else
                            <x-forms.input id="name" class="form-control" name="name" type="text" value="{{ $fieldValue('name') }}" required autofocus />
                        @endif
                        <div class="invalid-feedback" data-error-for="name"></div>
                    </div>

                    <div class="col-md-6">
                        <x-forms.label for="type" :label="__('branches.attributes.type')" required />
                        @if ($isView)
                            <x-forms.view-field for="type" :value="$branchTypeValue ? __('branches.types.'.$branchTypeValue) : null" />
                        @else
                            <x-forms.select class="form-select" id="type" name="type" required>
                                @foreach (\Modules\Core\Models\Branch::types() as $type)
                                    <option value="{{ $type }}" @selected($branchTypeValue === $type)>{{ __("branches.types.{$type}") }}</option>
                                @endforeach
                            </x-forms.select>
                        @endif
                        <div class="invalid-feedback" data-error-for="type"></div>
                    </div>

                    <div class="col-md-6">
                        <x-forms.label for="status" :label="__('branches.attributes.status')" required />
                        @if ($isView)
                            <x-forms.view-field for="status" :value="$branchStatusValue ? __('branches.statuses.'.$branchStatusValue) : null" />
                        @else
                            <x-forms.select class="form-select" id="status" name="status" required>
                                @foreach (['active', 'inactive'] as $status)
                                    <option value="{{ $status }}" @selected($branchStatusValue === $status)>{{ __("branches.statuses.{$status}") }}</option>
                                @endforeach
                            </x-forms.select>
                        @endif
                        <div class="invalid-feedback" data-error-for="status"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="phone">{{ __('branches.attributes.phone') }}</label>
                        @php
                            $phoneValue = $fieldValue('phone');
                        @endphp
                        @if ($isView && filled($phoneValue))
                            <x-forms.view-field for="phone" as="display">
                                <x-contact.phone-actions :phone="$phoneValue" />
                            </x-forms.view-field>
                        @elseif ($isView)
                            <x-forms.view-field for="phone" :value="$phoneValue" />
                        @else
                            <x-forms.input class="form-control" id="phone" name="phone" type="tel" value="{{ $phoneValue }}" />
                        @endif
                        <div class="invalid-feedback" data-error-for="phone"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="mobile">{{ __('branches.attributes.mobile') }}</label>
                        @php
                            $mobileValue = $fieldValue('mobile');
                        @endphp
                        @if ($isView && filled($mobileValue))
                            <x-forms.view-field for="mobile" as="display">
                                <x-contact.phone-actions :phone="$mobileValue" />
                            </x-forms.view-field>
                        @elseif ($isView)
                            <x-forms.view-field for="mobile" :value="$mobileValue" />
                        @else
                            <x-forms.input class="form-control" id="mobile" name="mobile" type="tel" value="{{ $mobileValue }}" />
                        @endif
                        <div class="invalid-feedback" data-error-for="mobile"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="email">{{ __('branches.attributes.email') }}</label>
                        @php
                            $emailValue = $fieldValue('email');
                        @endphp
                        @if ($isView && filled($emailValue))
                            <x-forms.view-field for="email" as="display">
                                <x-contact.email-link :email="$emailValue" />
                            </x-forms.view-field>
                        @elseif ($isView)
                            <x-forms.view-field for="email" :value="$emailValue" />
                        @else
                            <x-forms.input class="form-control" id="email" name="email" type="email" value="{{ $emailValue }}" />
                        @endif
                        <div class="invalid-feedback" data-error-for="email"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="hotline">{{ __('branches.attributes.hotline') }}</label>
                        @php
                            $hotlineValue = $fieldValue('hotline');
                        @endphp
                        @if ($isView && filled($hotlineValue))
                            <x-forms.view-field for="hotline" as="display">
                                <x-contact.phone-actions :phone="$hotlineValue" />
                            </x-forms.view-field>
                        @elseif ($isView)
                            <x-forms.view-field for="hotline" :value="$hotlineValue" />
                        @else
                            <x-forms.input class="form-control" id="hotline" name="hotline" type="tel" value="{{ $hotlineValue }}" />
                        @endif
                        <div class="invalid-feedback" data-error-for="hotline"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="contact_person">{{ __('branches.attributes.contact_person') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="contact_person" :value="$fieldValue('contact_person')" />
                        @else
                            <x-forms.input class="form-control" id="contact_person" name="contact_person" type="text" value="{{ $fieldValue('contact_person') }}" />
                        @endif
                        <div class="invalid-feedback" data-error-for="contact_person"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="camera_url">{{ __('branches.attributes.camera_url') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="camera_url" :value="$fieldValue('camera_url')" link />
                        @else
                            <x-forms.input class="form-control" id="camera_url" name="camera_url" type="url" value="{{ $fieldValue('camera_url') }}" />
                        @endif
                        <div class="invalid-feedback" data-error-for="camera_url"></div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="address">{{ __('branches.attributes.address') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="address" as="textarea" :value="$fieldValue('address')" rows="3" />
                        @else
                            <x-forms.textarea class="form-control" id="address" name="address" rows="3">{{ $fieldValue('address') }}</x-forms.textarea>
                        @endif
                        <div class="invalid-feedback" data-error-for="address"></div>
                    </div>

                    <div class="col-12"><hr><h6 class="mb-0">{{ __('branches.attendance_location.title') }}</h6><div class="form-text">{{ __('branches.attendance_location.help') }}</div></div>

                    @foreach (['attendance_latitude', 'attendance_longitude'] as $locationField)
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="{{ $locationField }}">{{ __('branches.attributes.'.$locationField) }}</label>
                            @if ($isView)
                                <x-forms.view-field :for="$locationField" :value="$fieldValue($locationField)" numeric dir="ltr" />
                            @else
                                <x-forms.input class="form-control" id="{{ $locationField }}" name="{{ $locationField }}" type="number" step="0.0000001" value="{{ $fieldValue($locationField) }}" dir="ltr" />
                            @endif
                            <div class="invalid-feedback" data-error-for="{{ $locationField }}"></div>
                        </div>
                    @endforeach

                    @foreach (['attendance_radius_meters' => 200, 'attendance_max_accuracy_meters' => 100] as $locationField => $defaultValue)
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="{{ $locationField }}">{{ __('branches.attributes.'.$locationField) }}</label>
                            @if ($isView)
                                <x-forms.view-field :for="$locationField" :value="$fieldValue($locationField, $defaultValue)" numeric />
                            @else
                                <x-forms.input class="form-control" id="{{ $locationField }}" name="{{ $locationField }}" type="number" min="1" step="1" value="{{ $fieldValue($locationField, $defaultValue) }}" />
                            @endif
                            <div class="invalid-feedback" data-error-for="{{ $locationField }}"></div>
                        </div>
                    @endforeach

                    <div class="col-12 col-md-4">
                        <label class="form-label" for="attendance_location_policy">{{ __('branches.attributes.attendance_location_policy') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="attendance_location_policy" :value="__('branches.attendance_location.policies.'.$fieldValue('attendance_location_policy', 'warn'))" />
                        @else
                            <x-forms.select class="form-select" id="attendance_location_policy" name="attendance_location_policy">
                                @foreach (['allow', 'warn', 'reject'] as $policy)
                                    <option value="{{ $policy }}" @selected($fieldValue('attendance_location_policy', 'warn') === $policy)>{{ __('branches.attendance_location.policies.'.$policy) }}</option>
                                @endforeach
                            </x-forms.select>
                        @endif
                        <div class="invalid-feedback" data-error-for="attendance_location_policy"></div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="notes">{{ __('branches.attributes.notes') }}</label>
                        @if ($isView)
                            <x-forms.view-field for="notes" as="textarea" :value="$fieldValue('notes')" rows="4" />
                        @else
                            <x-forms.textarea class="form-control" id="notes" name="notes" rows="4">{{ $fieldValue('notes') }}</x-forms.textarea>
                        @endif
                        <div class="invalid-feedback" data-error-for="notes"></div>
                    </div>

                    @if (! $isCreate)
                        <div class="col-12">
                            <x-audit-fields-row
                                :metadata="$metadata"
                                :show-deleted="$isView && ($record?->trashed() ?? false)"
                                :show-restored="$isView && ! ($record?->trashed() ?? false) && (($record?->restored_at ?? null) || ($record?->restored_by ?? null))"
                            />
                        </div>
                    @endif
                </div>
                    </div>

                    <div class="tab-pane fade js-station-halls-section @unless ($showStationHalls) d-none @endunless" id="branch-station-halls-pane" role="tabpanel" aria-labelledby="branch-station-halls-tab">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                            <div>
                                <label class="form-label mb-0">{{ __('branches.station_halls.title') }}</label>
                                <div class="form-text mb-0">{{ __('branches.station_halls.helper') }}</div>
                            </div>
                            @unless ($isView)
                                <button type="button" class="btn btn-falcon-default btn-sm js-add-station-hall" data-shortcut-action="branch.add_hall" title="{{ __('branches.shortcuts.add_hall') }}" data-bs-title="{{ __('branches.shortcuts.add_hall') }}">
                                    <span class="fas fa-plus me-1"></span>{{ __('branches.station_halls.add') }}
                                </button>
                            @endunless
                        </div>

                        @if ($isView)
                            <x-forms.view-field for="station_halls" as="display">
                                @if ($stationHallNames !== [])
                                    <div class="d-flex flex-wrap gap-2">
                                        @foreach ($stationHallNames as $hallName)
                                            <span class="badge rounded-pill badge-subtle-info">{{ $hallName }}</span>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="erp-view-empty-value text-600">{{ __('branches.station_halls.empty') }}</span>
                                @endif
                            </x-forms.view-field>
                        @else
                            <div class="js-station-halls-list">
                                @foreach (($stationHallRows === [] ? [['key' => null, 'name' => '']] : $stationHallRows) as $hallIndex => $hall)
                                    <div class="input-group input-group-sm mb-2 js-station-hall-row" data-station-hall-index="{{ $hallIndex }}">
                                        <x-forms.input type="hidden" name="station_halls[{{ $hallIndex }}][key]" value="{{ $hall['key'] ?? '' }}" />
                                        <x-forms.input class="form-control js-station-hall-input" name="station_halls[{{ $hallIndex }}][name]" type="text" value="{{ $hall['name'] ?? '' }}" placeholder="{{ __('branches.station_halls.placeholder') }}" />
                                        <button class="btn btn-falcon-default js-remove-station-hall" type="button" aria-label="{{ __('branches.station_halls.remove') }}">
                                            <span class="fas fa-times"></span>
                                        </button>
                                        <div class="invalid-feedback" data-error-for="station_halls.{{ $hallIndex }}.name"></div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="station_halls"></div>
                    </div>

                    <div class="tab-pane fade" id="branch-stores-pane" role="tabpanel" aria-labelledby="branch-stores-tab">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                            <div>
                                <label class="form-label mb-0">{{ __('branches.branch_stores.title') }}</label>
                                <div class="form-text mb-0">{{ __('branches.branch_stores.helper') }}</div>
                            </div>
                            @unless ($isView)
                                <button type="button" class="btn btn-falcon-default btn-sm js-add-branch-store" data-shortcut-action="branch.add_store" title="{{ __('branches.shortcuts.add_store') }}" data-bs-title="{{ __('branches.shortcuts.add_store') }}">
                                    <span class="fas fa-plus me-1"></span>{{ __('branches.branch_stores.add') }}
                                </button>
                            @endunless
                        </div>

                        @if ($isView)
                            <x-forms.view-field for="branch_stores" as="display">
                                @if ($branchStoreDisplays !== [])
                                    <div class="d-flex flex-column gap-2">
                                        @foreach ($branchStoreDisplays as $store)
                                            <div class="d-flex flex-wrap align-items-center gap-2">
                                                <span class="text-900">{{ $store['name'] }}</span>
                                                <span class="badge rounded-pill badge-subtle-info">{{ $branchStoreClassificationLabel($store['classification'] ?: \Modules\Core\Models\BranchStore::ClassificationGeneral) }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="erp-view-empty-value text-600">{{ __('branches.branch_stores.empty') }}</span>
                                @endif
                            </x-forms.view-field>
                        @else
                            <div class="js-branch-stores-list">
                                @foreach (($branchStoreRows === [] ? [['key' => null, 'name' => '', 'classification' => '']] : $branchStoreRows) as $storeIndex => $store)
                                    <div class="row g-2 align-items-stretch mb-2 js-branch-store-row" data-branch-store-index="{{ $storeIndex }}">
                                        <x-forms.input type="hidden" name="branch_stores[{{ $storeIndex }}][key]" value="{{ $store['key'] ?? '' }}" />
                                        <div class="col-12 col-md-5">
                                            <label class="form-label small" for="branch-store-name-{{ $storeIndex }}">{{ __('branches.branch_stores.name') }}</label>
                                            <x-forms.input class="form-control form-control-sm js-branch-store-input" id="branch-store-name-{{ $storeIndex }}" name="branch_stores[{{ $storeIndex }}][name]" type="text" value="{{ $store['name'] ?? '' }}" placeholder="{{ __('branches.branch_stores.placeholder') }}" />
                                            <div class="invalid-feedback" data-error-for="branch_stores.{{ $storeIndex }}.name"></div>
                                        </div>
                                        <div class="col">
                                            <label class="form-label small" for="branch-store-classification-{{ $storeIndex }}">{{ __('branches.branch_stores.classification') }}</label>
                                            <x-forms.select class="form-select form-select-sm js-branch-store-classification" id="branch-store-classification-{{ $storeIndex }}" name="branch_stores[{{ $storeIndex }}][classification]">
                                                <option value="">{{ __('branches.branch_stores.classification_placeholder') }}</option>
                                                @foreach (\Modules\Core\Models\BranchStore::classifications() as $classification)
                                                    <option value="{{ $classification }}" @selected(($store['classification'] ?? '') === $classification)>{{ $branchStoreClassificationLabel($classification) }}</option>
                                                @endforeach
                                            </x-forms.select>
                                            <div class="invalid-feedback" data-error-for="branch_stores.{{ $storeIndex }}.classification"></div>
                                        </div>
                                        <div class="col-auto d-flex align-items-end">
                                            <button class="btn btn-falcon-default btn-sm btn-icon-only px-2 js-remove-branch-store" type="button" aria-label="{{ __('branches.branch_stores.remove') }}" title="{{ __('branches.branch_stores.remove') }}">
                                                <span class="fas fa-times"></span>
                                            </button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="branch_stores"></div>
                    </div>

                </div>
            </div>

            @include('modules.core.branches.partials.form-footer')
        </div>
    </form>
@endsection

@push('scripts')
    @php
        $coreBranchesMessages = [
            'noChanges' => __('common.messages.no_changes'),
            'saved' => __('common.messages.saved_successfully'),
            'validationFailed' => __('common.messages.validation_failed'),
            'unexpectedError' => __('common.messages.unexpected_error'),
            'deleteConfirmTitle' => __('branches.messages.delete_confirm_title'),
            'deleteConfirmText' => __('branches.messages.delete_confirm_text'),
            'deleteConfirmYes' => __('branches.messages.delete_confirm_yes'),
            'no' => __('common.actions.no'),
            'restore' => __('branches.trash.restore'),
            'restoreConfirmTitle' => __('branches.trash.restore_confirm_title'),
            'restoreConfirmText' => __('branches.trash.restore_confirm_text'),
            'restoreConfirmYes' => __('branches.trash.restore_confirm_yes'),
            'stationHallPlaceholder' => __('branches.station_halls.placeholder'),
            'stationHallRemove' => __('branches.station_halls.remove'),
            'branchStorePlaceholder' => __('branches.branch_stores.placeholder'),
            'branchStoreRemove' => __('branches.branch_stores.remove'),
            'branchStoreName' => __('branches.branch_stores.name'),
            'branchStoreClassification' => __('branches.branch_stores.classification'),
            'branchStoreClassificationPlaceholder' => __('branches.branch_stores.classification_placeholder'),
            'branchStoreClassifications' => collect(\Modules\Core\Models\BranchStore::classifications())
                ->mapWithKeys(fn (string $classification): array => [$classification => $branchStoreClassificationLabel($classification)])
                ->all(),
            'cancel' => __('common.actions.cancel'),
            'confirm' => __('common.actions.confirm'),
        ];
    @endphp
    <script>
        window.coreBranchesMessages = @json($coreBranchesMessages);
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Core/branches.js') }}"></script>
@endpush

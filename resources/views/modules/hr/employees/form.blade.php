@extends('layouts.app')

@php
    use Modules\Core\Services\DateFormatService;

    $isView = $mode === 'view';
    $isEdit = $mode === 'edit';
    $isClone = $mode === 'clone';
    $title = match ($mode) {
        'edit' => __('hr.employees.edit'),
        'view' => __('hr.employees.view'),
        'clone' => __('hr.titles.clone'),
        default => __('hr.employees.create'),
    };
    $dateFormatService = app(DateFormatService::class);
    $employeeName = $isClone && $employee ? __('hr.defaults.clone_name', ['name' => $employee->full_name]) : $employee?->full_name;
    $cloneBlankFields = ['national_id', 'email', 'work_email'];
    $dateFields = ['birth_date', 'hire_date', 'start_date', 'end_date', 'national_id_expiry_date', 'passport_expiry_date', 'probation_end_date', 'contract_start_date', 'contract_end_date', 'termination_date'];
    $select2FieldNames = array_values(array_diff(array_keys($selectFields), ['default_shift_doc_num']));
    $personTypeOptions = ['fixed_employee', 'regular_labor', 'casual_labor'];
    $statusOptions = ['active', 'inactive', 'suspended', 'stopped', 'left'];
    $payBasisOptions = ['monthly_salary', 'weekly_wage', 'daily_wage', 'hourly_wage', 'shift_wage', 'piece_rate'];
    $payAmountFields = [
        'monthly_salary' => 'basic_salary',
        'weekly_wage' => 'weekly_wage',
        'daily_wage' => 'daily_wage',
        'hourly_wage' => 'hourly_wage',
        'shift_wage' => 'shift_wage',
        'piece_rate' => 'piece_rate',
    ];
    $attendancePolicyOptions = ['fixed_shift', 'flexible_shift', 'scheduled_labor', 'attendance_only', 'manual_work_sessions'];
    $paymentMethodOptions = ['cash', 'bank_transfer', 'wallet', 'other'];
    $fieldValue = function (string $field, mixed $default = '') use ($employee, $employeeName, $isClone, $cloneBlankFields) {
        if ($field === 'full_name') {
            return old($field, $employeeName ?? $default);
        }

        if ($isClone && in_array($field, $cloneBlankFields, true)) {
            return old($field, $default);
        }

        return old($field, $employee?->{$field} ?? $default);
    };
    $dateValue = function (string $field) use ($employee, $dateFormatService) {
        $old = old($field);

        if ($old !== null) {
            return $old;
        }

        return $employee?->{$field} ? $dateFormatService->formatDate($employee->{$field}, '') : '';
    };
    $documentDateValue = function ($document, string $field) use ($dateFormatService): string {
        return $document?->{$field} ? $dateFormatService->formatDate($document->{$field}, '') : '';
    };
    $selectOption = function (string $field) use ($selectedOptions, $defaults): ?array {
        $option = $selectedOptions[$field] ?? null;

        if ($field === 'payroll_currency_doc_num' && (($option['id'] ?? '') === '') && ! empty($defaults['payroll_currency_option'])) {
            return [
                ...($option ?? []),
                'id' => $defaults['payroll_currency_option']['id'],
                'text' => $defaults['payroll_currency_option']['text'],
            ];
        }

        return $option;
    };
    $selectValue = fn (string $field): string => (string) old($field, $selectOption($field)['id'] ?? '');
    $documentNumberValue = old('doc_number', ($isEdit || $isView) ? $employee?->doc_number : '');
    $showsDocumentNumberColumn = $canControlDocumentNumber || (($isEdit || $isView) && ! $canControlDocumentNumber);
    $trackedFields = [
        'doc_number',
        'full_name',
        'person_type',
        'status',
        'gender',
        'birth_date',
        'marital_status',
        'national_id',
        'work_email',
        'email',
        'phone',
        'mobile',
        'alternate_phone',
        'address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'hire_date',
        'start_date',
        'end_date',
        'attendance_policy_type',
        'pay_basis',
        'exchange_rate',
        'basic_salary',
        'weekly_wage',
        'daily_wage',
        'hourly_wage',
        'shift_wage',
        'piece_rate',
        'payment_method',
        'notes',
        ...$select2FieldNames,
    ];
    $originalEmployeeData = [];
    foreach ($trackedFields as $trackedField) {
        $originalEmployeeData[$trackedField] = match (true) {
            $trackedField === 'doc_number' => $canControlDocumentNumber ? (($isEdit || $isView) ? $employee?->doc_number : '') : null,
            in_array($trackedField, $dateFields, true) => $dateValue($trackedField),
            in_array($trackedField, $select2FieldNames, true) => $selectValue($trackedField),
            default => (string) $fieldValue($trackedField),
        };
    }
    $documentRows = ($employee && ! $isClone) ? $employee->documents : collect();
@endphp

@section('title', $title)

@push('styles')
    <style>
        .hr-select2-inline-control {
            display: flex;
            gap: .375rem;
            align-items: stretch;
        }

        .hr-select2-inline-control .select2-container {
            flex: 1 1 auto;
            width: 100% !important;
        }

        .hr-select2-inline-control .js-inline-lookup-create {
            flex: 0 0 auto;
            width: 2.25rem;
            padding-inline: .5rem;
        }

        .hr-document-table th,
        .hr-document-table td {
            vertical-align: top;
        }

        .hr-document-table .form-control,
        .hr-document-table .form-select {
            min-width: 9rem;
        }

        .hr-document-table .hr-document-file-control {
            min-width: 13rem;
        }

        .hr-employee-form-card .select2-container {
            width: 100% !important;
        }

        .hr-employee-form-card .product-image-field .product-image-picker-panel {
            min-height: 8.5rem;
        }

        .hr-employee-form-card .product-image-preview-frame {
            width: 8rem !important;
            height: 8rem !important;
            min-width: 8rem !important;
            aspect-ratio: 1 / 1;
        }

        .hr-employee-form-card .js-hr-pay-amount-input {
            text-align: center;
        }

        .hr-employee-form-card .product-image-preview-frame .js-product-image-preview-image {
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
    <form id="hr-employee-form"
        class="js-hr-employees-form"
        action="{{ $action }}"
        method="{{ $method }}"
        data-mode="{{ $mode }}"
        data-original='@json($originalEmployeeData)'
        data-main-currency-doc-num="{{ $defaults['main_currency_doc_num'] ?? '' }}"
        data-default-payroll-currency-doc-num="{{ $defaults['payroll_currency_option']['id'] ?? '' }}"
        data-default-payroll-currency-text="{{ $defaults['payroll_currency_option']['text'] ?? '' }}"
        novalidate>
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif
        <input type="hidden" name="submit_action" value="save">
        @if ($isClone && $cloneSourceToken)
            <input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}">
        @endif

        <div class="card hr-employee-form-card">
            @include('modules.hr.employees.partials.form-header')

            <div class="card-body js-hr-employees-form-body">
                <div class="alert alert-danger alert-dismissible fade show d-none js-hr-employees-alert" role="alert">
                    <span class="js-hr-employees-alert-message"></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('common.actions.close') }}"></button>
                </div>

                <ul class="mb-3 nav nav-tabs" id="hr-employee-tabs" role="tablist">
                    <li class="nav-item"><button class="nav-link active" id="tab-basic-info" data-bs-toggle="tab" data-bs-target="#pane-basic-info" type="button" role="tab">{{ __('hr.employees.sections.basic_info') }}</button></li>
                    <li class="nav-item"><button class="nav-link" id="tab-work-info" data-bs-toggle="tab" data-bs-target="#pane-work-info" type="button" role="tab">{{ __('hr.employees.sections.work_info') }}</button></li>
                    <li class="nav-item"><button class="nav-link" id="tab-attendance-biometric" data-bs-toggle="tab" data-bs-target="#pane-attendance-biometric" type="button" role="tab">{{ __('hr.employees.sections.attendance_biometric') }}</button></li>
                    <li class="nav-item"><button class="nav-link" id="tab-salary-payment" data-bs-toggle="tab" data-bs-target="#pane-salary-payment" type="button" role="tab">{{ __('hr.employees.sections.salary_payment') }}</button></li>
                    <li class="nav-item"><button class="nav-link" id="tab-documents" data-bs-toggle="tab" data-bs-target="#pane-documents" type="button" role="tab">{{ __('hr.employees.sections.documents') }}</button></li>
                    <li class="nav-item"><button class="nav-link" id="tab-notes" data-bs-toggle="tab" data-bs-target="#pane-notes" type="button" role="tab">{{ __('hr.employees.sections.notes') }}</button></li>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade show active" id="pane-basic-info" role="tabpanel" aria-labelledby="tab-basic-info">
                        <div class="row g-3 align-items-start">
                            @if ($canControlDocumentNumber)
                                <div class="col-md-3 col-xl-2">
                                    <label class="form-label" for="hr-employee-doc-number">{{ __('common.fields.document_number') }}</label>
                                    @if ($isView)
                                        <x-forms.view-field for="hr-employee-doc-number" as="display" :value="$documentNumberValue" input-class="text-center js-hr-employees-doc-number" />
                                    @else
                                        <input id="hr-employee-doc-number" name="doc_number" type="number" min="0" step="1" inputmode="numeric" class="text-center form-control js-hr-employees-doc-number" value="{{ $documentNumberValue }}" placeholder="{{ __('hr.document_number_control.placeholder') }}">
                                    @endif
                                    <div class="form-text">{{ __('hr.document_number_control.helper') }}</div>
                                    <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                                </div>
                            @elseif ($isEdit || $isView)
                                <div class="col-md-3 col-xl-2">
                                    <x-forms.view-field
                                        for="hr-employee-doc-number-display"
                                        as="display"
                                        :label="__('common.fields.doc_number')"
                                        :value="$employee?->doc_number"
                                        input-class="text-center"
                                    />
                                </div>
                            @endif

                            <div class="{{ $showsDocumentNumberColumn ? 'col-md-5 col-xl-4' : 'col-md-6 col-xl-5' }}">
                                <x-forms.label for="hr-employee-full-name" :label="__('hr.employees.attributes.full_name')" required />
                                @if ($isView)
                                    <x-forms.view-field for="hr-employee-full-name" :value="$fieldValue('full_name')" />
                                @else
                                    <input id="hr-employee-full-name" autofocus name="full_name" type="text" class="form-control" value="{{ $fieldValue('full_name') }}" required>
                                @endif
                                <div class="invalid-feedback" data-error-for="full_name"></div>
                            </div>

                            <div class="col-md-4 col-xl-3">
                                @php $personTypeValue = old('person_type', $employee?->person_type ?? 'fixed_employee'); @endphp
                                @if ($isView)
                                    <x-forms.view-field for="hr-employee-person-type" :label="__('hr.employees.attributes.person_type')" :value="$personTypeValue ? __('hr.employees.person_types.' . $personTypeValue) : null" required />
                                @else
                                    <x-forms.label for="hr-employee-person-type" :label="__('hr.employees.attributes.person_type')" required />
                                    <select id="hr-employee-person-type" name="person_type" class="form-select" required>
                                        @foreach ($personTypeOptions as $personType)
                                            <option value="{{ $personType }}" @selected($personTypeValue === $personType)>{{ __("hr.employees.person_types.{$personType}") }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <div class="invalid-feedback" data-error-for="person_type"></div>
                            </div>

                            <div class="col-md-4 col-xl-3">
                                @php $statusValue = old('status', $employee?->status ?? 'active'); @endphp
                                @if ($isView)
                                    <x-forms.view-field for="hr-employee-status" :label="__('common.fields.status')" :value="$statusValue ? __('hr.employees.statuses.' . $statusValue) : null" required />
                                @else
                                    <x-forms.label for="hr-employee-status" :label="__('common.fields.status')" required />
                                    <select id="hr-employee-status" name="status" class="form-select" required>
                                        @foreach ($statusOptions as $status)
                                            <option value="{{ $status }}" @selected($statusValue === $status)>{{ __("hr.employees.statuses.{$status}") }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <div class="invalid-feedback" data-error-for="status"></div>
                            </div>

                            <div class="col-md-4 col-xl-3">
                                @php $genderValue = old('gender', $employee?->gender); @endphp
                                @if ($isView)
                                    <x-forms.view-field for="hr-employee-gender" :label="__('hr.employees.attributes.gender')" :value="$genderValue ? __('hr.employees.genders.' . $genderValue) : null" />
                                @else
                                    <label class="form-label" for="hr-employee-gender">{{ __('hr.employees.attributes.gender') }}</label>
                                    <select id="hr-employee-gender" name="gender" class="form-select">
                                        <option value="">{{ __('common.placeholders.select') }}</option>
                                        @foreach (['male', 'female'] as $gender)
                                            <option value="{{ $gender }}" @selected($genderValue === $gender)>{{ __("hr.employees.genders.{$gender}") }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <div class="invalid-feedback" data-error-for="gender"></div>
                            </div>

                            <div class="col-md-4 col-xl-3">
                                <label class="form-label" for="hr-employee-birth-date">{{ __('hr.employees.attributes.birth_date') }}</label>
                                @if ($isView)
                                    <x-forms.view-field for="hr-employee-birth-date" :value="$dateValue('birth_date')" />
                                @else
                                    <input id="hr-employee-birth-date" name="birth_date" type="text" class="form-control js-date-picker" value="{{ $dateValue('birth_date') }}" placeholder="{{ __('common.placeholders.select_date') }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}">
                                @endif
                                <div class="invalid-feedback" data-error-for="birth_date"></div>
                            </div>

                            <div class="col-md-4 col-xl-3">
                                @php $maritalStatusValue = old('marital_status', $employee?->marital_status); @endphp
                                @if ($isView)
                                    <x-forms.view-field for="hr-employee-marital-status" :label="__('hr.employees.attributes.marital_status')" :value="$maritalStatusValue ? __('hr.employees.marital_statuses.' . $maritalStatusValue) : null" />
                                @else
                                    <label class="form-label" for="hr-employee-marital-status">{{ __('hr.employees.attributes.marital_status') }}</label>
                                    <select id="hr-employee-marital-status" name="marital_status" class="form-select">
                                        <option value="">{{ __('common.placeholders.select') }}</option>
                                        @foreach (['single', 'married', 'divorced', 'widowed'] as $maritalStatus)
                                            <option value="{{ $maritalStatus }}" @selected($maritalStatusValue === $maritalStatus)>{{ __("hr.employees.marital_statuses.{$maritalStatus}") }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <div class="invalid-feedback" data-error-for="marital_status"></div>
                            </div>

                            <div class="col-md-4 col-xl-3">
                                <label class="form-label" for="hr-employee-national-id">{{ __('hr.employees.attributes.national_id') }}</label>
                                @if ($isView)
                                    <x-forms.view-field for="hr-employee-national-id" :value="$fieldValue('national_id')" />
                                @else
                                    <input id="hr-employee-national-id" name="national_id" type="text" class="form-control" value="{{ $fieldValue('national_id') }}">
                                @endif
                                <div class="invalid-feedback" data-error-for="national_id"></div>
                            </div>

                            <div class="col-12 order-first">
                                <div class="row g-3">
                                    <div class="col-md-6 product-image-field">
                                        @php
                                            $photoFile = $employee?->photoArchiveFile;
                                            $photoValue = old('photo_archive_file_doc_num', $photoFile?->doc_num ?? '');
                                            $photoPreview = $photoValue !== '' && $photoFile?->doc_num === $photoValue
                                                ? route('admin.file-manager.files.preview', $photoFile->doc_num)
                                                : '';
                                            $photoFileLabel = $photoPreview !== ''
                                                ? ($photoFile?->original_name ?? __('hr.employees.photo.existing_file'))
                                                : __('hr.employees.photo.no_file_selected');
                                        @endphp
                                        <x-forms.label :for="$isView ? 'hr-employee-photo-preview' : 'hr-employee-photo-picker-button'" :label="__('hr.employees.attributes.photo_archive_file_doc_num')" />
                                        @unless ($isView)
                                            <input type="hidden" id="hr-employee-photo-archive-file-doc-num" name="photo_archive_file_doc_num" value="{{ $photoValue }}">
                                        @endunless
                                        <div id="hr-employee-photo-picker-field"
                                            class="p-3 border rounded-2 bg-body-tertiary product-image-picker-panel js-product-image-picker-field @if ($isView) opacity-75 @endif"
                                            data-current-url="{{ $photoPreview }}"
                                            data-existing-url="{{ $photoPreview }}"
                                            data-existing-label="{{ __('hr.employees.photo.existing_file') }}"
                                            data-no-image-label="{{ __('hr.employees.photo.no_file_selected') }}">
                                            <div class="gap-3 d-flex flex-column flex-lg-row align-items-start">
                                                <div id="hr-employee-photo-preview" class="overflow-hidden bg-white border d-flex align-items-center justify-content-center rounded-2 flex-shrink-0 product-image-preview-frame">
                                                    <img class="w-100 h-100 object-fit-contain js-product-image-preview-image @if ($photoPreview === '') d-none @endif"
                                                        src="{{ $photoPreview }}"
                                                        alt="{{ __('hr.employees.attributes.photo_archive_file_doc_num') }}">
                                                    <span class="fas fa-image text-400 fs-5 js-product-image-placeholder @if ($photoPreview !== '') d-none @endif"></span>
                                                </div>

                                                <div class="flex-1 min-w-0">
                                                    <div class="fw-semibold js-product-image-file-name">{{ $photoFileLabel }}</div>
                                                    <div class="mt-1 small text-600">
                                                        {{ __('hr.employees.photo.help') }}
                                                    </div>
                                                    @unless ($isView)
                                                        @can('file_manager.view')
                                                            <div class="flex-wrap gap-2 mt-2 d-flex">
                                                                <button type="button"
                                                                    id="hr-employee-photo-picker-button"
                                                                    class="btn btn-falcon-primary btn-sm js-product-image-picker-trigger"
                                                                    data-file-picker
                                                                    data-picker-accept="image"
                                                                    data-picker-max="1"
                                                                    data-picker-title="{{ __('hr.employees.photo.select_from_file_manager') }}"
                                                                    data-picker-target-input="#hr-employee-photo-archive-file-doc-num"
                                                                    data-picker-uploader="#hr-employee-photo-picker-field"
                                                                    data-picker-collection="employee_photo"
                                                                    data-picker-allow-upload="{{ auth()->user()?->can('file_manager.upload') ? 'true' : 'false' }}"
                                                                    data-picker-allow-create-folder="{{ auth()->user()?->can('file_manager.folders.create') ? 'true' : 'false' }}">
                                                                    <span class="fas fa-images me-1"></span>{{ __('hr.employees.actions.select_photo') }}
                                                                </button>
                                                                <button type="button"
                                                                    class="btn btn-falcon-default btn-sm text-danger js-product-image-remove @if ($photoPreview === '') d-none @endif">
                                                                    <span class="fas fa-times me-1"></span>{{ __('hr.employees.actions.remove_photo') }}
                                                                </button>
                                                            </div>
                                                        @endcan
                                                    @endunless
                                                </div>
                                            </div>
                                        </div>
                                        <div class="invalid-feedback d-block" data-error-for="photo_archive_file_doc_num"></div>
                                    </div>

                                    <div class="col-md-6 product-image-field">
                                        @php
                                            $signatureFile = $employee?->signatureArchiveFile;
                                            $signatureValue = old('signature_archive_file_doc_num', $signatureFile?->doc_num ?? '');
                                            $signaturePreview = $signatureValue !== '' && $signatureFile?->doc_num === $signatureValue
                                                ? route('admin.file-manager.files.preview', $signatureFile->doc_num)
                                                : '';
                                            $signatureFileLabel = $signaturePreview !== ''
                                                ? ($signatureFile?->original_name ?? __('hr.employees.signature.existing_file'))
                                                : __('hr.employees.signature.no_file_selected');
                                        @endphp
                                        <x-forms.label :for="$isView ? 'hr-employee-signature-preview' : 'hr-employee-signature-picker-button'" :label="__('hr.employees.attributes.signature_archive_file_doc_num')" />
                                        @unless ($isView)
                                            <input type="hidden" id="hr-employee-signature-archive-file-doc-num" name="signature_archive_file_doc_num" value="{{ $signatureValue }}">
                                        @endunless
                                        <div id="hr-employee-signature-picker-field"
                                            class="p-3 border rounded-2 bg-body-tertiary product-image-picker-panel js-product-image-picker-field js-hr-employee-signature-field @if ($isView) opacity-75 @endif"
                                            data-current-url="{{ $signaturePreview }}"
                                            data-existing-url="{{ $signaturePreview }}"
                                            data-existing-label="{{ __('hr.employees.signature.existing_file') }}"
                                            data-no-image-label="{{ __('hr.employees.signature.no_file_selected') }}">
                                            <div class="gap-3 d-flex flex-column flex-lg-row align-items-start">
                                                <div id="hr-employee-signature-preview" class="overflow-hidden bg-white border d-flex align-items-center justify-content-center rounded-2 flex-shrink-0 product-image-preview-frame">
                                                    <img class="w-100 h-100 object-fit-contain js-product-image-preview-image @if ($signaturePreview === '') d-none @endif"
                                                        src="{{ $signaturePreview }}"
                                                        alt="{{ __('hr.employees.attributes.signature_archive_file_doc_num') }}">
                                                    <span class="fas fa-signature text-400 fs-5 js-product-image-placeholder @if ($signaturePreview !== '') d-none @endif"></span>
                                                </div>

                                                <div class="flex-1 min-w-0">
                                                    <div class="fw-semibold js-product-image-file-name">{{ $signatureFileLabel }}</div>
                                                    <div class="mt-1 small text-600">
                                                        {{ __('hr.employees.signature.help') }}
                                                    </div>
                                                    @unless ($isView)
                                                        @can('file_manager.view')
                                                            <div class="flex-wrap gap-2 mt-2 d-flex">
                                                                <button type="button"
                                                                    id="hr-employee-signature-picker-button"
                                                                    class="btn btn-falcon-primary btn-sm js-product-image-picker-trigger"
                                                                    data-file-picker
                                                                    data-picker-accept="image"
                                                                    data-picker-max="1"
                                                                    data-picker-title="{{ __('hr.employees.signature.select_from_file_manager') }}"
                                                                    data-picker-target-input="#hr-employee-signature-archive-file-doc-num"
                                                                    data-picker-uploader="#hr-employee-signature-picker-field"
                                                                    data-picker-collection="employee_signature"
                                                                    data-picker-allow-upload="{{ auth()->user()?->can('file_manager.upload') ? 'true' : 'false' }}"
                                                                    data-picker-allow-create-folder="{{ auth()->user()?->can('file_manager.folders.create') ? 'true' : 'false' }}">
                                                                    <span class="fas fa-images me-1"></span>{{ __('hr.employees.actions.select_signature') }}
                                                                </button>
                                                                <button type="button"
                                                                    class="btn btn-falcon-default btn-sm text-danger js-product-image-remove @if ($signaturePreview === '') d-none @endif">
                                                                    <span class="fas fa-times me-1"></span>{{ __('hr.employees.actions.remove_signature') }}
                                                                </button>
                                                            </div>
                                                        @endcan
                                                    @endunless
                                                </div>
                                            </div>
                                        </div>
                                        <div class="invalid-feedback d-block" data-error-for="signature_archive_file_doc_num"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-4 col-xl-3">
                                <label class="form-label" for="hr-employee-phone">{{ __('hr.employees.attributes.phone') }}</label>
                                @if ($isView)
                                    <x-forms.view-field for="hr-employee-phone" :value="$fieldValue('phone')" />
                                @else
                                    <input id="hr-employee-phone" name="phone" type="text" class="form-control" value="{{ $fieldValue('phone') }}">
                                @endif
                                <div class="invalid-feedback" data-error-for="phone"></div>
                            </div>

                            <div class="col-md-4 col-xl-3">
                                <label class="form-label" for="hr-employee-mobile">{{ __('hr.employees.attributes.mobile') }}</label>
                                @if ($isView)
                                    <x-forms.view-field for="hr-employee-mobile" :value="$fieldValue('mobile')" />
                                @else
                                    <input id="hr-employee-mobile" name="mobile" type="text" class="form-control" value="{{ $fieldValue('mobile') }}">
                                @endif
                                <div class="invalid-feedback" data-error-for="mobile"></div>
                            </div>

                            <div class="col-md-4 col-xl-3">
                                <label class="form-label" for="hr-employee-alternate-phone">{{ __('hr.employees.attributes.alternate_phone') }}</label>
                                @if ($isView)
                                    <x-forms.view-field for="hr-employee-alternate-phone" :value="$fieldValue('alternate_phone')" />
                                @else
                                    <input id="hr-employee-alternate-phone" name="alternate_phone" type="text" class="form-control" value="{{ $fieldValue('alternate_phone') }}">
                                @endif
                                <div class="invalid-feedback" data-error-for="alternate_phone"></div>
                            </div>

                            <div class="col-md-4 col-xl-3">
                                <label class="form-label" for="hr-employee-email">{{ __('hr.employees.attributes.email') }}</label>
                                @if ($isView)
                                    <x-forms.view-field for="hr-employee-email" :value="$fieldValue('email')" />
                                @else
                                    <input id="hr-employee-email" name="email" type="email" class="form-control" value="{{ $fieldValue('email') }}">
                                @endif
                                <div class="invalid-feedback" data-error-for="email"></div>
                            </div>

                            <div class="col-md-6 col-xl">
                                <label class="form-label" for="hr-employee-emergency-contact-name">{{ __('hr.employees.attributes.emergency_contact_name') }}</label>
                                @if ($isView)
                                    <x-forms.view-field for="hr-employee-emergency-contact-name" :value="$fieldValue('emergency_contact_name')" />
                                @else
                                    <input id="hr-employee-emergency-contact-name" name="emergency_contact_name" type="text" class="form-control" value="{{ $fieldValue('emergency_contact_name') }}">
                                @endif
                                <div class="invalid-feedback" data-error-for="emergency_contact_name"></div>
                            </div>

                            <div class="col-md-6 col-xl">
                                <label class="form-label" for="hr-employee-emergency-contact-phone">{{ __('hr.employees.attributes.emergency_contact_phone') }}</label>
                                @if ($isView)
                                    <x-forms.view-field for="hr-employee-emergency-contact-phone" :value="$fieldValue('emergency_contact_phone')" />
                                @else
                                    <input id="hr-employee-emergency-contact-phone" name="emergency_contact_phone" type="text" class="form-control" value="{{ $fieldValue('emergency_contact_phone') }}">
                                @endif
                                <div class="invalid-feedback" data-error-for="emergency_contact_phone"></div>
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="hr-employee-address">{{ __('hr.employees.attributes.address') }}</label>
                                @if ($isView)
                                    <x-forms.view-field for="hr-employee-address" as="textarea" :value="$fieldValue('address')" rows="3" />
                                @else
                                    <textarea id="hr-employee-address" name="address" class="form-control" rows="3">{{ $fieldValue('address') }}</textarea>
                                @endif
                                <div class="invalid-feedback" data-error-for="address"></div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="pane-work-info" role="tabpanel" aria-labelledby="tab-work-info">
                        <div class="row g-3 align-items-start">
                            @foreach (['branch_doc_num', 'department_doc_num', 'section_doc_num', 'job_doc_num', 'employment_type_doc_num'] as $fieldName)
                                @php
                                    $option = $selectOption($fieldName) ?? [];
                                    $inputId = 'hr-employee-' . str_replace('_', '-', $fieldName);
                                @endphp
                                <div class="col-md-6 col-xl">
                                    @include('modules.hr.partials.inline-select2-field', [
                                        'isView' => $isView,
                                        'inputId' => $inputId,
                                        'fieldName' => $fieldName,
                                        'fieldLabel' => __('hr.employees.attributes.' . $fieldName),
                                        'placeholder' => __('hr.employees.placeholders.' . $fieldName),
                                        'selectedValue' => $selectValue($fieldName),
                                        'selectedText' => (string) ($option['text'] ?? ''),
                                        'dataUrl' => (string) ($option['url'] ?? ''),
                                        'canCreate' => (bool) ($option['can_create'] ?? false),
                                        'createUrl' => $option['create_url'] ?? null,
                                        'inlineUrl' => null,
                                        'required' => false,
                                    ])
                                </div>
                            @endforeach

                            @foreach (['hire_date', 'start_date', 'end_date'] as $fieldName)
                                @php $inputId = 'hr-employee-' . str_replace('_', '-', $fieldName); @endphp
                                <div class="col-md-6 col-xl-3">
                                    <label class="form-label" for="{{ $inputId }}">{{ __('hr.employees.attributes.' . $fieldName) }}</label>
                                    @if ($isView)
                                        <x-forms.view-field :for="$inputId" :value="$dateValue($fieldName)" />
                                    @else
                                        <input id="{{ $inputId }}" name="{{ $fieldName }}" type="text" class="form-control js-date-picker" value="{{ $dateValue($fieldName) }}" placeholder="{{ __('common.placeholders.select_date') }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}">
                                    @endif
                                    <div class="invalid-feedback" data-error-for="{{ $fieldName }}"></div>
                                </div>
                            @endforeach

                            <div class="col-md-6 col-xl">
                                <label class="form-label" for="hr-employee-work-email">{{ __('hr.employees.attributes.work_email') }}</label>
                                @if ($isView)
                                    <x-forms.view-field for="hr-employee-work-email" :value="$fieldValue('work_email')" />
                                @else
                                    <input id="hr-employee-work-email" name="work_email" type="email" class="form-control" value="{{ $fieldValue('work_email') }}">
                                @endif
                                <div class="invalid-feedback" data-error-for="work_email"></div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="pane-attendance-biometric" role="tabpanel" aria-labelledby="tab-attendance-biometric">
                        <div class="row g-3 align-items-start">
                            <div class="col-md-6 col-xl-4">
                                @php $attendancePolicyValue = (string) old('attendance_policy_type', $fieldValue('attendance_policy_type')); @endphp
                                @if ($isView)
                                    <x-forms.view-field for="hr-employee-attendance-policy-type" :label="__('hr.employees.attributes.attendance_policy_type')" :value="$attendancePolicyValue !== '' ? __('hr.employees.attendance_policies.' . $attendancePolicyValue) : null" />
                                @else
                                    <label class="form-label" for="hr-employee-attendance-policy-type">{{ __('hr.employees.attributes.attendance_policy_type') }}</label>
                                    <select id="hr-employee-attendance-policy-type" name="attendance_policy_type" class="form-select">
                                        <option value="">{{ __('common.placeholders.select') }}</option>
                                        @foreach ($attendancePolicyOptions as $policy)
                                            <option value="{{ $policy }}" @selected($attendancePolicyValue === $policy)>{{ __('hr.employees.attendance_policies.' . $policy) }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <div class="invalid-feedback" data-error-for="attendance_policy_type"></div>
                            </div>
                        </div>

                        @if (! $isView)
                            @php
                                $biometricRows = $employee && ! $isClone ? $employee->biometricMappings : collect();
                            @endphp
                            <hr class="my-3">
                            <div class="gap-2 mb-2 d-flex align-items-center justify-content-between">
                                <h6 class="mb-0">{{ __('hr.employees.biometric.title') }}</h6>
                                <button type="button" class="btn btn-falcon-default btn-sm js-hr-biometric-add">
                                    <span class="fas fa-plus me-1"></span>{{ __('hr.employees.actions.add_fingerprint') }}
                                </button>
                            </div>
                            <div class="table-responsive scrollbar">
                                <table class="table mb-0 align-middle table-sm hr-biometric-table">
                                    <thead class="bg-100 text-900">
                                        <tr>
                                            <th>{{ __('hr.employees.biometric.device') }}</th>
                                            <th>{{ __('hr.employees.biometric.code') }}</th>
                                            <th class="text-center">{{ __('hr.employees.biometric.active') }}</th>
                                            <th>{{ __('hr.employees.biometric.notes') }}</th>
                                            <th class="text-center">{{ __('common.fields.actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="js-hr-biometric-rows" data-next-index="{{ $biometricRows->count() }}">
                                        @forelse ($biometricRows as $biometricIndex => $mapping)
                                            @php
                                                $device = $mapping->device;
                                                $deviceText = $device ? trim(implode(' / ', array_filter([$device->name, $device->doc_num]))) : '';
                                            @endphp
                                            <tr class="js-hr-biometric-row" data-biometric-index="{{ $biometricIndex }}">
                                                <td>
                                                    <input type="hidden" name="biometric_mappings[{{ $biometricIndex }}][id]" value="{{ $mapping->id }}">
                                                    <input type="hidden" name="biometric_mappings[{{ $biometricIndex }}][_delete]" value="0" class="js-hr-biometric-delete-flag">
                                                    <div class="hr-select2-inline-control">
                                                        <select id="hr-biometric-device-{{ $biometricIndex }}" name="biometric_mappings[{{ $biometricIndex }}][device_doc_num]" class="form-select js-select2-ajax" data-url="{{ $biometricDeviceSelect['url'] }}" data-placeholder="{{ __('hr.employees.biometric.placeholder_device') }}" data-allow-clear="true">
                                                            @if ($device)
                                                                <option value="{{ $device->doc_num }}" selected>{{ $deviceText }}</option>
                                                            @endif
                                                        </select>
                                                        @if (($biometricDeviceSelect['can_create'] ?? false) && ($biometricDeviceSelect['create_url'] ?? null))
                                                            <a class="btn btn-falcon-default btn-sm" href="{{ $biometricDeviceSelect['create_url'] }}" target="_blank" rel="noopener" title="{{ __('hr.inline_lookup.add_new_device') }}" data-bs-title="{{ __('hr.inline_lookup.add_new_device') }}">
                                                                <span class="fas fa-plus"></span>
                                                                <span class="visually-hidden">{{ __('hr.inline_lookup.add_new_device') }}</span>
                                                            </a>
                                                        @endif
                                                    </div>
                                                    <div class="invalid-feedback d-block" data-error-for="biometric_mappings.{{ $biometricIndex }}.device_doc_num"></div>
                                                </td>
                                                <td>
                                                    <input name="biometric_mappings[{{ $biometricIndex }}][biometric_code]" type="text" class="form-control" value="{{ $mapping->biometric_code }}" placeholder="{{ __('hr.employees.biometric.code_placeholder') }}">
                                                    <div class="invalid-feedback d-block" data-error-for="biometric_mappings.{{ $biometricIndex }}.biometric_code"></div>
                                                </td>
                                                <td class="text-center">
                                                    <div class="form-check form-check-inline mb-0 justify-content-center">
                                                        <input id="hr-biometric-active-{{ $biometricIndex }}" name="biometric_mappings[{{ $biometricIndex }}][is_active]" type="checkbox" class="form-check-input" value="1" @checked($mapping->is_active)>
                                                    </div>
                                                </td>
                                                <td>
                                                    <input name="biometric_mappings[{{ $biometricIndex }}][notes]" type="text" class="form-control" value="{{ $mapping->notes }}" placeholder="{{ __('hr.employees.biometric.notes_placeholder') }}">
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="p-0 btn btn-link text-danger js-hr-biometric-remove" title="{{ __('common.actions.delete') }}">
                                                        <span class="fas fa-trash-alt"></span>
                                                    </button>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr class="js-hr-biometric-empty">
                                                <td colspan="5" class="py-4 text-center text-600">{{ __('hr.employees.biometric.empty') }}</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>

                            <template id="hr-biometric-row-template">
                                <tr class="js-hr-biometric-row" data-biometric-index="__INDEX__">
                                    <td>
                                        <input type="hidden" name="biometric_mappings[__INDEX__][_delete]" value="0" class="js-hr-biometric-delete-flag">
                                        <div class="hr-select2-inline-control">
                                            <select id="hr-biometric-device-__INDEX__" name="biometric_mappings[__INDEX__][device_doc_num]" class="form-select js-select2-ajax" data-url="{{ $biometricDeviceSelect['url'] }}" data-placeholder="{{ __('hr.employees.biometric.placeholder_device') }}" data-allow-clear="true"></select>
                                            @if (($biometricDeviceSelect['can_create'] ?? false) && ($biometricDeviceSelect['create_url'] ?? null))
                                                <a class="btn btn-falcon-default btn-sm" href="{{ $biometricDeviceSelect['create_url'] }}" target="_blank" rel="noopener" title="{{ __('hr.inline_lookup.add_new_device') }}" data-bs-title="{{ __('hr.inline_lookup.add_new_device') }}">
                                                    <span class="fas fa-plus"></span>
                                                    <span class="visually-hidden">{{ __('hr.inline_lookup.add_new_device') }}</span>
                                                </a>
                                            @endif
                                        </div>
                                        <div class="invalid-feedback d-block" data-error-for="biometric_mappings.__INDEX__.device_doc_num"></div>
                                    </td>
                                    <td>
                                        <input name="biometric_mappings[__INDEX__][biometric_code]" type="text" class="form-control" placeholder="{{ __('hr.employees.biometric.code_placeholder') }}">
                                        <div class="invalid-feedback d-block" data-error-for="biometric_mappings.__INDEX__.biometric_code"></div>
                                    </td>
                                    <td class="text-center">
                                        <div class="form-check form-check-inline mb-0 justify-content-center">
                                            <input name="biometric_mappings[__INDEX__][is_active]" type="checkbox" class="form-check-input" value="1" checked>
                                        </div>
                                    </td>
                                    <td>
                                        <input name="biometric_mappings[__INDEX__][notes]" type="text" class="form-control" placeholder="{{ __('hr.employees.biometric.notes_placeholder') }}">
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="p-0 btn btn-link text-danger js-hr-biometric-remove" title="{{ __('common.actions.delete') }}">
                                            <span class="fas fa-trash-alt"></span>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        @else
                            @php
                                $biometricRows = $employee ? $employee->biometricMappings : collect();
                            @endphp
                            <hr class="my-3">
                            <div class="table-responsive scrollbar">
                                <table class="table mb-0 align-middle table-sm">
                                    <thead class="bg-100 text-900">
                                        <tr>
                                            <th>{{ __('hr.employees.biometric.device') }}</th>
                                            <th>{{ __('hr.employees.biometric.code') }}</th>
                                            <th class="text-center">{{ __('hr.employees.biometric.active') }}</th>
                                            <th>{{ __('hr.employees.biometric.notes') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($biometricRows as $mapping)
                                            <tr>
                                                <td>{{ $mapping->device?->name ?? $mapping->device?->doc_num ?? '-' }}</td>
                                                <td>{{ $mapping->biometric_code }}</td>
                                                <td class="text-center">
                                                    @if ($mapping->is_active)
                                                        <span class="badge badge-soft-success">{{ __('common.status.active') }}</span>
                                                    @else
                                                        <span class="badge badge-soft-secondary">{{ __('common.status.inactive') }}</span>
                                                    @endif
                                                </td>
                                                <td>{{ $mapping->notes ?? '-' }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="4" class="py-4 text-center text-600">{{ __('hr.employees.biometric.empty') }}</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>

                    <div class="tab-pane fade" id="pane-salary-payment" role="tabpanel" aria-labelledby="tab-salary-payment">
                        <div class="row g-3 align-items-start">
                            <div class="col-md-6 col-xl">
                                @php $payBasisValue = (string) old('pay_basis', $fieldValue('pay_basis')); @endphp
                                @if ($isView)
                                    <x-forms.view-field for="hr-employee-pay-basis" :label="__('hr.employees.attributes.pay_basis')" :value="$payBasisValue !== '' ? __('hr.employees.pay_basis.' . $payBasisValue) : null" required />
                                @else
                                    <x-forms.label for="hr-employee-pay-basis" :label="__('hr.employees.attributes.pay_basis')" required />
                                    <select id="hr-employee-pay-basis" name="pay_basis" class="form-select js-hr-pay-basis" required>
                                        <option value="">{{ __('common.placeholders.select') }}</option>
                                        @foreach ($payBasisOptions as $basis)
                                            <option value="{{ $basis }}" @selected($payBasisValue === $basis)>{{ __('hr.employees.pay_basis.' . $basis) }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <div class="invalid-feedback" data-error-for="pay_basis"></div>
                            </div>

                            @php
                                $fieldName = 'payroll_currency_doc_num';
                                $option = $selectOption($fieldName) ?? [];
                            @endphp
                            <div class="col-md-6 col-xl">
                                @include('modules.hr.partials.inline-select2-field', [
                                    'isView' => $isView,
                                    'inputId' => 'hr-employee-payroll-currency-doc-num',
                                    'fieldName' => $fieldName,
                                    'fieldLabel' => __('hr.employees.attributes.' . $fieldName),
                                    'placeholder' => __('hr.employees.placeholders.' . $fieldName),
                                    'selectedValue' => $selectValue($fieldName),
                                    'selectedText' => (string) ($option['text'] ?? ''),
                                    'dataUrl' => (string) ($option['url'] ?? ''),
                                    'canCreate' => false,
                                    'inlineUrl' => null,
                                    'required' => true,
                                ])
                            </div>

                            <div class="col-md-6 col-xl">
                                <x-forms.label for="hr-employee-exchange-rate" :label="__('hr.employees.attributes.exchange_rate')" required />
                                @if ($isView)
                                    <x-forms.view-field for="hr-employee-exchange-rate" :value="$fieldValue('exchange_rate', $defaults['exchange_rate'] ?? '')" numeric dir="ltr" />
                                @else
                                    <x-forms.numeric-input id="hr-employee-exchange-rate" name="exchange_rate" :value="old('exchange_rate', $fieldValue('exchange_rate', $defaults['exchange_rate'] ?? ''))" :scale="6" min="0.000001" step="0.000001" class="text-center js-hr-employee-exchange-rate" required />
                                @endif
                                <div class="invalid-feedback" data-error-for="exchange_rate"></div>
                            </div>

                            @foreach ($payAmountFields as $basis => $fieldName)
                                @php
                                    $inputId = 'hr-employee-' . str_replace('_', '-', $fieldName);
                                    $payAmountScale = $fieldName === 'basic_salary' ? 2 : 4;
                                    $payAmountStep = $fieldName === 'basic_salary' ? '0.01' : '0.0001';
                                @endphp
                                <div class="col-md-6 col-xl js-hr-pay-amount-field" data-pay-basis="{{ $basis }}">
                                    <label class="form-label" for="{{ $inputId }}">{{ __('hr.employees.attributes.' . $fieldName) }}</label>
                                    @if ($isView)
                                        <x-forms.view-field :for="$inputId" :value="$fieldValue($fieldName)" numeric dir="ltr" />
                                    @else
                                        <x-forms.numeric-input :id="$inputId" :name="$fieldName" :value="$fieldValue($fieldName)" :scale="$payAmountScale" min="0" :step="$payAmountStep" class="text-center js-hr-pay-amount-input" />
                                    @endif
                                    <div class="invalid-feedback" data-error-for="{{ $fieldName }}"></div>
                                </div>
                            @endforeach

                            <div class="col-md-6 col-xl">
                                @php $paymentMethodValue = (string) old('payment_method', $fieldValue('payment_method')); @endphp
                                @if ($isView)
                                    <x-forms.view-field for="hr-employee-payment-method" :label="__('hr.employees.attributes.payment_method')" :value="$paymentMethodValue !== '' ? __('hr.employees.payment_methods.' . $paymentMethodValue) : null" />
                                @else
                                    <label class="form-label" for="hr-employee-payment-method">{{ __('hr.employees.attributes.payment_method') }}</label>
                                    <select id="hr-employee-payment-method" name="payment_method" class="form-select">
                                        <option value="">{{ __('common.placeholders.select') }}</option>
                                        @foreach ($paymentMethodOptions as $method)
                                            <option value="{{ $method }}" @selected($paymentMethodValue === $method)>{{ __('hr.employees.payment_methods.' . $method) }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <div class="invalid-feedback" data-error-for="payment_method"></div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="pane-documents" role="tabpanel" aria-labelledby="tab-documents">
                        @if ($isView)
                            <div class="table-responsive scrollbar">
                                <table class="table mb-0 align-middle table-sm js-hr-employee-documents-table">
                                    <thead class="bg-100 text-900">
                                        <tr>
                                            <th>{{ __('hr.employees.documents.attributes.document_type') }}</th>
                                            <th>{{ __('hr.employees.documents.attributes.original_name') }}</th>
                                            <th>{{ __('hr.employees.documents.attributes.file_label') }}</th>
                                            <th>{{ __('hr.employees.documents.attributes.issue_date') }}</th>
                                            <th>{{ __('common.fields.file_size') }}</th>
                                            <th>{{ __('hr.employees.documents.attributes.expires_at') }}</th>
                                            <th>{{ __('hr.employees.documents.attributes.alert_before_expiry_days') }}</th>
                                            <th>{{ __('hr.employees.documents.attributes.notes') }}</th>
                                            <th class="text-end">{{ __('common.fields.actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($documentRows as $document)
                                            @include('modules.hr.employees.partials.document-row', ['document' => $document, 'employee' => $employee, 'isView' => $isView])
                                        @empty
                                            <tr class="js-hr-employee-documents-empty">
                                                <td colspan="9" class="py-4 text-center text-600">{{ __('hr.employees.documents.messages.empty') }}</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="gap-2 mb-2 d-flex align-items-center justify-content-between">
                                <h6 class="mb-0">{{ __('hr.employees.documents.title') }}</h6>
                                <button type="button" class="btn btn-falcon-default btn-sm js-hr-document-add">
                                    <span class="fas fa-plus me-1"></span>{{ __('hr.employees.actions.add_document') }}
                                </button>
                            </div>
                            <div class="table-responsive scrollbar">
                                <table class="table mb-0 align-middle table-sm hr-document-table">
                                    <thead class="bg-100 text-900">
                                        <tr>
                                            <th>{{ __('hr.employees.documents.attributes.document_type_doc_num') }}</th>
                                            <th>{{ __('hr.employees.documents.attributes.archive_file_doc_num') }}</th>
                                            <th>{{ __('hr.employees.documents.attributes.file_label') }}</th>
                                            <th>{{ __('hr.employees.documents.attributes.issue_date') }}</th>
                                            <th>{{ __('hr.employees.documents.attributes.expires_at') }}</th>
                                            <th>{{ __('hr.employees.documents.attributes.alert_before_expiry_days') }}</th>
                                            <th>{{ __('hr.employees.documents.attributes.notes') }}</th>
                                            <th class="text-center">{{ __('common.fields.actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="js-hr-document-rows" data-next-index="{{ $documentRows->count() }}">
                                        @forelse ($documentRows as $documentIndex => $document)
                                            @php
                                                $documentType = $document->documentType;
                                                $documentTypeText = $documentType ? trim(implode(' / ', array_filter([$documentType->name, $documentType->doc_num]))) : '';
                                                $archiveFile = $document->archiveFile;
                                            @endphp
                                            <tr class="js-hr-document-row" data-document-index="{{ $documentIndex }}">
                                                <td>
                                                    <input type="hidden" name="documents[{{ $documentIndex }}][id]" value="{{ $document->id }}">
                                                    <input type="hidden" name="documents[{{ $documentIndex }}][_delete]" value="0" class="js-hr-document-delete-flag">
                                                    <input type="hidden" name="documents[{{ $documentIndex }}][document_number_text]" value="{{ $document->document_number_text }}">
                                                    <div class="hr-select2-inline-control">
                                                        <select id="hr-document-type-{{ $documentIndex }}" name="documents[{{ $documentIndex }}][document_type_doc_num]" class="form-select js-select2-ajax" data-url="{{ $documentTypeSelect['url'] }}" data-placeholder="{{ __('hr.employees.documents.attributes.document_type_doc_num') }}" data-allow-clear="true">
                                                            @if ($documentType)
                                                                <option value="{{ $documentType->doc_num }}" selected>{{ $documentTypeText }}</option>
                                                            @endif
                                                        </select>
                                                        @if (($documentTypeSelect['can_create'] ?? false) && ($documentTypeSelect['create_url'] ?? null))
                                                            <a class="btn btn-falcon-default btn-sm" href="{{ $documentTypeSelect['create_url'] }}" target="_blank" rel="noopener" title="{{ __('hr.inline_lookup.add_new') }}" data-bs-title="{{ __('hr.inline_lookup.add_new') }}">
                                                                <span class="fas fa-plus"></span>
                                                                <span class="visually-hidden">{{ __('hr.inline_lookup.add_new') }}</span>
                                                            </a>
                                                        @endif
                                                    </div>
                                                    <div class="invalid-feedback d-block" data-error-for="documents.{{ $documentIndex }}.document_type_doc_num"></div>
                                                </td>
                                                <td>
                                                    <div class="input-group hr-document-file-control">
                                                        <input id="hr-document-file-{{ $documentIndex }}" name="documents[{{ $documentIndex }}][archive_file_doc_num]" type="hidden" value="{{ $archiveFile?->doc_num ?? '' }}" class="js-hr-document-file-input">
                                                        <input type="text" class="form-control js-hr-document-file-display" value="{{ $archiveFile?->original_name ?? $document->original_name }}" readonly>
                                                        <button type="button"
                                                            class="btn btn-falcon-default js-hr-document-file-picker"
                                                            data-file-picker
                                                            data-picker-accept="document"
                                                            data-picker-title="{{ __('hr.employees.actions.select_document_file') }}"
                                                            data-picker-target-input="#hr-document-file-{{ $documentIndex }}"
                                                            data-picker-allow-upload="{{ auth()->user()?->can('file_manager.upload') ? 'true' : 'false' }}"
                                                            data-picker-allow-create-folder="{{ auth()->user()?->can('file_manager.folders.create') ? 'true' : 'false' }}">
                                                            <span class="fas fa-paperclip"></span>
                                                        </button>
                                                    </div>
                                                    <div class="invalid-feedback d-block" data-error-for="documents.{{ $documentIndex }}.archive_file_doc_num"></div>
                                                </td>
                                                <td>
                                                    <input name="documents[{{ $documentIndex }}][file_label]" type="text" class="form-control" value="{{ $document->file_label }}">
                                                    <div class="invalid-feedback d-block" data-error-for="documents.{{ $documentIndex }}.file_label"></div>
                                                </td>
                                                <td>
                                                    <input name="documents[{{ $documentIndex }}][issue_date]" type="text" class="form-control js-date-picker" value="{{ $documentDateValue($document, 'issue_date') }}" placeholder="{{ __('common.placeholders.select_date') }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}">
                                                    <div class="invalid-feedback d-block" data-error-for="documents.{{ $documentIndex }}.issue_date"></div>
                                                </td>
                                                <td>
                                                    <input name="documents[{{ $documentIndex }}][expires_at]" type="text" class="form-control js-date-picker" value="{{ $documentDateValue($document, 'expires_at') }}" placeholder="{{ __('common.placeholders.select_date') }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-date-min="{{ $documentDateValue($document, 'issue_date') ? '' : '' }}">
                                                    <div class="invalid-feedback d-block" data-error-for="documents.{{ $documentIndex }}.expires_at"></div>
                                                </td>
                                                <td>
                                                    <x-forms.numeric-input
                                                        :name="'documents['.$documentIndex.'][alert_before_expiry_days]'"
                                                        :value="$document->alert_before_expiry_days"
                                                        :scale="0"
                                                        min="0"
                                                        max="3650"
                                                        step="1"
                                                        class="text-center"
                                                    />
                                                    <div class="invalid-feedback d-block" data-error-for="documents.{{ $documentIndex }}.alert_before_expiry_days"></div>
                                                </td>
                                                <td>
                                                    <input name="documents[{{ $documentIndex }}][notes]" type="text" class="form-control" value="{{ $document->notes }}">
                                                    <div class="invalid-feedback d-block" data-error-for="documents.{{ $documentIndex }}.notes"></div>
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="p-0 btn btn-link text-danger js-hr-document-remove" title="{{ __('common.actions.delete') }}">
                                                        <span class="fas fa-trash-alt"></span>
                                                    </button>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr class="js-hr-document-empty">
                                                <td colspan="8" class="py-4 text-center text-600">{{ __('hr.employees.documents.messages.empty') }}</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>

                            <template id="hr-document-row-template">
                                <tr class="js-hr-document-row" data-document-index="__INDEX__">
                                    <td>
                                        <input type="hidden" name="documents[__INDEX__][_delete]" value="0" class="js-hr-document-delete-flag">
                                        <div class="hr-select2-inline-control">
                                            <select id="hr-document-type-__INDEX__" name="documents[__INDEX__][document_type_doc_num]" class="form-select js-select2-ajax" data-url="{{ $documentTypeSelect['url'] }}" data-placeholder="{{ __('hr.employees.documents.attributes.document_type_doc_num') }}" data-allow-clear="true"></select>
                                            @if (($documentTypeSelect['can_create'] ?? false) && ($documentTypeSelect['create_url'] ?? null))
                                                <a class="btn btn-falcon-default btn-sm" href="{{ $documentTypeSelect['create_url'] }}" target="_blank" rel="noopener" title="{{ __('hr.inline_lookup.add_new') }}" data-bs-title="{{ __('hr.inline_lookup.add_new') }}">
                                                    <span class="fas fa-plus"></span>
                                                    <span class="visually-hidden">{{ __('hr.inline_lookup.add_new') }}</span>
                                                </a>
                                            @endif
                                        </div>
                                        <div class="invalid-feedback d-block" data-error-for="documents.__INDEX__.document_type_doc_num"></div>
                                    </td>
                                    <td>
                                        <div class="input-group hr-document-file-control">
                                            <input id="hr-document-file-__INDEX__" name="documents[__INDEX__][archive_file_doc_num]" type="hidden" class="js-hr-document-file-input">
                                            <input type="text" class="form-control js-hr-document-file-display" value="" readonly>
                                            <button type="button"
                                                class="btn btn-falcon-default js-hr-document-file-picker"
                                                data-file-picker
                                                data-picker-accept="document"
                                                data-picker-title="{{ __('hr.employees.actions.select_document_file') }}"
                                                data-picker-target-input="#hr-document-file-__INDEX__"
                                                data-picker-allow-upload="{{ auth()->user()?->can('file_manager.upload') ? 'true' : 'false' }}"
                                                data-picker-allow-create-folder="{{ auth()->user()?->can('file_manager.folders.create') ? 'true' : 'false' }}">
                                                <span class="fas fa-paperclip"></span>
                                            </button>
                                        </div>
                                        <div class="invalid-feedback d-block" data-error-for="documents.__INDEX__.archive_file_doc_num"></div>
                                    </td>
                                    <td>
                                        <input name="documents[__INDEX__][file_label]" type="text" class="form-control">
                                        <div class="invalid-feedback d-block" data-error-for="documents.__INDEX__.file_label"></div>
                                    </td>
                                    <td>
                                        <input name="documents[__INDEX__][issue_date]" type="text" class="form-control js-date-picker" placeholder="{{ __('common.placeholders.select_date') }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}">
                                        <div class="invalid-feedback d-block" data-error-for="documents.__INDEX__.issue_date"></div>
                                    </td>
                                    <td>
                                        <input name="documents[__INDEX__][expires_at]" type="text" class="form-control js-date-picker" placeholder="{{ __('common.placeholders.select_date') }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}">
                                        <div class="invalid-feedback d-block" data-error-for="documents.__INDEX__.expires_at"></div>
                                    </td>
                                    <td>
                                        <x-forms.numeric-input
                                            name="documents[__INDEX__][alert_before_expiry_days]"
                                            :scale="0"
                                            min="0"
                                            max="3650"
                                            step="1"
                                            class="text-center"
                                        />
                                        <div class="invalid-feedback d-block" data-error-for="documents.__INDEX__.alert_before_expiry_days"></div>
                                    </td>
                                    <td>
                                        <input name="documents[__INDEX__][notes]" type="text" class="form-control">
                                        <div class="invalid-feedback d-block" data-error-for="documents.__INDEX__.notes"></div>
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="p-0 btn btn-link text-danger js-hr-document-remove" title="{{ __('common.actions.delete') }}">
                                            <span class="fas fa-trash-alt"></span>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        @endif
                    </div>

                    <div class="tab-pane fade" id="pane-notes" role="tabpanel" aria-labelledby="tab-notes">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label" for="hr-employee-notes">{{ __('hr.employees.attributes.notes') }}</label>
                                @if ($isView)
                                    <x-forms.view-field for="hr-employee-notes" as="textarea" :value="$fieldValue('notes')" rows="5" />
                                @else
                                    <textarea id="hr-employee-notes" name="notes" class="form-control" rows="5">{{ $fieldValue('notes') }}</textarea>
                                @endif
                                <div class="invalid-feedback" data-error-for="notes"></div>
                            </div>
                        </div>
                    </div>
                </div>

                @if ($isEdit || $isView)
                    <x-audit-fields-row
                        :metadata="$metadata"
                        :show-deleted="$isView && ($employee?->trashed() ?? false)"
                        :show-restored="$isView && ! ($employee?->trashed() ?? false) && (($employee?->restored_at ?? null) || ($employee?->restored_by ?? null))"
                    />
                @endif
            </div>

            @include('modules.hr.employees.partials.form-footer')
        </div>
    </form>

    <x-file-picker-modal />
@endsection

@push('scripts')
    @php
        $hrMessages = [
            'noChanges' => __('common.messages.no_changes'),
            'validationSummary' => __('common.messages.validation_failed'),
            'unexpectedError' => __('auth.ajax.unexpected_error'),
            'close' => __('auth.alerts.close'),
            'yes' => __('common.actions.yes'),
            'no' => __('common.actions.no'),
            'loading' => __('common.messages.loading'),
            'deleteConfirmTitle' => __('hr.employees.messages.delete_confirm_title'),
            'deleteConfirmText' => __('hr.messages.delete_confirm_text'),
            'deleteConfirmYes' => __('hr.messages.delete_confirm_yes'),
            'restore' => __('hr.trash.restore'),
            'restoreConfirmTitle' => __('hr.trash.restore_confirm_title'),
            'restoreConfirmText' => __('hr.trash.restore_confirm_text'),
            'restoreConfirmYes' => __('hr.trash.restore_confirm_yes'),
            'documentDeleteConfirmTitle' => __('hr.employees.documents.messages.delete_confirm_title'),
            'documentDeleteConfirmText' => __('hr.employees.documents.messages.delete_confirm_text'),
            'documentDeleteConfirmYes' => __('hr.messages.delete_confirm_yes'),
            'emptyDocuments' => __('hr.employees.documents.messages.empty'),
            'documentFileRequired' => __('hr.employees.documents.validation.file_required'),
            'emptyBiometric' => __('hr.employees.biometric.empty'),
            'noPhotoSelected' => __('hr.employees.photo.no_file_selected'),
        ];
    @endphp
    <script>
        window.hrEmployeesMessages = @json($hrMessages);
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Core/file-picker.js') }}"></script>
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/HR/hr-employees.js') }}"></script>
@endpush

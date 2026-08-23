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
    $cloneBlankFields = ['employee_code', 'national_id', 'email', 'work_email'];
    $dateFields = ['birth_date', 'hire_date', 'start_date', 'end_date', 'national_id_expiry_date', 'passport_expiry_date', 'probation_end_date', 'contract_start_date', 'contract_end_date', 'termination_date', 'insurance_start_date', 'insurance_end_date', 'tax_start_date', 'tax_end_date'];
    $select2FieldNames = array_keys($selectFields);
    $booleanFields = ['attendance_tracking_enabled', 'overtime_enabled'];
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
    $statutoryStatusOptions = ['subject', 'not_subject', 'suspended', 'ended'];
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
        'employee_code',
        'full_name',
        'person_type',
        'status',
        'gender',
        'birth_date',
        'marital_status',
        'national_id',
        'work_email',
        'email',
        'personal_email',
        'phone',
        'mobile',
        'alternate_phone',
        'address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'hire_date',
        'start_date',
        'end_date',
        'contract_start_date',
        'contract_end_date',
        'probation_end_date',
        'attendance_tracking_enabled',
        'attendance_policy_type',
        'allow_late_minutes',
        'allow_early_leave_minutes',
        'overtime_enabled',
        'pay_basis',
        'exchange_rate',
        'basic_salary',
        'weekly_wage',
        'daily_wage',
        'hourly_wage',
        'shift_wage',
        'piece_rate',
        'payment_method',
        'insurance_status',
        'social_insurance_number',
        'insurance_start_date',
        'insurance_end_date',
        'insurance_contribution_wage',
        'insurance_non_coverage_reason',
        'insurance_notes',
        'tax_status',
        'tax_start_date',
        'tax_end_date',
        'tax_special_treatment_reason',
        'tax_notes',
        'notes',
        ...$select2FieldNames,
    ];
    $originalEmployeeData = [];
    foreach ($trackedFields as $trackedField) {
        $originalEmployeeData[$trackedField] = match (true) {
            $trackedField === 'doc_number' => $canControlDocumentNumber ? (($isEdit || $isView) ? $employee?->doc_number : '') : null,
            in_array($trackedField, $dateFields, true) => $dateValue($trackedField),
            in_array($trackedField, $select2FieldNames, true) => $selectValue($trackedField),
            in_array($trackedField, $booleanFields, true) => $fieldValue($trackedField) ? '1' : '0',
            default => (string) $fieldValue($trackedField),
        };
    }
    $documentRows = ($employee && ! $isClone) ? $employee->documents : collect();
@endphp

@section('title', $title)

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
                    <li class="nav-item"><button class="nav-link" id="tab-insurance-taxes" data-bs-toggle="tab" data-bs-target="#pane-insurance-taxes" type="button" role="tab">{{ __('hr.employees.sections.insurance_taxes') }}</button></li>
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

                            <div class="col-md-4 col-xl-3">
                                <label class="form-label" for="hr-employee-employee-code">{{ __('hr.employees.attributes.employee_code') }}</label>
                                @if ($isView)
                                    <x-forms.view-field for="hr-employee-employee-code" :value="$fieldValue('employee_code')" />
                                @else
                                    <input id="hr-employee-employee-code" name="employee_code" type="text" class="form-control" value="{{ $fieldValue('employee_code') }}">
                                @endif
                                <div class="invalid-feedback" data-error-for="employee_code"></div>
                            </div>

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

                            @php
                                $fieldName = 'nationality_doc_num';
                                $option = $selectOption($fieldName) ?? [];
                            @endphp
                            <div class="col-md-4 col-xl-3">
                                @include('modules.hr.partials.inline-select2-field', [
                                    'isView' => $isView,
                                    'inputId' => 'hr-employee-nationality-doc-num',
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

                                    @php
                                        $signatureFile = $employee?->signatureArchiveFile;
                                        $signatureValue = old('signature_archive_file_doc_num', $signatureFile?->doc_num ?? '');
                                    @endphp
                                    <x-forms.archive-image-picker
                                        class="col-md-6 product-image-field"
                                        field-id="hr-employee-signature-archive-file-doc-num"
                                        field-name="signature_archive_file_doc_num"
                                        :value="$signatureValue"
                                        :file="$signatureFile"
                                        :is-view="$isView"
                                        :label="__('hr.employees.attributes.signature_archive_file_doc_num')"
                                        :existing-label="__('hr.employees.signature.existing_file')"
                                        :empty-label="__('hr.employees.signature.no_file_selected')"
                                        :help-text="__('hr.employees.signature.help')"
                                        :select-title="__('hr.employees.signature.select_from_file_manager')"
                                        :select-label="__('hr.employees.actions.select_signature')"
                                        :remove-label="__('hr.employees.actions.remove_signature')"
                                        collection="employee_signature"
                                        icon="fas fa-signature" />
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

                            <div class="col-md-4 col-xl-3">
                                <label class="form-label" for="hr-employee-personal-email">{{ __('hr.employees.attributes.personal_email') }}</label>
                                @if ($isView)
                                    <x-forms.view-field for="hr-employee-personal-email" :value="$fieldValue('personal_email')" />
                                @else
                                    <input id="hr-employee-personal-email" name="personal_email" type="email" class="form-control" value="{{ $fieldValue('personal_email') }}">
                                @endif
                                <div class="invalid-feedback" data-error-for="personal_email"></div>
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
                            @foreach (['branch_doc_num', 'department_doc_num', 'section_doc_num', 'job_doc_num', 'employment_type_doc_num', 'hiring_status_doc_num'] as $fieldName)
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
                                        'dependsOn' => $fieldName === 'section_doc_num' ? '#hr-employee-department-doc-num' : null,
                                        'dependentParam' => $fieldName === 'section_doc_num' ? 'department_doc_num' : null,
                                        'dependentResultField' => $fieldName === 'section_doc_num' ? 'department_doc_num' : null,
                                        'disableWhenDependencyEmpty' => $fieldName === 'section_doc_num',
                                    ])
                                </div>
                            @endforeach

                            {{-- @foreach (['hire_date', 'start_date', 'end_date', 'contract_start_date', 'contract_end_date', 'probation_end_date'] as $fieldName) --}}
                            @foreach (['hire_date', 'contract_start_date', 'contract_end_date', 'probation_end_date'] as $fieldName)
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
                            @php $attendanceTrackingEnabled = (bool) old('attendance_tracking_enabled', $employee?->attendance_tracking_enabled ?? false); @endphp
                            <div class="col-md-6 col-xl-3">
                                <label class="form-label d-block" for="hr-employee-attendance-tracking-enabled">{{ __('hr.employees.attributes.attendance_tracking_enabled') }}</label>
                                @if ($isView)
                                    <x-forms.view-field for="hr-employee-attendance-tracking-enabled" :value="__('hr.employees.booleans.' . ($attendanceTrackingEnabled ? 'yes' : 'no'))" />
                                @else
                                    <input type="hidden" name="attendance_tracking_enabled" value="0">
                                    <div class="form-check form-switch pt-2">
                                        <input id="hr-employee-attendance-tracking-enabled" name="attendance_tracking_enabled" type="checkbox" class="form-check-input" value="1" @checked($attendanceTrackingEnabled)>
                                    </div>
                                @endif
                                <div class="invalid-feedback" data-error-for="attendance_tracking_enabled"></div>
                            </div>

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

                            @php
                                $fieldName = 'default_shift_doc_num';
                                $option = $selectOption($fieldName) ?? [];
                            @endphp
                            <div class="col-md-6 col-xl-3">
                                @include('modules.hr.partials.inline-select2-field', [
                                    'isView' => $isView,
                                    'inputId' => 'hr-employee-default-shift-doc-num',
                                    'fieldName' => $fieldName,
                                    'fieldLabel' => __('hr.employees.attributes.' . $fieldName),
                                    'placeholder' => __('hr.employees.placeholders.' . $fieldName),
                                    'selectedValue' => $selectValue($fieldName),
                                    'selectedText' => (string) ($option['text'] ?? ''),
                                    'dataUrl' => (string) ($option['url'] ?? ''),
                                    'canCreate' => false,
                                    'inlineUrl' => null,
                                    'required' => false,
                                ])
                            </div>

                            @foreach (['allow_late_minutes', 'allow_early_leave_minutes'] as $fieldName)
                                @php $inputId = 'hr-employee-' . str_replace('_', '-', $fieldName); @endphp
                                <div class="col-md-6 col-xl-3">
                                    <label class="form-label" for="{{ $inputId }}">{{ __('hr.employees.attributes.' . $fieldName) }}</label>
                                    @if ($isView)
                                        <x-forms.view-field :for="$inputId" :value="$fieldValue($fieldName)" numeric dir="ltr" />
                                    @else
                                        <input id="{{ $inputId }}" name="{{ $fieldName }}" type="number" min="0" step="1" class="form-control text-center" value="{{ $fieldValue($fieldName, 0) }}">
                                    @endif
                                    <div class="invalid-feedback" data-error-for="{{ $fieldName }}"></div>
                                </div>
                            @endforeach

                            @php $overtimeEnabled = (bool) old('overtime_enabled', $employee?->overtime_enabled ?? false); @endphp
                            <div class="col-md-6 col-xl-3">
                                <label class="form-label d-block" for="hr-employee-overtime-enabled">{{ __('hr.employees.attributes.overtime_enabled') }}</label>
                                @if ($isView)
                                    <x-forms.view-field for="hr-employee-overtime-enabled" :value="__('hr.employees.booleans.' . ($overtimeEnabled ? 'yes' : 'no'))" />
                                @else
                                    <input type="hidden" name="overtime_enabled" value="0">
                                    <div class="form-check form-switch pt-2">
                                        <input id="hr-employee-overtime-enabled" name="overtime_enabled" type="checkbox" class="form-check-input" value="1" @checked($overtimeEnabled)>
                                    </div>
                                @endif
                                <div class="invalid-feedback" data-error-for="overtime_enabled"></div>
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
                            <div class="vstack gap-3 js-hr-biometric-rows" data-next-index="{{ $biometricRows->count() }}">
                                @forelse ($biometricRows as $biometricIndex => $mapping)
                                    @include('modules.hr.employees.partials.biometric-mapping-card', compact('mapping', 'biometricIndex', 'biometricDeviceSelect', 'isView'))
                                @empty
                                    <div class="border rounded-3 py-4 text-center text-600 js-hr-biometric-empty">{{ __('hr.employees.biometric.empty') }}</div>
                                @endforelse
                            </div>

                            <template id="hr-biometric-row-template">
                                @include('modules.hr.employees.partials.biometric-mapping-card', [
                                    'mapping' => null,
                                    'biometricIndex' => '__INDEX__',
                                    'biometricDeviceSelect' => $biometricDeviceSelect,
                                    'isView' => false,
                                ])
                            </template>
                        @else
                            @php
                                $biometricRows = $employee ? $employee->biometricMappings : collect();
                            @endphp
                            <hr class="my-3">
                            <div class="vstack gap-3">
                                @forelse ($biometricRows as $biometricIndex => $mapping)
                                    @include('modules.hr.employees.partials.biometric-mapping-card', compact('mapping', 'biometricIndex', 'biometricDeviceSelect', 'isView'))
                                @empty
                                    <div class="border rounded-3 py-4 text-center text-600">{{ __('hr.employees.biometric.empty') }}</div>
                                @endforelse
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

                            @php
                                $fieldName = 'allowance_doc_num';
                                $option = $selectOption($fieldName) ?? [];
                            @endphp
                            <div class="col-md-6 col-xl">
                                @include('modules.hr.partials.inline-select2-field', [
                                    'isView' => $isView,
                                    'inputId' => 'hr-employee-allowance-doc-num',
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
                        </div>
                    </div>

                    <div class="tab-pane fade" id="pane-insurance-taxes" role="tabpanel" aria-labelledby="tab-insurance-taxes">
                        <div class="row g-4">
                            <div class="col-12 col-xl-6">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="d-flex align-items-start justify-content-between gap-2 mb-3">
                                        <div>
                                            <h6 class="mb-1">{{ __('hr.employees.statutory.social_insurance') }}</h6>
                                            <small class="text-muted">{{ __('hr.employees.statutory.preview_date', ['date' => $statutoryPreviewDate]) }}</small>
                                        </div>
                                        @if ($socialInsurancePolicyEditUrl)
                                            <a class="btn btn-sm btn-falcon-default" href="{{ $socialInsurancePolicyEditUrl }}">
                                                <span class="fas fa-external-link-alt me-1"></span>{{ __('hr.employees.statutory.open_policy') }}
                                            </a>
                                        @endif
                                    </div>

                                    @if ($socialInsurancePolicy)
                                        @php
                                            $socialCombinedRate = bcadd((string) $socialInsurancePolicy->employee_contribution_rate, (string) $socialInsurancePolicy->employer_contribution_rate, 4);
                                        @endphp
                                        <div class="rounded-3 bg-body-tertiary p-3 mb-3">
                                            <div class="fw-semibold mb-2">{{ __('hr.employees.statutory.applicable_policy') }}</div>
                                            <div class="row g-2 small">
                                                <div class="col-12"><span class="text-muted">{{ __('hr.employees.statutory.policy_name') }}:</span> <strong>{{ $socialInsurancePolicy->name }}</strong></div>
                                                <div class="col-12"><span class="text-muted">{{ __('hr.employees.statutory.effective_period') }}:</span> {{ $dateFormatService->formatDate($socialInsurancePolicy->effective_from, '') }} — {{ $socialInsurancePolicy->effective_to ? $dateFormatService->formatDate($socialInsurancePolicy->effective_to, '') : '∞' }}</div>
                                                <div class="col-6"><span class="text-muted">{{ __('hr.employees.statutory.minimum_wage') }}:</span> {{ $socialInsurancePolicy->minimum_contribution_wage ?? '—' }}</div>
                                                <div class="col-6"><span class="text-muted">{{ __('hr.employees.statutory.maximum_wage') }}:</span> {{ $socialInsurancePolicy->maximum_contribution_wage ?? '—' }}</div>
                                                <div class="col-4"><span class="text-muted">{{ __('hr.employees.statutory.employee_rate') }}:</span> {{ $socialInsurancePolicy->employee_contribution_rate }}%</div>
                                                <div class="col-4"><span class="text-muted">{{ __('hr.employees.statutory.employer_rate') }}:</span> {{ $socialInsurancePolicy->employer_contribution_rate }}%</div>
                                                <div class="col-4"><span class="text-muted">{{ __('hr.employees.statutory.combined_rate') }}:</span> {{ $socialCombinedRate }}%</div>
                                            </div>
                                            <button class="btn btn-link btn-sm px-0 mt-2" type="button" data-bs-toggle="collapse" data-bs-target="#employee-insurance-policy-components" aria-expanded="false">
                                                {{ __('hr.employees.statutory.components') }} <span class="fas fa-chevron-down ms-1"></span>
                                            </button>
                                            <div class="collapse" id="employee-insurance-policy-components">
                                                <div class="table-responsive">
                                                    <table class="table table-sm mb-0 align-middle">
                                                        <thead>
                                                            <tr>
                                                                <th>{{ __('hr.employees.statutory.component') }}</th>
                                                                <th class="text-center">{{ __('hr.employees.statutory.employee_rate') }}</th>
                                                                <th class="text-center">{{ __('hr.employees.statutory.employer_rate') }}</th>
                                                                <th class="text-center">{{ __('hr.employees.statutory.combined_rate') }}</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            @foreach ($socialInsurancePolicy->components->where('is_active', true) as $component)
                                                                <tr>
                                                                    <td>{{ $component->name }}</td>
                                                                    <td class="text-center">{{ $component->employee_rate }}%</td>
                                                                    <td class="text-center">{{ $component->employer_rate }}%</td>
                                                                    <td class="text-center">{{ bcadd((string) $component->employee_rate, (string) $component->employer_rate, 4) }}%</td>
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    @else
                                        <div class="alert alert-warning py-2 mb-3">{{ __('hr.employees.statutory.no_applicable_policy', ['date' => $statutoryPreviewDate]) }}</div>
                                    @endif
                                    <div class="row g-3">
                                        @php $insuranceStatus = (string) old('insurance_status', $fieldValue('insurance_status', 'not_subject')); @endphp
                                        <div class="col-md-6">
                                            @if ($isView)
                                                <x-forms.view-field for="hr-employee-insurance-status" :label="__('hr.employees.attributes.insurance_status')" :value="__('hr.employees.statutory_statuses.'.$insuranceStatus)" required />
                                            @else
                                                <x-forms.label for="hr-employee-insurance-status" :label="__('hr.employees.attributes.insurance_status')" required />
                                                <select id="hr-employee-insurance-status" name="insurance_status" class="form-select js-hr-statutory-status" data-statutory-target="insurance" required>
                                                    @foreach ($statutoryStatusOptions as $status)
                                                        <option value="{{ $status }}" @selected($insuranceStatus === $status)>{{ __('hr.employees.statutory_statuses.'.$status) }}</option>
                                                    @endforeach
                                                </select>
                                            @endif
                                            <div class="invalid-feedback" data-error-for="insurance_status"></div>
                                        </div>

                                        <div class="col-md-6" data-statutory-details="insurance">
                                            <label class="form-label" for="hr-employee-social-insurance-number">{{ __('hr.employees.attributes.social_insurance_number') }}</label>
                                            @if ($isView)
                                                <x-forms.view-field for="hr-employee-social-insurance-number" :value="$fieldValue('social_insurance_number')" dir="ltr" />
                                            @else
                                                <input id="hr-employee-social-insurance-number" name="social_insurance_number" type="text" class="form-control" value="{{ $fieldValue('social_insurance_number') }}" maxlength="60" dir="ltr">
                                            @endif
                                            <div class="invalid-feedback" data-error-for="social_insurance_number"></div>
                                        </div>

                                        @php
                                            $fieldName = 'insurance_office_doc_num';
                                            $option = $selectOption($fieldName) ?? [];
                                        @endphp
                                        <div class="col-md-6" data-statutory-details="insurance">
                                            @include('modules.hr.partials.inline-select2-field', [
                                                'isView' => $isView,
                                                'inputId' => 'hr-employee-insurance-office-doc-num',
                                                'fieldName' => $fieldName,
                                                'fieldLabel' => __('hr.employees.attributes.'.$fieldName),
                                                'placeholder' => __('hr.employees.placeholders.'.$fieldName),
                                                'selectedValue' => $selectValue($fieldName),
                                                'selectedText' => (string) ($option['text'] ?? ''),
                                                'dataUrl' => (string) ($option['url'] ?? ''),
                                                'canCreate' => (bool) ($option['can_create'] ?? false),
                                                'createUrl' => $option['create_url'] ?? null,
                                                'inlineUrl' => null,
                                            ])
                                        </div>

                                        @foreach (['insurance_start_date', 'insurance_end_date'] as $fieldName)
                                            @php $inputId = 'hr-employee-'.str_replace('_', '-', $fieldName); @endphp
                                            <div class="col-md-6" data-statutory-details="insurance">
                                                <label class="form-label" for="{{ $inputId }}">{{ __('hr.employees.attributes.'.$fieldName) }}</label>
                                                @if ($isView)
                                                    <x-forms.view-field :for="$inputId" :value="$dateValue($fieldName)" />
                                                @else
                                                    <input id="{{ $inputId }}" name="{{ $fieldName }}" type="text" class="form-control js-date-picker" value="{{ $dateValue($fieldName) }}" placeholder="{{ __('common.placeholders.select_date') }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}">
                                                @endif
                                                <div class="invalid-feedback" data-error-for="{{ $fieldName }}"></div>
                                            </div>
                                        @endforeach

                                        <div class="col-md-6" data-statutory-details="insurance">
                                            <label class="form-label" for="hr-employee-insurance-contribution-wage">{{ __('hr.employees.attributes.insurance_contribution_wage') }}</label>
                                            @if ($isView)
                                                <x-forms.view-field for="hr-employee-insurance-contribution-wage" :value="$fieldValue('insurance_contribution_wage')" numeric dir="ltr" />
                                            @else
                                                <x-forms.numeric-input id="hr-employee-insurance-contribution-wage" name="insurance_contribution_wage" :value="$fieldValue('insurance_contribution_wage')" :scale="2" min="0" step="0.01" class="text-center" />
                                            @endif
                                            <div class="invalid-feedback" data-error-for="insurance_contribution_wage"></div>
                                        </div>

                                        <div class="col-12" data-statutory-reason="insurance">
                                            <label class="form-label" for="hr-employee-insurance-non-coverage-reason">{{ __('hr.employees.attributes.insurance_non_coverage_reason') }}</label>
                                            @if ($isView)
                                                <x-forms.view-field for="hr-employee-insurance-non-coverage-reason" :value="$fieldValue('insurance_non_coverage_reason')" />
                                            @else
                                                <input id="hr-employee-insurance-non-coverage-reason" name="insurance_non_coverage_reason" type="text" class="form-control" value="{{ $fieldValue('insurance_non_coverage_reason') }}">
                                            @endif
                                            <div class="invalid-feedback" data-error-for="insurance_non_coverage_reason"></div>
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label" for="hr-employee-insurance-notes">{{ __('hr.employees.attributes.insurance_notes') }}</label>
                                            @if ($isView)
                                                <x-forms.view-field for="hr-employee-insurance-notes" as="textarea" :value="$fieldValue('insurance_notes')" rows="3" />
                                            @else
                                                <textarea id="hr-employee-insurance-notes" name="insurance_notes" class="form-control" rows="3">{{ $fieldValue('insurance_notes') }}</textarea>
                                            @endif
                                            <div class="invalid-feedback" data-error-for="insurance_notes"></div>
                                        </div>

                                        @if ($socialInsurancePolicy)
                                            <div class="col-12">
                                                <div class="card border shadow-none js-insurance-contribution-preview"
                                                    data-employee-rate="{{ $socialInsurancePolicy->employee_contribution_rate }}"
                                                    data-employer-rate="{{ $socialInsurancePolicy->employer_contribution_rate }}"
                                                    data-minimum-wage="{{ $socialInsurancePolicy->minimum_contribution_wage ?? '' }}"
                                                    data-maximum-wage="{{ $socialInsurancePolicy->maximum_contribution_wage ?? '' }}"
                                                    data-rounding-rule="{{ $socialInsurancePolicy->rounding_rule }}">
                                                    <div class="card-header bg-body-tertiary py-2">
                                                        <div class="fw-semibold">{{ __('hr.employees.statutory.insurance_preview') }}</div>
                                                        <small class="text-muted">{{ __('hr.employees.statutory.preview_information', ['date' => $statutoryPreviewDate]) }}</small>
                                                    </div>
                                                    <div class="card-body py-2">
                                                        <div class="text-muted js-insurance-preview-empty {{ $socialInsurancePreview ? 'd-none' : '' }}">{{ __('hr.employees.statutory.preview_waiting_for_wage') }}</div>
                                                        <div class="row g-2 small js-insurance-preview-values {{ $socialInsurancePreview ? '' : 'd-none' }}">
                                                            @foreach ([
                                                                'entered_wage' => 'entered_wage',
                                                                'applicable_wage' => 'applicable_wage',
                                                                'employee_contribution' => 'employee_contribution',
                                                                'employer_contribution' => 'employer_contribution',
                                                                'combined_contribution' => 'combined_contribution',
                                                            ] as $label => $previewKey)
                                                                <div class="col-6">
                                                                    <span class="text-muted">{{ __('hr.employees.statutory.'.$label) }}:</span>
                                                                    <strong data-insurance-preview-value="{{ $previewKey }}">{{ $socialInsurancePreview[$previewKey] ?? '—' }}</strong>
                                                                </div>
                                                            @endforeach
                                                            <div class="col-12 text-warning js-insurance-preview-clamped {{ ($socialInsurancePreview['was_clamped'] ?? false) ? '' : 'd-none' }}">
                                                                {{ __('hr.employees.statutory.wage_was_clamped') }}
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 col-xl-6">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="d-flex align-items-start justify-content-between gap-2 mb-3">
                                        <div>
                                            <h6 class="mb-1">{{ __('hr.employees.statutory.employment_tax') }}</h6>
                                            <small class="text-muted">{{ __('hr.employees.statutory.preview_date', ['date' => $statutoryPreviewDate]) }}</small>
                                        </div>
                                        @if ($employmentTaxPolicyEditUrl)
                                            <a class="btn btn-sm btn-falcon-default" href="{{ $employmentTaxPolicyEditUrl }}">
                                                <span class="fas fa-external-link-alt me-1"></span>{{ __('hr.employees.statutory.open_policy') }}
                                            </a>
                                        @endif
                                    </div>

                                    @if ($employmentTaxPolicy)
                                        <div class="rounded-3 bg-body-tertiary p-3 mb-3">
                                            <div class="fw-semibold mb-2">{{ __('hr.employees.statutory.applicable_policy') }}</div>
                                            <div class="row g-2 small">
                                                <div class="col-12"><span class="text-muted">{{ __('hr.employees.statutory.policy_name') }}:</span> <strong>{{ $employmentTaxPolicy->name }}</strong></div>
                                                <div class="col-12"><span class="text-muted">{{ __('hr.employees.statutory.effective_period') }}:</span> {{ $dateFormatService->formatDate($employmentTaxPolicy->effective_from, '') }} — {{ $employmentTaxPolicy->effective_to ? $dateFormatService->formatDate($employmentTaxPolicy->effective_to, '') : '∞' }}</div>
                                                <div class="col-6"><span class="text-muted">{{ __('hr.employees.statutory.tax_year') }}:</span> {{ $employmentTaxPolicy->tax_year }}</div>
                                                <div class="col-6"><span class="text-muted">{{ __('hr.employees.statutory.annual_exemption') }}:</span> {{ $employmentTaxPolicy->annual_exemption_amount }}</div>
                                                <div class="col-12"><span class="text-muted">{{ __('hr.employees.statutory.rounding_rule') }}:</span> {{ __('hr.foundation.options.rounding_rule.'.$employmentTaxPolicy->rounding_rule) }}</div>
                                            </div>
                                            <button class="btn btn-link btn-sm px-0 mt-2" type="button" data-bs-toggle="collapse" data-bs-target="#employee-tax-policy-brackets" aria-expanded="false">
                                                {{ __('hr.employees.statutory.tax_brackets') }} <span class="fas fa-chevron-down ms-1"></span>
                                            </button>
                                            <div class="collapse" id="employee-tax-policy-brackets">
                                                <div class="table-responsive">
                                                    <table class="table table-sm mb-0 align-middle">
                                                        <thead>
                                                            <tr>
                                                                <th>#</th>
                                                                <th class="text-center">{{ __('hr.employees.statutory.from_amount') }}</th>
                                                                <th class="text-center">{{ __('hr.employees.statutory.to_amount') }}</th>
                                                                <th class="text-center">{{ __('hr.employees.statutory.rate') }}</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            @foreach ($employmentTaxPolicy->brackets as $bracketIndex => $bracket)
                                                                <tr>
                                                                    <td>{{ $bracketIndex + 1 }}</td>
                                                                    <td class="text-center">{{ $bracket->from_amount }}</td>
                                                                    <td class="text-center">{{ $bracket->to_amount ?? __('hr.employees.statutory.no_upper_limit') }}</td>
                                                                    <td class="text-center">{{ $bracket->rate }}%</td>
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                            <div class="alert alert-info py-2 mt-3 mb-0 small">{{ __('hr.employees.statutory.tax_calculation_deferred') }}</div>
                                        </div>
                                    @else
                                        <div class="alert alert-warning py-2 mb-3">{{ __('hr.employees.statutory.no_applicable_policy', ['date' => $statutoryPreviewDate]) }}</div>
                                    @endif
                                    <div class="row g-3">
                                        @php $taxStatus = (string) old('tax_status', $fieldValue('tax_status', 'not_subject')); @endphp
                                        <div class="col-md-6">
                                            @if ($isView)
                                                <x-forms.view-field for="hr-employee-tax-status" :label="__('hr.employees.attributes.tax_status')" :value="__('hr.employees.statutory_statuses.'.$taxStatus)" required />
                                            @else
                                                <x-forms.label for="hr-employee-tax-status" :label="__('hr.employees.attributes.tax_status')" required />
                                                <select id="hr-employee-tax-status" name="tax_status" class="form-select js-hr-statutory-status" data-statutory-target="tax" required>
                                                    @foreach ($statutoryStatusOptions as $status)
                                                        <option value="{{ $status }}" @selected($taxStatus === $status)>{{ __('hr.employees.statutory_statuses.'.$status) }}</option>
                                                    @endforeach
                                                </select>
                                            @endif
                                            <div class="invalid-feedback" data-error-for="tax_status"></div>
                                        </div>

                                        @foreach (['tax_start_date', 'tax_end_date'] as $fieldName)
                                            @php $inputId = 'hr-employee-'.str_replace('_', '-', $fieldName); @endphp
                                            <div class="col-md-6" data-statutory-details="tax">
                                                <label class="form-label" for="{{ $inputId }}">{{ __('hr.employees.attributes.'.$fieldName) }}</label>
                                                @if ($isView)
                                                    <x-forms.view-field :for="$inputId" :value="$dateValue($fieldName)" />
                                                @else
                                                    <input id="{{ $inputId }}" name="{{ $fieldName }}" type="text" class="form-control js-date-picker" value="{{ $dateValue($fieldName) }}" placeholder="{{ __('common.placeholders.select_date') }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}">
                                                @endif
                                                <div class="invalid-feedback" data-error-for="{{ $fieldName }}"></div>
                                            </div>
                                        @endforeach

                                        <div class="col-12" data-statutory-reason="tax">
                                            <label class="form-label" for="hr-employee-tax-special-treatment-reason">{{ __('hr.employees.attributes.tax_special_treatment_reason') }}</label>
                                            @if ($isView)
                                                <x-forms.view-field for="hr-employee-tax-special-treatment-reason" :value="$fieldValue('tax_special_treatment_reason')" />
                                            @else
                                                <input id="hr-employee-tax-special-treatment-reason" name="tax_special_treatment_reason" type="text" class="form-control" value="{{ $fieldValue('tax_special_treatment_reason') }}">
                                            @endif
                                            <div class="invalid-feedback" data-error-for="tax_special_treatment_reason"></div>
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label" for="hr-employee-tax-notes">{{ __('hr.employees.attributes.tax_notes') }}</label>
                                            @if ($isView)
                                                <x-forms.view-field for="hr-employee-tax-notes" as="textarea" :value="$fieldValue('tax_notes')" rows="3" />
                                            @else
                                                <textarea id="hr-employee-tax-notes" name="tax_notes" class="form-control" rows="3">{{ $fieldValue('tax_notes') }}</textarea>
                                            @endif
                                            <div class="invalid-feedback" data-error-for="tax_notes"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="pane-documents" role="tabpanel" aria-labelledby="tab-documents">
                        @if (($isView || ! $canManageDocuments) && $canViewDocuments)
                            <div class="vstack gap-3 js-hr-employee-documents-list">
                                @forelse ($documentRows as $documentIndex => $document)
                                    @include('modules.hr.employees.partials.document-row', [
                                        'document' => $document,
                                        'documentSequence' => $documentIndex + 1,
                                        'employee' => $employee,
                                        'isView' => true,
                                    ])
                                @empty
                                    <div class="border rounded-2 py-4 text-center text-600 js-hr-employee-documents-empty">{{ __('hr.employees.documents.messages.empty') }}</div>
                                @endforelse
                            </div>
                        @elseif (! $isView && $canManageDocuments)
                            <div class="gap-2 mb-3 d-flex align-items-center justify-content-between">
                                <h6 class="mb-0">{{ __('hr.employees.documents.title') }}</h6>
                                <button type="button" class="btn btn-falcon-default btn-sm js-hr-document-add">
                                    <span class="fas fa-plus me-1"></span>{{ __('hr.employees.actions.add_document') }}
                                </button>
                            </div>
                            <div class="vstack gap-3 js-hr-document-rows" data-next-index="{{ $documentRows->count() }}">
                                @forelse ($documentRows as $documentIndex => $document)
                                    @include('modules.hr.employees.partials.document-form-card', compact('document', 'documentIndex', 'documentTypeSelect', 'dateFormatService', 'documentDateValue', 'employee', 'canViewDocuments', 'canDeleteDocuments'))
                                @empty
                                    <div class="border rounded-2 py-4 text-center text-600 js-hr-document-empty">{{ __('hr.employees.documents.messages.empty') }}</div>
                                @endforelse
                            </div>

                            <template id="hr-document-row-template">
                                @include('modules.hr.employees.partials.document-form-card', [
                                    'document' => null,
                                    'documentIndex' => '__INDEX__',
                                    'documentTypeSelect' => $documentTypeSelect,
                                    'dateFormatService' => $dateFormatService,
                                    'documentDateValue' => $documentDateValue,
                                    'employee' => $employee,
                                    'canViewDocuments' => $canViewDocuments,
                                    'canDeleteDocuments' => $canDeleteDocuments,
                                ])
                            </template>
                        @else
                            <div class="alert alert-info mb-0">{{ __('common.messages.forbidden') }}</div>
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
            'documentItemTitle' => __('hr.employees.documents.item_title', ['number' => ':number']),
            'replaceDocumentFile' => __('hr.employees.documents.actions.replace'),
            'selectDocumentFile' => __('hr.employees.actions.select_document_file'),
            'emptyBiometric' => __('hr.employees.biometric.empty'),
            'noPhotoSelected' => __('hr.employees.photo.no_file_selected'),
        ];
    @endphp
    <script>
        window.hrEmployeesMessages = @json($hrMessages);
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Core/file-picker.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Core/archive-image-picker-field.js') }}"></script>
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/HR/hr-employees.js') }}"></script>
@endpush

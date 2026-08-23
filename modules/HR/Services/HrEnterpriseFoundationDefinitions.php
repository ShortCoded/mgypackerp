<?php

namespace Modules\HR\Services;

use Modules\HR\Models\HrBiometricDevice;
use Modules\HR\Models\HrDepartment;
use Modules\HR\Models\HrDocumentType;
use Modules\HR\Models\HrEmploymentTaxPolicy;
use Modules\HR\Models\HrEmploymentType;
use Modules\HR\Models\HrGrade;
use Modules\HR\Models\HrInsuranceOffice;
use Modules\HR\Models\HrJob;
use Modules\HR\Models\HrSection;
use Modules\HR\Models\HrShift;
use Modules\HR\Models\HrSocialInsurancePolicy;

final class HrEnterpriseFoundationDefinitions
{
    /**
     * @return array<string, HrFoundationDefinition>
     */
    public static function definitions(): array
    {
        return [
            'departments' => new HrFoundationDefinition(
                key: 'departments',
                routeKey: 'departments',
                documentKey: 'hr_departments',
                permissionPrefix: 'hr.departments',
                table: 'hr_departments',
                modelClass: HrDepartment::class,
                translationKey: 'departments',
                fields: [
                    ['name' => 'code', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:80'], 'unique' => true],
                ],
                jsNamespace: 'hrDepartments',
                tableColumns: [
                    ['name' => 'code', 'type' => 'text'],
                    ['name' => 'status', 'type' => 'status'],
                ],
            ),
            'sections' => new HrFoundationDefinition(
                key: 'sections',
                routeKey: 'sections',
                documentKey: 'hr_sections',
                permissionPrefix: 'hr.sections',
                table: 'hr_sections',
                modelClass: HrSection::class,
                translationKey: 'sections',
                fields: [
                    ['name' => 'department_doc_num', 'column' => 'department_id', 'type' => 'relation', 'model' => HrDepartment::class, 'select2' => 'departments', 'rules' => ['nullable', 'string'], 'active_only' => true],
                    ['name' => 'code', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:80'], 'unique' => true],
                ],
                jsNamespace: 'hrSections',
                tableColumns: [
                    ['name' => 'code', 'type' => 'text'],
                    ['name' => 'status', 'type' => 'status'],
                ],
            ),
            'jobs' => new HrFoundationDefinition(
                key: 'jobs',
                routeKey: 'jobs',
                documentKey: 'hr_jobs',
                permissionPrefix: 'hr.jobs',
                table: 'hr_jobs',
                modelClass: HrJob::class,
                translationKey: 'jobs',
                fields: [
                    ['name' => 'code', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:80'], 'unique' => true],
                    ['name' => 'description', 'type' => 'textarea', 'rules' => ['nullable', 'string']],
                ],
                jsNamespace: 'hrJobs',
                tableColumns: [
                    ['name' => 'code', 'type' => 'text'],
                    ['name' => 'status', 'type' => 'status'],
                ],
            ),
            'document-types' => self::simpleMaster(
                routeKey: 'document-types',
                documentKey: 'hr_document_types',
                permissionPrefix: 'hr.document_types',
                table: 'hr_document_types',
                modelClass: HrDocumentType::class,
                translationKey: 'document_types',
                jsNamespace: 'hrDocumentTypes',
            ),
            'shifts' => new HrFoundationDefinition(
                key: 'shifts',
                routeKey: 'shifts',
                documentKey: 'hr_shifts',
                permissionPrefix: 'hr.shifts',
                table: 'hr_shifts',
                modelClass: HrShift::class,
                translationKey: 'shifts',
                fields: [
                    ['name' => 'start_time', 'type' => 'time', 'rules' => ['nullable', 'date_format:H:i']],
                    ['name' => 'end_time', 'type' => 'time', 'rules' => ['nullable', 'date_format:H:i']],
                    ['name' => 'break_minutes', 'type' => 'number', 'rules' => ['required', 'integer', 'min:0', 'max:65535'], 'default' => 0, 'scale' => 0, 'min' => 0, 'max' => 65535, 'step' => '1'],
                    ['name' => 'crosses_midnight', 'type' => 'checkbox', 'rules' => ['nullable', 'boolean'], 'default' => false],
                ],
                jsNamespace: 'hrShifts',
                tableColumns: [
                    ['name' => 'start_time', 'type' => 'text'],
                    ['name' => 'end_time', 'type' => 'text'],
                    ['name' => 'break_minutes', 'type' => 'number'],
                    ['name' => 'status', 'type' => 'status'],
                ],
            ),
            'biometric-devices' => new HrFoundationDefinition(
                key: 'biometric-devices',
                routeKey: 'biometric-devices',
                documentKey: 'hr_biometric_devices',
                permissionPrefix: 'hr.biometric_devices',
                table: 'hr_biometric_devices',
                modelClass: HrBiometricDevice::class,
                translationKey: 'biometric_devices',
                fields: [
                    ['name' => 'device_uid', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:120'], 'unique' => true, 'help' => 'device_uid'],
                    ['name' => 'serial_number', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:120']],
                    ['name' => 'location', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:255']],
                ],
                jsNamespace: 'hrBiometricDevices',
                tableColumns: [
                    ['name' => 'device_uid', 'type' => 'text'],
                    ['name' => 'serial_number', 'type' => 'text'],
                    ['name' => 'location', 'type' => 'text'],
                    ['name' => 'status', 'type' => 'status'],
                ],
                companyScoped: true,
            ),
            'grades' => new HrFoundationDefinition(
                key: 'grades',
                routeKey: 'grades',
                documentKey: 'hr_grades',
                permissionPrefix: 'hr.grades',
                table: 'hr_grades',
                modelClass: HrGrade::class,
                translationKey: 'grades',
                fields: [
                    ['name' => 'code', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:80'], 'unique' => true],
                    ['name' => 'rank', 'type' => 'number', 'rules' => ['required', 'integer', 'min:0', 'max:65535'], 'default' => 0, 'scale' => 0, 'min' => 0, 'max' => 65535, 'step' => '1'],
                ],
                jsNamespace: 'hrGrades',
                tableColumns: [
                    ['name' => 'code', 'type' => 'text'],
                    ['name' => 'rank', 'type' => 'number'],
                    ['name' => 'status', 'type' => 'status'],
                ],
            ),
            'employment-types' => self::simpleMaster(
                routeKey: 'employment-types',
                documentKey: 'hr_employment_types',
                permissionPrefix: 'hr.employment_types',
                table: 'hr_employment_types',
                modelClass: HrEmploymentType::class,
                translationKey: 'employment_types',
                jsNamespace: 'hrEmploymentTypes',
            ),
            'insurance-offices' => new HrFoundationDefinition(
                key: 'insurance-offices',
                routeKey: 'insurance-offices',
                documentKey: 'hr_insurance_offices',
                permissionPrefix: 'hr.insurance_offices',
                table: 'hr_insurance_offices',
                modelClass: HrInsuranceOffice::class,
                translationKey: 'insurance_offices',
                fields: [
                    ['name' => 'insurance_office_code', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:80'], 'unique' => true],
                    ['name' => 'address', 'type' => 'textarea', 'rules' => ['nullable', 'string', 'max:1000']],
                    ['name' => 'phone', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:50']],
                    ['name' => 'email', 'type' => 'text', 'rules' => ['nullable', 'email:rfc', 'max:255']],
                    ['name' => 'contact_person', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:255']],
                ],
                jsNamespace: 'hrInsuranceOffices',
                tableColumns: [
                    ['name' => 'insurance_office_code', 'type' => 'text'],
                    ['name' => 'phone', 'type' => 'text'],
                    ['name' => 'email', 'type' => 'text'],
                    ['name' => 'status', 'type' => 'status'],
                ],
            ),
            'social-insurance-policies' => new HrFoundationDefinition(
                key: 'social-insurance-policies',
                routeKey: 'social-insurance-policies',
                documentKey: 'hr_social_insurance_policies',
                permissionPrefix: 'hr.social_insurance_policies',
                table: 'hr_social_insurance_policies',
                modelClass: HrSocialInsurancePolicy::class,
                translationKey: 'social_insurance_policies',
                fields: [
                    ['name' => 'effective_from', 'type' => 'date', 'rules' => ['required', 'date_format:Y-m-d']],
                    ['name' => 'effective_to', 'type' => 'date', 'rules' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from']],
                    ['name' => 'minimum_contribution_wage', 'type' => 'decimal', 'rules' => ['nullable', 'numeric', 'min:0', 'regex:/^(?:\d{1,13}|\d{0,13}\.\d{1,2})$/D'], 'scale' => 2, 'min' => 0, 'step' => '0.01'],
                    ['name' => 'maximum_contribution_wage', 'type' => 'decimal', 'rules' => ['nullable', 'numeric', 'min:0', 'gte:minimum_contribution_wage', 'regex:/^(?:\d{1,13}|\d{0,13}\.\d{1,2})$/D'], 'scale' => 2, 'min' => 0, 'step' => '0.01'],
                    ['name' => 'rounding_rule', 'type' => 'select', 'rules' => ['required', 'string', 'in:nearest,down,up,none'], 'options' => ['nearest', 'down', 'up', 'none'], 'default' => 'nearest'],
                ],
                jsNamespace: 'hrSocialInsurancePolicies',
                tableColumns: [
                    ['name' => 'effective_from', 'type' => 'date'],
                    ['name' => 'effective_to', 'type' => 'date'],
                    ['name' => 'employee_contribution_rate', 'type' => 'decimal'],
                    ['name' => 'employer_contribution_rate', 'type' => 'decimal'],
                    ['name' => 'status', 'type' => 'status'],
                ],
                companyScoped: true,
                hasInsuranceComponents: true,
            ),
            'employment-tax-policies' => new HrFoundationDefinition(
                key: 'employment-tax-policies',
                routeKey: 'employment-tax-policies',
                documentKey: 'hr_employment_tax_policies',
                permissionPrefix: 'hr.employment_tax_policies',
                table: 'hr_employment_tax_policies',
                modelClass: HrEmploymentTaxPolicy::class,
                translationKey: 'employment_tax_policies',
                fields: [
                    ['name' => 'tax_year', 'type' => 'number', 'rules' => ['required', 'integer', 'min:2000', 'max:2200'], 'scale' => 0, 'min' => 2000, 'max' => 2200, 'step' => '1'],
                    ['name' => 'effective_from', 'type' => 'date', 'rules' => ['required', 'date_format:Y-m-d']],
                    ['name' => 'effective_to', 'type' => 'date', 'rules' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from']],
                    ['name' => 'annual_exemption_amount', 'type' => 'decimal', 'rules' => ['required', 'numeric', 'min:0', 'regex:/^(?:\d{1,13}|\d{0,13}\.\d{1,2})$/D'], 'default' => '0', 'scale' => 2, 'min' => 0, 'step' => '0.01'],
                    ['name' => 'rounding_rule', 'type' => 'select', 'rules' => ['required', 'string', 'in:nearest,down,up,none'], 'options' => ['nearest', 'down', 'up', 'none'], 'default' => 'nearest'],
                ],
                jsNamespace: 'hrEmploymentTaxPolicies',
                tableColumns: [
                    ['name' => 'tax_year', 'type' => 'number'],
                    ['name' => 'effective_from', 'type' => 'date'],
                    ['name' => 'effective_to', 'type' => 'date'],
                    ['name' => 'annual_exemption_amount', 'type' => 'decimal'],
                    ['name' => 'status', 'type' => 'status'],
                ],
                companyScoped: true,
                hasTaxBrackets: true,
            ),
        ];
    }

    private static function simpleMaster(
        string $routeKey,
        string $documentKey,
        string $permissionPrefix,
        string $table,
        string $modelClass,
        string $translationKey,
        string $jsNamespace,
    ): HrFoundationDefinition {
        return new HrFoundationDefinition(
            key: $routeKey,
            routeKey: $routeKey,
            documentKey: $documentKey,
            permissionPrefix: $permissionPrefix,
            table: $table,
            modelClass: $modelClass,
            translationKey: $translationKey,
            fields: [
                ['name' => 'code', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:80'], 'unique' => true],
            ],
            jsNamespace: $jsNamespace,
            tableColumns: [
                ['name' => 'code', 'type' => 'text'],
                ['name' => 'status', 'type' => 'status'],
            ],
        );
    }
}

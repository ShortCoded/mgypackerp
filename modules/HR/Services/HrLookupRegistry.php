<?php

namespace Modules\HR\Services;

use InvalidArgumentException;
use Modules\HR\Models\HrAllowance;
use Modules\HR\Models\HrArea;
use Modules\HR\Models\HrCity;
use Modules\HR\Models\HrCountry;
use Modules\HR\Models\HrFaculty;
use Modules\HR\Models\HrGovernorate;
use Modules\HR\Models\HrHiringStatus;
use Modules\HR\Models\HrIdentification;
use Modules\HR\Models\HrMilitaryService;
use Modules\HR\Models\HrNationality;
use Modules\HR\Models\HrQualification;
use Modules\HR\Models\HrReligion;
use Modules\HR\Models\HrSpecialization;
use Modules\HR\Models\HrUniversity;

class HrLookupRegistry
{
    /**
     * @return array<string, HrLookupDefinition>
     */
    public function all(): array
    {
        return [
            'countries' => new HrLookupDefinition(
                key: 'countries',
                routeKey: 'countries',
                documentKey: 'hr_countries',
                permissionPrefix: 'hr.countries',
                table: 'hr_countries',
                modelClass: HrCountry::class,
                translationKey: 'countries',
                viewPath: 'modules.hr.lookups',
                jsNamespace: 'hrCountries',
            ),
            'governorates' => new HrLookupDefinition(
                key: 'governorates',
                routeKey: 'governorates',
                documentKey: 'hr_governorates',
                permissionPrefix: 'hr.governorates',
                table: 'hr_governorates',
                modelClass: HrGovernorate::class,
                translationKey: 'governorates',
                viewPath: 'modules.hr.lookups',
                jsNamespace: 'hrGovernorates',
            ),
            'cities' => new HrLookupDefinition(
                key: 'cities',
                routeKey: 'cities',
                documentKey: 'hr_cities',
                permissionPrefix: 'hr.cities',
                table: 'hr_cities',
                modelClass: HrCity::class,
                translationKey: 'cities',
                viewPath: 'modules.hr.lookups',
                jsNamespace: 'hrCities',
            ),
            'areas' => new HrLookupDefinition(
                key: 'areas',
                routeKey: 'areas',
                documentKey: 'hr_areas',
                permissionPrefix: 'hr.areas',
                table: 'hr_areas',
                modelClass: HrArea::class,
                translationKey: 'areas',
                viewPath: 'modules.hr.lookups',
                jsNamespace: 'hrAreas',
            ),
            'nationalities' => new HrLookupDefinition(
                key: 'nationalities',
                routeKey: 'nationalities',
                documentKey: 'hr_nationalities',
                permissionPrefix: 'hr.nationalities',
                table: 'hr_nationalities',
                modelClass: HrNationality::class,
                translationKey: 'nationalities',
                viewPath: 'modules.hr.lookups',
                jsNamespace: 'hrNationalities',
            ),
            'religions' => new HrLookupDefinition(
                key: 'religions',
                routeKey: 'religions',
                documentKey: 'hr_religions',
                permissionPrefix: 'hr.religions',
                table: 'hr_religions',
                modelClass: HrReligion::class,
                translationKey: 'religions',
                viewPath: 'modules.hr.lookups',
                jsNamespace: 'hrReligions',
            ),
            'qualifications' => new HrLookupDefinition(
                key: 'qualifications',
                routeKey: 'qualifications',
                documentKey: 'hr_qualifications',
                permissionPrefix: 'hr.qualifications',
                table: 'hr_qualifications',
                modelClass: HrQualification::class,
                translationKey: 'qualifications',
                viewPath: 'modules.hr.lookups',
                jsNamespace: 'hrQualifications',
            ),
            'universities' => new HrLookupDefinition(
                key: 'universities',
                routeKey: 'universities',
                documentKey: 'hr_universities',
                permissionPrefix: 'hr.universities',
                table: 'hr_universities',
                modelClass: HrUniversity::class,
                translationKey: 'universities',
                viewPath: 'modules.hr.lookups',
                jsNamespace: 'hrUniversities',
            ),
            'faculties' => new HrLookupDefinition(
                key: 'faculties',
                routeKey: 'faculties',
                documentKey: 'hr_faculties',
                permissionPrefix: 'hr.faculties',
                table: 'hr_faculties',
                modelClass: HrFaculty::class,
                translationKey: 'faculties',
                viewPath: 'modules.hr.lookups',
                jsNamespace: 'hrFaculties',
            ),
            'specializations' => new HrLookupDefinition(
                key: 'specializations',
                routeKey: 'specializations',
                documentKey: 'hr_specializations',
                permissionPrefix: 'hr.specializations',
                table: 'hr_specializations',
                modelClass: HrSpecialization::class,
                translationKey: 'specializations',
                viewPath: 'modules.hr.lookups',
                jsNamespace: 'hrSpecializations',
            ),
            'military-services' => new HrLookupDefinition(
                key: 'military-services',
                routeKey: 'military-services',
                documentKey: 'hr_military_services',
                permissionPrefix: 'hr.military_services',
                table: 'hr_military_services',
                modelClass: HrMilitaryService::class,
                translationKey: 'military_services',
                viewPath: 'modules.hr.lookups',
                jsNamespace: 'hrMilitaryServices',
            ),
            'allowances' => new HrLookupDefinition(
                key: 'allowances',
                routeKey: 'allowances',
                documentKey: 'hr_allowances',
                permissionPrefix: 'hr.allowances',
                table: 'hr_allowances',
                modelClass: HrAllowance::class,
                translationKey: 'allowances',
                viewPath: 'modules.hr.lookups',
                jsNamespace: 'hrAllowances',
            ),
            'hiring-statuses' => new HrLookupDefinition(
                key: 'hiring-statuses',
                routeKey: 'hiring-statuses',
                documentKey: 'hr_hiring_statuses',
                permissionPrefix: 'hr.hiring_statuses',
                table: 'hr_hiring_statuses',
                modelClass: HrHiringStatus::class,
                translationKey: 'hiring_statuses',
                viewPath: 'modules.hr.lookups',
                jsNamespace: 'hrHiringStatuses',
            ),
            'identifications' => new HrLookupDefinition(
                key: 'identifications',
                routeKey: 'identifications',
                documentKey: 'hr_identifications',
                permissionPrefix: 'hr.identifications',
                table: 'hr_identifications',
                modelClass: HrIdentification::class,
                translationKey: 'identifications',
                viewPath: 'modules.hr.lookups',
                jsNamespace: 'hrIdentifications',
            ),
        ];
    }

    public function get(string $key): HrLookupDefinition
    {
        return $this->all()[$key] ?? throw new InvalidArgumentException("Unknown HR lookup [{$key}].");
    }

    public function fromRouteName(?string $routeName): HrLookupDefinition
    {
        $routeName = (string) $routeName;

        foreach ($this->all() as $definition) {
            if (str_contains($routeName, "admin.hr.{$definition->routeKey}.")) {
                return $definition;
            }
        }

        throw new InvalidArgumentException("Unable to resolve HR lookup from route [{$routeName}].");
    }
}

<?php

namespace Modules\HR\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Services\OperatingCompanyContextService;

class HrEmployee extends Model
{
    use SoftDeletes;

    protected $table = 'hr_employees';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'employee_code',
        'employee_number',
        'full_name',
        'name',
        'local_name',
        'person_type',
        'status',
        'gender',
        'birth_date',
        'marital_status',
        'national_id',
        'national_id_expiry_date',
        'passport_number',
        'passport_expiry_date',
        'hire_date',
        'probation_end_date',
        'termination_date',
        'contract_start_date',
        'contract_end_date',
        'company_id',
        'user_id',
        'branch_id',
        'department_id',
        'section_id',
        'job_id',
        'grade_id',
        'department',
        'department_name',
        'job_title',
        'manager_employee_id',
        'work_email',
        'email',
        'personal_email',
        'phone',
        'mobile',
        'alternate_phone',
        'country_id',
        'governorate_id',
        'city_id',
        'area_id',
        'address',
        'photo_archive_file_id',
        'signature_archive_file_id',
        'nationality_id',
        'religion_id',
        'qualification_id',
        'university_id',
        'faculty_id',
        'specialization_id',
        'employment_type_id',
        'graduation_year',
        'allowance_id',
        'military_service_id',
        'hiring_status_id',
        'identification_id',
        'attendance_tracking_enabled',
        'attendance_policy_type',
        'default_shift_id',
        'allow_late_minutes',
        'allow_early_leave_minutes',
        'overtime_enabled',
        'social_insurance_number',
        'insurance_status',
        'insurance_office_id',
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
        'pay_basis',
        'payroll_currency_id',
        'exchange_rate',
        'basic_salary',
        'weekly_wage',
        'daily_wage',
        'hourly_wage',
        'shift_wage',
        'piece_rate',
        'payment_method',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relation',
        'start_date',
        'end_date',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'restored_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'insurance_status' => 'not_subject',
        'tax_status' => 'not_subject',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $employee): void {
            $employee->public_uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'national_id_expiry_date' => 'date',
            'passport_expiry_date' => 'date',
            'hire_date' => 'date',
            'probation_end_date' => 'date',
            'termination_date' => 'date',
            'contract_start_date' => 'date',
            'contract_end_date' => 'date',
            'start_date' => 'date',
            'end_date' => 'date',
            'insurance_start_date' => 'date',
            'insurance_end_date' => 'date',
            'tax_start_date' => 'date',
            'tax_end_date' => 'date',
            'attendance_tracking_enabled' => 'boolean',
            'overtime_enabled' => 'boolean',
            'basic_salary' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'weekly_wage' => 'decimal:4',
            'daily_wage' => 'decimal:4',
            'hourly_wage' => 'decimal:4',
            'shift_wage' => 'decimal:4',
            'piece_rate' => 'decimal:4',
            'insurance_contribution_wage' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    public function resolveRouteBinding(mixed $value, mixed $field = null): ?self
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();
        $query = $this->newQuery()->where($field ?? $this->getRouteKeyName(), $value);

        return $companyId === null
            ? $query->whereRaw('1 = 0')->first()
            : $query->where($this->getTable().'.company_id', $companyId)->first();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * @return HasMany<HrEmployeeDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(HrEmployeeDocument::class, 'employee_id');
    }

    /**
     * @return HasMany<HrEmployeeBiometricMapping, $this>
     */
    public function biometricMappings(): HasMany
    {
        return $this->hasMany(HrEmployeeBiometricMapping::class, 'employee_id');
    }

    /**
     * @return BelongsTo<ArchiveFile, $this>
     */
    public function photoArchiveFile(): BelongsTo
    {
        return $this->belongsTo(ArchiveFile::class, 'photo_archive_file_id');
    }

    /**
     * @return BelongsTo<ArchiveFile, $this>
     */
    public function signatureArchiveFile(): BelongsTo
    {
        return $this->belongsTo(ArchiveFile::class, 'signature_archive_file_id');
    }

    /**
     * @return HasMany<HrEmployeeBiometricMapping, $this>
     */
    public function fingerprints(): HasMany
    {
        return $this->hasMany(HrEmployeeBiometricMapping::class, 'employee_id');
    }

    /**
     * @return BelongsTo<HrCountry, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(HrCountry::class, 'country_id');
    }

    /**
     * @return BelongsTo<HrGovernorate, $this>
     */
    public function governorate(): BelongsTo
    {
        return $this->belongsTo(HrGovernorate::class, 'governorate_id');
    }

    /**
     * @return BelongsTo<HrCity, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(HrCity::class, 'city_id');
    }

    /**
     * @return BelongsTo<HrArea, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(HrArea::class, 'area_id');
    }

    /**
     * @return BelongsTo<HrNationality, $this>
     */
    public function nationality(): BelongsTo
    {
        return $this->belongsTo(HrNationality::class, 'nationality_id');
    }

    /**
     * @return BelongsTo<HrReligion, $this>
     */
    public function religion(): BelongsTo
    {
        return $this->belongsTo(HrReligion::class, 'religion_id');
    }

    /**
     * @return BelongsTo<HrQualification, $this>
     */
    public function qualification(): BelongsTo
    {
        return $this->belongsTo(HrQualification::class, 'qualification_id');
    }

    /**
     * @return BelongsTo<HrUniversity, $this>
     */
    public function university(): BelongsTo
    {
        return $this->belongsTo(HrUniversity::class, 'university_id');
    }

    /**
     * @return BelongsTo<HrFaculty, $this>
     */
    public function faculty(): BelongsTo
    {
        return $this->belongsTo(HrFaculty::class, 'faculty_id');
    }

    /**
     * @return BelongsTo<HrSpecialization, $this>
     */
    public function specialization(): BelongsTo
    {
        return $this->belongsTo(HrSpecialization::class, 'specialization_id');
    }

    /**
     * @return BelongsTo<HrMilitaryService, $this>
     */
    public function militaryService(): BelongsTo
    {
        return $this->belongsTo(HrMilitaryService::class, 'military_service_id');
    }

    /**
     * @return BelongsTo<HrHiringStatus, $this>
     */
    public function hiringStatus(): BelongsTo
    {
        return $this->belongsTo(HrHiringStatus::class, 'hiring_status_id');
    }

    /**
     * @return BelongsTo<HrIdentification, $this>
     */
    public function identification(): BelongsTo
    {
        return $this->belongsTo(HrIdentification::class, 'identification_id');
    }

    /**
     * @return BelongsTo<HrAllowance, $this>
     */
    public function allowance(): BelongsTo
    {
        return $this->belongsTo(HrAllowance::class, 'allowance_id');
    }

    /**
     * @return BelongsTo<HrEmploymentType, $this>
     */
    public function employmentType(): BelongsTo
    {
        return $this->belongsTo(HrEmploymentType::class, 'employment_type_id');
    }

    /**
     * @return BelongsTo<HrShift, $this>
     */
    public function defaultShift(): BelongsTo
    {
        return $this->belongsTo(HrShift::class, 'default_shift_id');
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function payrollCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'payroll_currency_id');
    }

    /**
     * @return BelongsTo<HrInsuranceOffice, $this>
     */
    public function insuranceOffice(): BelongsTo
    {
        return $this->belongsTo(HrInsuranceOffice::class, 'insurance_office_id');
    }

    /**
     * @return BelongsTo<HrDepartment, $this>
     */
    public function departmentModel(): BelongsTo
    {
        return $this->belongsTo(HrDepartment::class, 'department_id');
    }

    /**
     * @return BelongsTo<HrSection, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(HrSection::class, 'section_id');
    }

    /**
     * @return BelongsTo<HrJob, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(HrJob::class, 'job_id');
    }

    /**
     * @return BelongsTo<HrGrade, $this>
     */
    public function grade(): BelongsTo
    {
        return $this->belongsTo(HrGrade::class, 'grade_id');
    }

    /**
     * @param  Builder<HrEmployee>  $query
     * @return Builder<HrEmployee>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->getTable().'.status', 'active');
    }
}

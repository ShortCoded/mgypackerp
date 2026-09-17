<?php

namespace Modules\Sales\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Concerns\SnapshotsCompanyPrintIdentity;
use Modules\Core\Models\Currency;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\HR\Models\HrEmployee;

class Quotation extends Model
{
    use SnapshotsCompanyPrintIdentity, SoftDeletes;

    public const TypeStandard = 'standard';

    public const TypeProject = 'project';

    public const StatusDraft = 'draft';

    public const StatusSent = 'sent';

    public const StatusUnderReview = 'under_review';

    public const StatusAccepted = 'accepted';

    public const StatusRejected = 'rejected';

    public const StatusExpired = 'expired';

    public const StatusConverted = 'converted';

    public const StatusCancelled = 'cancelled';

    public const Types = [
        self::TypeStandard,
        self::TypeProject,
    ];

    public const Statuses = [
        self::StatusDraft,
        self::StatusSent,
        self::StatusUnderReview,
        self::StatusAccepted,
        self::StatusRejected,
        self::StatusExpired,
        self::StatusConverted,
        self::StatusCancelled,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'sales_request_id',
        'doc_number',
        'doc_num',
        'company_id',
        'branch_id',
        'customer_id',
        'customer_reference',
        'quotation_type',
        'project_name',
        'subject',
        'quotation_date',
        'valid_until',
        'currency_id',
        'exchange_rate',
        'sales_person_id',
        'business_employee_id',
        'sent_by',
        'sent_at',
        'current_revision_id',
        'status',
        'notes',
        'internal_notes',
        'print_identity_snapshot',
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
        'quotation_type' => self::TypeStandard,
        'exchange_rate' => 1,
        'status' => self::StatusDraft,
    ];

    protected function casts(): array
    {
        return [
            'quotation_date' => 'date',
            'valid_until' => 'date',
            'exchange_rate' => 'decimal:6',
            'print_identity_snapshot' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'restored_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    public function scopeOperationallyPending(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('status'), [
            self::StatusDraft,
            self::StatusSent,
            self::StatusUnderReview,
            self::StatusAccepted,
        ]);
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        return $this->companyScopedRouteQuery($value, $field)->first();
    }

    public function resolveSoftDeletableRouteBinding($value, $field = null): ?self
    {
        return $this->companyScopedRouteQuery($value, $field)->withTrashed()->first();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function salesRequest(): BelongsTo
    {
        return $this->belongsTo(SalesRequest::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class)->withTrashed();
    }

    /** Legacy authentication reference retained without inferring an employee mapping. */
    public function legacySalesUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_person_id');
    }

    public function salesPerson(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'business_employee_id')->withTrashed();
    }

    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(QuotationRevision::class, 'current_revision_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(QuotationRevision::class)->orderByDesc('revision_number');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(QuotationAttachment::class)->orderByDesc('created_at');
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class)->orderByDesc('created_at');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId);
    }

    public function canDeleteDraft(): bool
    {
        return ! $this->trashed() && $this->status === self::StatusDraft
            && $this->revisions()->count() === 1 && ! $this->salesOrders()->exists();
    }

    public function canCancel(): bool
    {
        return ! $this->trashed() && ! in_array($this->status, [self::StatusCancelled, self::StatusConverted], true)
            && ! $this->salesOrders()->exists();
    }

    public function canIssueRevision(): bool
    {
        return ! $this->trashed() && in_array($this->status, [self::StatusSent, self::StatusUnderReview, self::StatusAccepted, self::StatusRejected, self::StatusExpired], true)
            && ! $this->salesOrders()->exists();
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function canEditCurrentRevision(): bool
    {
        return ! $this->trashed()
            && $this->status !== self::StatusCancelled
            && $this->currentRevision instanceof QuotationRevision
            && $this->currentRevision->isDraft();
    }

    public function label(): string
    {
        return trim(implode(' / ', array_filter([
            $this->doc_num,
            $this->subject ?: $this->project_name,
        ])));
    }

    private function companyScopedRouteQuery(mixed $value, ?string $field): Builder
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();
        $query = $this->newQuery()->where($field ?? $this->getRouteKeyName(), $value);

        return $companyId === null ? $query->whereRaw('1 = 0') : $query->where($this->getTable().'.company_id', $companyId);
    }
}

<?php

namespace Modules\Production\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Company;

class ProductionIdentifier extends Model
{
    use SoftDeletes;

    protected $table = 'production_identifiers';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'parent_id',
        'doc_number',
        'doc_num',
        'name',
        'is_group',
        'status',
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
        'is_group' => false,
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'is_group' => 'boolean',
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

    public function documentNameLabel(): string
    {
        return self::documentNameLabelFor($this->doc_num, $this->name);
    }

    public static function documentNameLabelFor(?string $docNum, ?string $name): string
    {
        return trim(implode(' / ', array_filter([
            trim((string) $docNum),
            trim((string) $name),
        ], fn (string $value): bool => $value !== '')));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function identifierType(): BelongsTo
    {
        return $this->belongsTo(ProductionIdentifierType::class, 'production_identifier_type_id')->withTrashed();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('doc_number')->orderBy('name');
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->getTable().'.status', 'active');
    }

    public function scopeGroup(Builder $query): Builder
    {
        return $query->where($this->getTable().'.is_group', true);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy($this->getTable().'.doc_number')->orderBy($this->getTable().'.name');
    }
}

<?php

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Auth\Models\Role;

class Branch extends Model
{
    use SoftDeletes;

    public const TypeAdministrative = 'administrative';

    public const TypeFactory = 'factory';

    public const TypeWarehouse = 'warehouse';

    public const TypeShowroom = 'showroom';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'company_id',
        'name',
        'type',
        'address',
        'camera_url',
        'phone',
        'mobile',
        'email',
        'hotline',
        'contact_person',
        'notes',
        'status',
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
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
     * @return BelongsToMany<Role, $this>
     */
    public function accessRoles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_branch_access', 'branch_id', 'role_id')
            ->withTimestamps();
    }

    /**
     * @return HasMany<BranchHall, $this>
     */
    public function halls(): HasMany
    {
        return $this->hasMany(BranchHall::class, 'branch_id')->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<BranchStore, $this>
     */
    public function stores(): HasMany
    {
        return $this->hasMany(BranchStore::class, 'branch_id')->orderBy('position')->orderBy('id');
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [
            self::TypeAdministrative,
            self::TypeFactory,
            self::TypeWarehouse,
            self::TypeShowroom,
        ];
    }

    /**
     * @param  Builder<Branch>  $query
     * @return Builder<Branch>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->getTable().'.status', 'active');
    }
}

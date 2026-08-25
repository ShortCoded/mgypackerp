<?php

namespace Modules\Auth\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\RequestMemo;

class UserPresenceSession extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'user_id',
        'session_fingerprint',
        'status',
        'ip_address',
        'user_agent',
        'browser_name',
        'os_name',
        'device_type',
        'login_at',
        'last_seen_at',
        'last_activity_at',
        'locked_at',
        'logout_at',
        'expires_at',
        'offline_reason',
        'context',
        'branch_id',
        'branch_doc_num',
        'branch_name',
        'financial_period_id',
        'financial_period_doc_num',
        'financial_period_name',
    ];

    protected static function booted(): void
    {
        static::creating(function (UserPresenceSession $presenceSession): void {
            $columns = app(RequestMemo::class)->remember(
                'schema.columns.'.$presenceSession->getTable(),
                fn (): array => Schema::getColumnListing($presenceSession->getTable()),
            );

            if (in_array('public_id', $columns, true) && (! is_string($presenceSession->public_id) || trim($presenceSession->public_id) === '')) {
                $presenceSession->public_id = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'login_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'locked_at' => 'datetime',
            'logout_at' => 'datetime',
            'expires_at' => 'datetime',
            'context' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function financialPeriod(): BelongsTo
    {
        return $this->belongsTo(FinancialPeriod::class);
    }
}

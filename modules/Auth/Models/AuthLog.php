<?php

namespace Modules\Auth\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Core\Models\FinancialPeriod;

class AuthLog extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'user_id',
        'login',
        'identifier',
        'email',
        'phone',
        'username',
        'event',
        'status',
        'remember_me',
        'session_fingerprint',
        'request_id',
        'ip_address',
        'client_ip',
        'user_agent',
        'guard',
        'url',
        'path',
        'route_name',
        'method',
        'referrer',
        'accept_language',
        'locale',
        'timezone',
        'browser_name',
        'browser_version',
        'os_name',
        'os_version',
        'device_type',
        'platform',
        'is_mobile',
        'is_tablet',
        'is_desktop',
        'is_bot',
        'country',
        'region',
        'city',
        'latitude',
        'longitude',
        'location_accuracy',
        'geo_source',
        'isp',
        'asn',
        'payload_summary',
        'failure_reason',
        'context',
        'client_context',
        'device_context',
        'location_context',
        'network_context',
        'logged_in_at',
        'logged_out_at',
        'branch_id',
        'branch_doc_num',
        'branch_name',
        'financial_period_id',
        'financial_period_doc_num',
        'financial_period_name',
    ];

    protected static function booted(): void
    {
        static::creating(function (AuthLog $authLog): void {
            if (! is_string($authLog->public_id) || trim($authLog->public_id) === '') {
                $authLog->public_id = (string) Str::uuid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'remember_me' => 'boolean',
            'is_mobile' => 'boolean',
            'is_tablet' => 'boolean',
            'is_desktop' => 'boolean',
            'is_bot' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'location_accuracy' => 'float',
            'payload_summary' => 'array',
            'context' => 'array',
            'client_context' => 'array',
            'device_context' => 'array',
            'location_context' => 'array',
            'network_context' => 'array',
            'logged_in_at' => 'datetime',
            'logged_out_at' => 'datetime',
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

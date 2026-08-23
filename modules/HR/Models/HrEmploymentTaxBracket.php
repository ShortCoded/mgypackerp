<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class HrEmploymentTaxBracket extends Model
{
    protected $table = 'hr_employment_tax_brackets';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_uuid',
        'employment_tax_policy_id',
        'from_amount',
        'to_amount',
        'rate',
        'notes',
        'sort_order',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $bracket): void {
            $bracket->public_uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_amount' => 'decimal:2',
            'to_amount' => 'decimal:2',
            'rate' => 'decimal:4',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<HrEmploymentTaxPolicy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(HrEmploymentTaxPolicy::class, 'employment_tax_policy_id');
    }
}

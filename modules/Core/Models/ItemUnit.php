<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemUnit extends ItemLookup
{
    protected $table = 'item_units';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'company_id',
        'name',
        'equivalent_value',
        'equivalent_unit_id',
        'notes',
        'status',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'restored_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'equivalent_value' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<ItemUnit, $this>
     */
    public function equivalentUnit(): BelongsTo
    {
        return $this->belongsTo(self::class, 'equivalent_unit_id')->withTrashed();
    }
}

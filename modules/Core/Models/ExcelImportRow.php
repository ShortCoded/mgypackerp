<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExcelImportRow extends Model
{
    public const StatusValid = 'valid';

    public const StatusInvalid = 'invalid';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'batch_id',
        'sheet_key',
        'excel_row',
        'status',
        'import_key',
        'data',
        'normalized_data',
        'issues',
        'created_doc_num',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'normalized_data' => 'array',
            'issues' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ExcelImportBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ExcelImportBatch::class, 'batch_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForSheet(Builder $query, string $sheetKey): Builder
    {
        return $query->where('sheet_key', $sheetKey);
    }
}

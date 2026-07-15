<?php

namespace Modules\Core\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CrudAuditService
{
    /**
     * @var array<string, list<string>>
     */
    private array $tableColumnCache = [];

    public function __construct(
        private readonly RequestMemo $memo,
    ) {}

    public function clearCreationUpdateAudit(Model $record): void
    {
        $values = [];

        foreach (['updated_by', 'updated_at', 'deleted_by', 'deleted_at', 'restored_by', 'restored_at'] as $column) {
            if ($this->hasColumn($record, $column)) {
                $values[$column] = null;
            }
        }

        $this->writeAuditValues($record, $values);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function saveUpdate(Model $record, array $attributes = [], ?int $userId = null): void
    {
        if ($attributes !== []) {
            $record->forceFill($attributes);
        }

        if ($this->hasColumn($record, 'updated_by')) {
            $record->forceFill([
                'updated_by' => $userId ?? auth()->id(),
            ]);
        }

        $record->save();
    }

    public function touchUpdateAudit(Model $record, ?int $userId = null): void
    {
        if ($this->hasColumn($record, 'updated_by')) {
            $record->forceFill([
                'updated_by' => $userId ?? auth()->id(),
            ]);
        }

        if ($this->hasColumn($record, 'updated_at')) {
            $record->forceFill([
                'updated_at' => now(),
            ]);
        }

        $record->save();
    }

    public function softDelete(Model $record, ?int $userId = null): void
    {
        $stableAudit = $this->stableAuditSnapshot($record);
        $deletedBy = $userId ?? auth()->id();

        if ($this->hasColumn($record, 'deleted_by')) {
            $record->forceFill([
                'deleted_by' => $deletedBy,
            ])->saveQuietly();
        }

        $record->delete();

        $values = $stableAudit;

        if ($this->hasColumn($record, 'deleted_by')) {
            $values['deleted_by'] = $deletedBy;
        }

        if ($this->hasColumn($record, 'deleted_at')) {
            $values['deleted_at'] = $record->getAttribute('deleted_at');
        }

        $this->writeAuditValues($record, $values);
    }

    public function restore(Model $record, ?int $userId = null): void
    {
        $stableAudit = $this->stableAuditSnapshot($record);
        $restoredAt = now();
        $restoredBy = $userId ?? auth()->id();

        if ($this->hasColumn($record, 'deleted_by')) {
            $record->forceFill([
                'deleted_by' => null,
            ])->saveQuietly();
        }

        $record->restore();

        $values = $stableAudit;

        if ($this->hasColumn($record, 'deleted_by')) {
            $values['deleted_by'] = null;
        }

        if ($this->hasColumn($record, 'deleted_at')) {
            $values['deleted_at'] = null;
        }

        if ($this->hasColumn($record, 'restored_by')) {
            $values['restored_by'] = $restoredBy;
        }

        if ($this->hasColumn($record, 'restored_at')) {
            $values['restored_at'] = $restoredAt;
        }

        $this->writeAuditValues($record, $values);
    }

    /**
     * @return array<string, mixed>
     */
    private function stableAuditSnapshot(Model $record): array
    {
        $values = [];

        foreach (['created_by', 'created_at', 'updated_by', 'updated_at'] as $column) {
            if ($this->hasColumn($record, $column)) {
                $values[$column] = $record->getAttribute($column);
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function writeAuditValues(Model $record, array $values): void
    {
        if ($values === []) {
            return;
        }

        DB::table($record->getTable())
            ->where($record->getKeyName(), $record->getKey())
            ->update($values);

        $record->forceFill($values);
        $record->syncOriginal();
    }

    private function hasColumn(Model $record, string $column): bool
    {
        return in_array($column, $this->columnsForTable($record->getTable()), true);
    }

    /**
     * @return list<string>
     */
    private function columnsForTable(string $table): array
    {
        if (! array_key_exists($table, $this->tableColumnCache)) {
            $this->tableColumnCache[$table] = $this->memo->remember("schema.columns.{$table}", function () use ($table): array {
                try {
                    return Schema::getColumnListing($table);
                } catch (Throwable) {
                    return [];
                }
            });
        }

        return $this->tableColumnCache[$table];
    }
}

<?php

namespace Modules\Production\Services;

use Illuminate\Support\Facades\DB;
use Modules\Production\Models\QualityInspectionType;

class QualityInspectionPlanSnapshotService
{
    /** @return array<string, mixed>|null */
    public function capture(int $companyId, mixed $inspectionTypeId): ?array
    {
        if ($inspectionTypeId === null) {
            return null;
        }

        $type = QualityInspectionType::query()
            ->where('company_id', $companyId)
            ->whereKey($inspectionTypeId)
            ->firstOrFail();
        $checkpoints = DB::table('quality_checkpoints')
            ->where('company_id', $companyId)
            ->where('quality_inspection_type_id', $type->getKey())
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('sequence')
            ->get([
                'id', 'code', 'name', 'name_ar', 'sequence', 'response_type', 'acceptance_criteria',
                'minimum_value', 'maximum_value', 'measurement_unit', 'is_required',
            ])
            ->map(fn (object $checkpoint): array => [
                ...((array) $checkpoint),
                'id' => (int) $checkpoint->id,
                'sequence' => (int) $checkpoint->sequence,
                'is_required' => (bool) $checkpoint->is_required,
            ])
            ->values()
            ->all();
        $definition = [
            'type' => [
                'id' => (int) $type->getKey(),
                'code' => $type->code,
                'name' => $type->name,
                'name_ar' => $type->name_ar,
                'is_final_production' => (bool) $type->is_final_production,
            ],
            'checkpoints' => $checkpoints,
        ];

        return [
            ...$definition,
            'revision' => hash('sha256', json_encode($definition, JSON_THROW_ON_ERROR)),
            'captured_at' => now()->toIso8601String(),
        ];
    }
}

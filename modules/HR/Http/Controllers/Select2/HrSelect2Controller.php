<?php

namespace Modules\HR\Http\Controllers\Select2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\HR\Services\HrFoundationRegistry;
use Modules\HR\Services\HrSelect2Service;

class HrSelect2Controller extends Controller
{
    public function __construct(
        private readonly HrSelect2Service $select2,
        private readonly HrFoundationRegistry $hrFoundationRegistry,
    ) {}

    public function lookup(Request $request, string $resource): JsonResponse
    {
        if (! $this->canUseHrSelect2($request)) {
            return $this->forbiddenSelect2Response();
        }

        return response()->json($this->select2->lookup($resource, $request));
    }

    public function foundation(Request $request, string $resource): JsonResponse
    {
        if (! $this->canUseHrSelect2($request)) {
            return $this->forbiddenSelect2Response();
        }

        return response()->json($this->select2->foundation($resource, $request));
    }

    public function employees(Request $request): JsonResponse
    {
        if (! $this->canUseHrSelect2($request)) {
            return $this->forbiddenSelect2Response();
        }

        return response()->json($this->select2->employees($request));
    }

    private function forbiddenSelect2Response(): JsonResponse
    {
        return response()->json([
            'message' => __('auth.forbidden'),
            'results' => [],
            'pagination' => ['more' => false],
        ], 403);
    }

    private function canUseHrSelect2(Request $request): bool
    {
        $user = $request->user();

        foreach ([
            'hr.employees.view',
            'hr.employees.create',
            'hr.employees.edit',
            'hr.shift_assignments.view',
            'hr.shift_assignments.manage',
            'hr.employee_attendance.view',
            'hr.employee_attendance.correct',
        ] as $permission) {
            if ($user?->can($permission)) {
                return true;
            }
        }

        foreach ($this->hrFoundationRegistry->all() as $definition) {
            foreach (['view', 'create', 'edit'] as $suffix) {
                if ($user?->can($definition->permission($suffix))) {
                    return true;
                }
            }
        }

        return false;
    }
}

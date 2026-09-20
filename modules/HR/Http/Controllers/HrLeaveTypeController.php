<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\BreadcrumbService;
use Modules\HR\Http\Requests\StoreHrLeaveTypeRequest;
use Modules\HR\Models\HrLeaveType;

class HrLeaveTypeController extends Controller
{
    public function __construct(
        private readonly BreadcrumbService $breadcrumbs,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function index(Request $request): View
    {
        abort_unless((bool) $request->user()?->can('hr.leave_types.view'), 403);
        $trash = $request->string('trash')->toString();
        $canViewDeleted = (bool) $request->user()?->can('hr.leave_types.view_deleted');
        abort_unless(! in_array($trash, ['with', 'only'], true) || $canViewDeleted, 403);
        $leaveTypes = HrLeaveType::query();
        if ($trash === 'with') {
            $leaveTypes->withTrashed();
        } elseif ($trash === 'only') {
            $leaveTypes->onlyTrashed();
        }

        return view('modules.hr.leave-types.index', [
            'leaveTypes' => $leaveTypes
                ->orderBy('name')
                ->paginate(30)
                ->withQueryString(),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.hr.leave-types.index'),
            'canViewDeleted' => $canViewDeleted,
            'trash' => $trash,
        ]);
    }

    public function show(Request $request, string $leaveType): View
    {
        abort_unless((bool) $request->user()?->can('hr.leave_types.view'), 403);
        $record = HrLeaveType::withTrashed()->findOrFail($leaveType);
        if ($record->trashed()) {
            abort_unless((bool) $request->user()?->can('hr.leave_types.view_deleted'), 403);
        }

        return view('modules.hr.leave-types.show', [
            'leaveType' => $record,
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.hr.leave-types.index'),
        ]);
    }

    public function store(StoreHrLeaveTypeRequest $request): RedirectResponse
    {
        try {
            $leaveType = DB::transaction(fn (): HrLeaveType => HrLeaveType::query()->create([
                ...$request->persistenceData(),
                'created_by' => $request->user()->getKey(),
                'updated_by' => $request->user()->getKey(),
            ]));
        } catch (QueryException $exception) {
            $this->throwDuplicateCodeValidation($exception);
        }

        $this->log($request, 'create', $leaveType);

        return back()->with('success', __('hr_leave_types.messages.created'));
    }

    public function update(StoreHrLeaveTypeRequest $request, HrLeaveType $leaveType): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $leaveType): void {
                $locked = HrLeaveType::query()->lockForUpdate()->findOrFail($leaveType->getKey());
                $locked->update([
                    ...$request->persistenceData(),
                    'updated_by' => $request->user()->getKey(),
                ]);
            });
        } catch (QueryException $exception) {
            $this->throwDuplicateCodeValidation($exception);
        }

        $this->log($request, 'update', $leaveType->refresh());

        return back()->with('success', __('hr_leave_types.messages.updated'));
    }

    public function destroy(Request $request, HrLeaveType $leaveType): RedirectResponse
    {
        abort_unless((bool) $request->user()?->can('hr.leave_types.delete'), 403);

        DB::transaction(function () use ($request, $leaveType): void {
            $locked = HrLeaveType::query()->lockForUpdate()->findOrFail($leaveType->getKey());
            $locked->updated_by = $request->user()->getKey();
            $locked->deleted_by = $request->user()->getKey();
            $locked->save();
            $locked->delete();
        });

        $this->log($request, 'delete', $leaveType);

        return back()->with('success', __('hr_leave_types.messages.deleted'));
    }

    public function restore(Request $request, string $leaveType): RedirectResponse
    {
        abort_unless((bool) $request->user()?->can('hr.leave_types.restore'), 403);
        $record = DB::transaction(function () use ($request, $leaveType): HrLeaveType {
            $locked = HrLeaveType::withTrashed()->lockForUpdate()->findOrFail($leaveType);
            abort_unless($locked->trashed(), 409);
            $locked->restore();
            $locked->forceFill([
                'restored_by' => $request->user()->getKey(),
                'restored_at' => now(),
                'updated_by' => $request->user()->getKey(),
            ])->save();

            return $locked;
        });

        $this->log($request, 'restore', $record);

        return back()->with('success', __('hr_leave_types.messages.restored'));
    }

    private function log(Request $request, string $action, HrLeaveType $leaveType): void
    {
        $this->activityLogger->log($request, 'hr', 'leave_type.'.$action, 'success', [
            'subject' => $leaveType,
            'properties_only' => true,
            'properties' => [
                'code' => $leaveType->code,
                'payment_status' => data_get($leaveType->metadata, 'payment_status'),
                'requires_balance' => $leaveType->requiresBalance(),
            ],
        ]);
    }

    private function throwDuplicateCodeValidation(QueryException $exception): never
    {
        if (str_contains($exception->getMessage(), 'hr_leave_types_code_unique')
            || str_contains($exception->getMessage(), 'hr_leave_types.code')) {
            throw ValidationException::withMessages(['code' => __('validation.unique', ['attribute' => __('hr_leave_types.fields.code')])]);
        }

        throw $exception;
    }
}

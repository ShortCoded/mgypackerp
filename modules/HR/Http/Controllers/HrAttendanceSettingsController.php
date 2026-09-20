<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\HR\Http\Requests\UpdateAttendanceSettingsRequest;

class HrAttendanceSettingsController extends Controller
{
    public function __construct(
        private readonly OperatingCompanyContextService $companies,
        private readonly OperatingScopeAccessService $scope,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function index(Request $request): View
    {
        $company = $this->companies->currentCompany($request);
        abort_unless($company !== null, 409);

        return view('modules.hr.attendance-settings.index', [
            'branches' => $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])->get(),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.hr.attendance-settings.index'),
        ]);
    }

    public function update(UpdateAttendanceSettingsRequest $request, Branch $branch): RedirectResponse
    {
        $company = $this->companies->currentCompany($request);
        abort_unless($company !== null && $this->scope->canAccessBranch($request->user(), $branch, $company), 404);

        DB::transaction(function () use ($request, $branch, $company): void {
            $locked = Branch::query()->where('company_id', $company->getKey())->lockForUpdate()->findOrFail($branch->getKey());
            abort_unless($this->scope->canAccessBranch($request->user(), $locked, $company), 404);
            $locked->update([
                ...$request->validated(),
                'updated_by' => $request->user()->getKey(),
            ]);
        });

        return back()->with('success', __('hr_attendance_settings.messages.updated'));
    }
}

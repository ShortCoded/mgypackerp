<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Auth\DataTables\AuthSessionsReportDataTable;
use Modules\Auth\Exports\AuthSessionsExport;
use Modules\Auth\Models\UserPresenceSession;
use Modules\Auth\Services\AuthLogService;
use Modules\Auth\Services\Reports\AuthSessionReport;
use Modules\Auth\Services\UserPresenceService;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\Reports\ReportPdfService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class AuthSessionReportController extends Controller
{
    public function __construct(
        private readonly AuthSessionReport $report,
        private readonly UserPresenceService $presence,
        private readonly AuthLogService $authLogs,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function index(): View
    {
        return view('modules.auth.auth-sessions.index');
    }

    public function data(Request $request, AuthSessionsReportDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function filterUsers(Request $request): JsonResponse
    {
        return response()->json($this->report->userOptions($request));
    }

    public function filterPresenceStatuses(Request $request): JsonResponse
    {
        return response()->json($this->report->presenceStatusOptions($request));
    }

    public function filterAccountStatuses(Request $request): JsonResponse
    {
        return response()->json($this->report->accountStatusOptions($request));
    }

    public function filterDevices(Request $request): JsonResponse
    {
        return response()->json($this->report->deviceOptions($request));
    }

    public function filterBrowsers(Request $request): JsonResponse
    {
        return response()->json($this->report->browserOptions($request));
    }

    public function filterOperatingSystems(Request $request): JsonResponse
    {
        return response()->json($this->report->osOptions($request));
    }

    public function filterOfflineReasons(Request $request): JsonResponse
    {
        return response()->json($this->report->offlineReasonOptions($request));
    }

    public function details(string $session): JsonResponse
    {
        $presenceSession = $this->report->query()
            ->where('user_presence_sessions.public_id', $session)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'title' => __('auth_sessions.details_title'),
            'data' => $this->report->details($presenceSession),
        ]);
    }

    public function exportExcel(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new AuthSessionsExport($this->report, $this->report->filtersFromRequest($request)),
            'auth-sessions.xlsx'
        );
    }

    public function exportCsv(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new AuthSessionsExport($this->report, $this->report->filtersFromRequest($request)),
            'auth-sessions.csv',
            \Maatwebsite\Excel\Excel::CSV
        );
    }

    public function exportPdf(Request $request, ReportPdfService $pdf): Response
    {
        $rows = $this->report->query($this->report->filtersFromRequest($request))
            ->latest('user_presence_sessions.login_at')
            ->limit(500)
            ->get();

        return $pdf->stream('reports.auth-sessions', [
            'title' => __('auth_sessions.report_title'),
            'headings' => $this->report->headings(),
            'rows' => $rows->map(fn ($row): array => $this->report->map($row))->all(),
            'limited' => $rows->count() === 500,
        ], 'active-sessions-report.pdf');
    }

    public function forceLogout(Request $request, string $session): JsonResponse
    {
        $presenceSession = UserPresenceSession::query()
            ->where('public_id', $session)
            ->firstOrFail();

        $currentFingerprint = $this->presence->sessionFingerprint($request);

        if ($currentFingerprint !== null && $presenceSession->session_fingerprint === $currentFingerprint) {
            return response()->json([
                'success' => false,
                'message' => __('auth_sessions.messages.current_session_forbidden'),
            ], 422);
        }

        if (! $this->presence->isFreshActiveSession($presenceSession)) {
            return response()->json([
                'success' => false,
                'message' => __('auth_sessions.messages.session_not_active'),
            ], 422);
        }

        DB::transaction(function () use ($presenceSession): void {
            $presenceSession->forceFill([
                'status' => UserPresenceService::StatusOffline,
                'offline_reason' => UserPresenceService::ReasonForcedLogout,
                'logout_at' => now(),
                'updated_at' => now(),
            ])->save();
        });

        $sessionDeleted = $this->presence->deleteDatabaseSessionForFingerprint($presenceSession->session_fingerprint);

        $this->authLogs->log($request, 'session_force_logout', 'success', [
            'user_id' => $presenceSession->user_id,
            'failure_reason' => UserPresenceService::ReasonForcedLogout,
            'target_session_public_id' => $presenceSession->public_id,
            'database_session_deleted' => $sessionDeleted,
        ]);

        $this->activityLogger->log($request, 'auth', 'auth.sessions.force_logout', 'success', [
            'properties_only' => true,
            'properties' => [
                'target_session_public_id' => $presenceSession->public_id,
                'target_user_doc_num' => $presenceSession->user?->doc_num,
                'target_user_name' => $presenceSession->user?->name,
                'database_session_deleted' => $sessionDeleted,
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => __('auth_sessions.messages.force_logout_success'),
        ]);
    }
}

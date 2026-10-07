<?php

namespace Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Core\Services\DateFormatService;
use Modules\HR\Models\HrShift;
use Modules\Production\Http\Requests\ProductionDailyReportRequest;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionDailyReportCorrectionService;
use Modules\Production\Services\ProductionDailyReportService;
use Modules\Production\Services\ProductionHandoverService;
use Modules\Production\Services\ProductionPieceOutputApprovalService;
use Modules\Production\Services\ProductionQualityQuantityService;

class ProductionDailyReportController extends Controller
{
    public function __construct(private readonly ProductionDailyReportService $service, private readonly ProductionHandoverService $handovers) {}

    public function create(Request $request, ProductionRun $productionRun): View
    {
        try {
            $runs = $this->handovers->runs($productionRun);
        } catch (DomainException) {
            abort(404);
        }
        $kind = $request->query('kind', 'injection');
        abort_unless(in_array($kind, ['injection', 'cover'], true), 404);

        return view('modules.production.runs.daily-report', ['record' => $productionRun, 'runs' => $runs, 'kind' => $kind,
            'shifts' => HrShift::query()->where('status', 'active')->orderBy('name')->get()]);
    }

    public function store(ProductionDailyReportRequest $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        try {
            $entries = $this->service->record($productionRun, $request->validated());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['report' => $exception->getMessage()]);
        }
        $url = route('admin.production.runs.show', $productionRun);

        return $request->expectsJson() ? response()->json(['success' => true, 'entry_public_ids' => $entries->pluck('public_id'), 'redirect_url' => $url])
            : redirect($url)->with('success', __('Saved successfully.'));
    }

    public function correction(ProductionRun $productionRun, string $entry): View
    {
        return view('modules.production.runs.daily-report-correction', app(ProductionDailyReportCorrectionService::class)->preview($productionRun, $entry));
    }

    public function approvePieceOutput(Request $request, ProductionRun $productionRun): RedirectResponse
    {
        $data = $request->validate(['confirmed' => ['required', 'accepted'], 'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'evidence' => ['required', 'string', 'min:5', 'max:2000']]);
        try {
            app(ProductionPieceOutputApprovalService::class)->approve($productionRun, $data['reason'], $data['evidence']);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['piece_output' => $exception->getMessage()]);
        }

        return redirect()->route('admin.production.runs.show', $productionRun);
    }

    public function withdrawPieceOutput(Request $request, ProductionRun $productionRun): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000'], 'evidence' => ['required', 'string', 'min:5', 'max:2000']]);
        try {
            app(ProductionPieceOutputApprovalService::class)->prepareWithdrawal($productionRun, $data['reason'], $data['evidence']);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['piece_output' => $exception->getMessage()]);
        }

        return redirect()->route('admin.production.runs.show', $productionRun);
    }

    public function prepareCorrection(Request $request, ProductionRun $productionRun, string $entry): JsonResponse|RedirectResponse
    {
        if (is_string($request->input('posting_date'))) {
            $request->merge(['posting_date' => app(DateFormatService::class)->normalizeForStorage($request->input('posting_date')) ?? $request->input('posting_date')]);
        }
        $data = $request->validate(['document_error' => ['required', 'accepted'],
            'rejected_quantity' => ['nullable', 'numeric', 'min:0', 'decimal:0,8'],
            'rework_quantity' => ['nullable', 'numeric', 'min:0', 'decimal:0,8'],
            'scrap_quantity' => ['nullable', 'numeric', 'min:0', 'decimal:0,8'], 'quantity' => ['required', 'numeric', 'min:0', 'decimal:0,8'], 'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'evidence' => ['required', 'string', 'min:5', 'max:2000'], 'fingerprint' => ['required', 'string', 'size:64'], 'posting_date' => ['required', 'date_format:Y-m-d']]);
        try {
            $proposal = app(ProductionDailyReportCorrectionService::class)->prepare($productionRun, $entry,
                (string) $data['quantity'], $data['reason'], $data['evidence'], $data['fingerprint'], $data['posting_date'], collect(['rejected' => $data['rejected_quantity'] ?? null,
                    'rework' => $data['rework_quantity'] ?? null, 'scrap' => $data['scrap_quantity'] ?? null])->filter(fn ($value): bool => $value !== null)->all());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return $request->expectsJson() ? response()->json(['success' => true, 'data' => ['correction_id' => $proposal->id]])
            : redirect()->route('admin.production.runs.daily-reports.correction', [$productionRun, $entry])->with('success', __('production_run_correction.prepared'));
    }

    public function withdrawQuality(Request $request, ProductionRun $productionRun, string $entry, int $batch): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000'], 'evidence' => ['required', 'string', 'min:5', 'max:2000']]);
        try {
            app(ProductionQualityQuantityService::class)->withdrawForDailyCorrection($productionRun, $batch, $data['reason'], $data['evidence']);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return $request->expectsJson() ? response()->json(['success' => true])
            : redirect()->route('admin.production.runs.daily-reports.correction', [$productionRun, $entry])->with('success', __('production_daily_report.correction.quality_withdrawn'));
    }
}

<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Auth\Services\UserPresenceService;
use Modules\Core\Http\Requests\SelectOperatingContextRequest;
use Modules\Core\Services\OperatingContextService;

class OperatingContextController extends Controller
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly UserPresenceService $presence,
    ) {}

    public function options(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->context->options($request),
        ]);
    }

    public function select(SelectOperatingContextRequest $request): JsonResponse
    {
        $context = $this->context->select(
            $request,
            (string) $request->validated('company_doc_num'),
            (string) $request->validated('branch_doc_num'),
            (string) $request->validated('financial_period_doc_num'),
        );

        $this->presence->touch($request, $request->user(), true, [
            'event' => 'operating_context_selected',
        ]);

        return response()->json([
            'success' => true,
            'message' => __('operating_context.messages.saved'),
            'reload' => true,
            'data' => [
                'current' => $context,
            ],
        ]);
    }

    public function clear(Request $request): JsonResponse
    {
        $this->context->clear($request);

        return response()->json([
            'success' => true,
            'data' => [
                'current' => $this->context->current($request),
            ],
        ]);
    }
}

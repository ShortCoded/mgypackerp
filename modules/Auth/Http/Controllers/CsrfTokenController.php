<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CsrfTokenController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->isAllowedAjaxJsonRequest($request) || $this->isCrossSiteFetch($request)) {
            return $this->jsonResponse(['ok' => false], 403);
        }

        return $this->jsonResponse([
            'ok' => true,
            'csrf_token' => csrf_token(),
        ]);
    }

    private function isAllowedAjaxJsonRequest(Request $request): bool
    {
        $accept = strtolower($request->headers->get('Accept', ''));

        return str_contains($accept, 'application/json') && $request->ajax();
    }

    private function isCrossSiteFetch(Request $request): bool
    {
        $fetchSite = strtolower($request->headers->get('Sec-Fetch-Site', ''));

        return $fetchSite !== '' && ! in_array($fetchSite, ['same-origin', 'same-site', 'none'], true);
    }

    /**
     * @param  array<string, bool|string>  $payload
     */
    private function jsonResponse(array $payload, int $status = 200): JsonResponse
    {
        return response()
            ->json($payload, $status)
            ->withHeaders([
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0, private',
                'Pragma' => 'no-cache',
                'Expires' => 'Fri, 01 Jan 1990 00:00:00 GMT',
            ]);
    }
}

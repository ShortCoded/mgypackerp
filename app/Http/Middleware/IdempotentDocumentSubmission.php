<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Services\OperatingContextService;
use Symfony\Component\HttpFoundation\Response;

class IdempotentDocumentSubmission
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $mode = 'optional'): Response
    {
        $token = $request->input('_submission_token', $request->header('Idempotency-Key'));
        if (! $request->isMethod('POST')) {
            return $next($request);
        }
        if (! $token) {
            abort_if($mode === 'required', 422, __('The document submission token is invalid.'));

            return $next($request);
        }
        abort_unless(is_string($token) && Str::isUuid($token), 422, __('The document submission token is invalid.'));
        if ($request->routeIs('admin.purchases.purchase-invoices.store')) {
            abort_unless($request->user()?->can($request->filled('clone_source_token') ? 'purchase_invoices.clone' : 'purchase_invoices.create'), 403);
        }
        $context = app(OperatingContextService::class)->snapshot($request);
        abort_unless($context['company_id'] && $request->user(), 403);
        $identity = ['company_id' => $context['company_id'], 'user_id' => $request->user()->getKey(),
            'operation' => (string) $request->route()?->getName(), 'token' => $token];
        $hash = hash('sha256', json_encode($this->canonicalize([
            'path' => $request->path(),
            'branch_id' => $context['branch_id'],
            'financial_period_id' => $context['financial_period_id'],
            'input' => Arr::except($request->input(), ['_token', '_submission_token']),
            'files' => $request->allFiles(),
        ]), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($identity, $hash, $request, $next): Response {
            DB::table('document_submissions')->insertOrIgnore([...$identity, 'payload_hash' => $hash, 'created_at' => now(), 'updated_at' => now()]);
            $submission = DB::table('document_submissions')->where($identity)->lockForUpdate()->first();
            abort_unless($submission && hash_equals($submission->payload_hash, $hash), 409, __('This form was already submitted with different values. Open a new form to create another document.'));
            if ($submission->response_status !== null) {
                return new Response($submission->response_body, $submission->response_status, json_decode($submission->response_headers, true, flags: JSON_THROW_ON_ERROR));
            }
            $response = $next($request);
            if ($response->getStatusCode() >= 400 || ($request->hasSession() && $request->session()->get('errors')?->any())) {
                DB::table('document_submissions')->where('id', $submission->id)->delete();

                return $response;
            }
            $headers = array_intersect_key($response->headers->all(), array_flip(['content-type', 'location']));
            DB::table('document_submissions')->where('id', $submission->id)->update([
                'response_status' => $response->getStatusCode(), 'response_body' => $response->getContent(),
                'response_headers' => json_encode($headers, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);

            return $response;
        }, 3);
    }

    private function canonicalize(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            $path = $value->getRealPath();

            return [
                'original_name' => $value->getClientOriginalName(),
                'client_mime_type' => $value->getClientMimeType(),
                'size' => $value->getSize(),
                'upload_error' => $value->getError(),
                'content_sha256' => is_string($path) && is_file($path) && is_readable($path)
                    ? hash_file('sha256', $path)
                    : null,
            ];
        }

        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}

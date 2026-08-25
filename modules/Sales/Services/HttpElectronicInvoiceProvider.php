<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Support\Facades\Http;
use Modules\Sales\Contracts\ElectronicInvoiceProvider;
use RuntimeException;

class HttpElectronicInvoiceProvider implements ElectronicInvoiceProvider
{
    /**
     * Create a new class instance.
     */
    public function submit(array $payload, string $idempotencyKey): array
    {
        $baseUrl = (string) config('e_invoice.base_url');
        $token = (string) config('e_invoice.access_token');
        if ($baseUrl === '' || $token === '') {
            throw new DomainException(__('Electronic Invoice provider credentials are not configured.'));
        }
        $response = Http::baseUrl($baseUrl)
            ->withToken($token)
            ->acceptJson()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->timeout((int) config('e_invoice.timeout_seconds', 20))
            ->connectTimeout(10)
            ->retry([250, 750], throw: false)
            ->post('/documents', $payload);

        if ($response->serverError()) {
            throw new RuntimeException('Electronic Invoice provider is temporarily unavailable.');
        }
        if ($response->clientError()) {
            return ['status' => 'rejected', 'reference' => $response->json('reference'), 'metadata' => ['reason' => $response->json('message', 'Provider rejected the document.')]];
        }

        return [
            'status' => $response->json('status', 'submitted'),
            'reference' => $response->json('uuid', $response->json('reference')),
            'metadata' => ['http_status' => $response->status()],
        ];
    }
}

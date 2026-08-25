<?php

namespace Modules\Sales\Services;

use Modules\Sales\Contracts\ElectronicInvoiceProvider;
use RuntimeException;

class MockElectronicInvoiceProvider implements ElectronicInvoiceProvider
{
    /**
     * Create a new class instance.
     */
    public function submit(array $payload, string $idempotencyKey): array
    {
        return match ((string) config('e_invoice.mock_result', 'accepted')) {
            'rejected' => ['status' => 'rejected', 'reference' => 'MOCK-'.$idempotencyKey, 'metadata' => ['reason' => 'Mock business rejection']],
            'retryable_failure' => throw new RuntimeException('Mock retryable provider failure.'),
            default => ['status' => 'accepted', 'reference' => 'MOCK-'.$idempotencyKey, 'metadata' => ['accepted' => true]],
        };
    }
}

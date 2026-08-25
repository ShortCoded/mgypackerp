<?php

namespace Modules\Sales\Contracts;

interface ElectronicInvoiceProvider
{
    /** @param array<string, mixed> $payload @return array{status: string, reference?: string|null, metadata?: array<string, mixed>} */
    public function submit(array $payload, string $idempotencyKey): array;
}

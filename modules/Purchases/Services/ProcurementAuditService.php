<?php

namespace Modules\Purchases\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Services\ActivityLogger;

class ProcurementAuditService
{
    public function __construct(private readonly ActivityLogger $activities) {}

    /** @param array<string, mixed> $properties */
    public function record(Model $subject, string $action, array $properties = []): void
    {
        $this->activities->log(request(), 'purchases', $action, 'success', [
            'subject' => $subject,
            'company_id' => $subject->getAttribute('company_id'),
            'properties_only' => true,
            'properties' => [
                'doc_num' => $subject->getAttribute('doc_num'),
                'status' => $subject->getAttribute('status'),
                ...$properties,
            ],
        ]);
    }
}

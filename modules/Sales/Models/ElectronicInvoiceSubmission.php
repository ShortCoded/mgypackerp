<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ElectronicInvoiceSubmission extends Model
{
    public const StatusReady = 'ready';

    public const StatusQueued = 'queued';

    public const StatusSubmitted = 'submitted';

    public const StatusAccepted = 'accepted';

    public const StatusRejected = 'rejected';

    public const StatusFailed = 'submission_failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array', 'response_metadata' => 'array',
            'last_attempt_at' => 'datetime', 'submitted_at' => 'datetime', 'accepted_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }
}

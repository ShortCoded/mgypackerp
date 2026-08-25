<?php

namespace Modules\Sales\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Sales\Models\ElectronicInvoiceSubmission;
use Modules\Sales\Services\ElectronicInvoiceService;

class SubmitElectronicInvoice implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public int $tries = 5;

    public function __construct(public readonly int $submissionId) {}

    /**
     * Execute the job.
     */
    public function handle(ElectronicInvoiceService $service): void
    {
        $service->submit(ElectronicInvoiceSubmission::query()->findOrFail($this->submissionId));
    }

    public function uniqueId(): string
    {
        return (string) $this->submissionId;
    }
}

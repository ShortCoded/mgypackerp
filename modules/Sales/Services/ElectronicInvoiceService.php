<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Contracts\ElectronicInvoiceProvider;
use Modules\Sales\Jobs\SubmitElectronicInvoice;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\ElectronicInvoiceSubmission;
use Throwable;

class ElectronicInvoiceService
{
    public function __construct(private readonly ElectronicInvoicePayloadBuilder $payloads) {}

    public function queue(CustomerInvoice $invoice): ElectronicInvoiceSubmission
    {
        if (! config('e_invoice.enabled')) {
            $invoice->forceFill(['electronic_invoice_status' => 'not_configured'])->save();
            throw new DomainException(__('Electronic Invoice submission is disabled until provider credentials are activated.'));
        }

        $submission = DB::transaction(function () use ($invoice): ElectronicInvoiceSubmission {
            $locked = CustomerInvoice::query()->lockForUpdate()->findOrFail($invoice->getKey());
            $payload = $this->payloads->build($locked);
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $provider = (string) config('e_invoice.provider', 'mock');
            $environment = (string) config('e_invoice.environment', 'sandbox');
            $hash = hash('sha256', $json);
            $submission = ElectronicInvoiceSubmission::query()->firstOrCreate([
                'customer_invoice_id' => $locked->getKey(), 'provider' => $provider,
                'environment' => $environment, 'payload_hash' => $hash,
            ], [
                'company_id' => $locked->company_id, 'payload_version' => (string) config('e_invoice.payload_version', '1.0'),
                'payload' => $payload, 'status' => ElectronicInvoiceSubmission::StatusReady,
            ]);
            if (in_array($submission->status, [ElectronicInvoiceSubmission::StatusAccepted, ElectronicInvoiceSubmission::StatusSubmitted], true)) {
                return $submission;
            }
            $submission->forceFill(['status' => ElectronicInvoiceSubmission::StatusQueued])->save();
            $locked->forceFill(['electronic_invoice_status' => 'queued'])->save();

            return $submission;
        }, 3);

        SubmitElectronicInvoice::dispatch($submission->getKey())->afterCommit();

        return $submission->refresh();
    }

    public function submit(ElectronicInvoiceSubmission $submission): ElectronicInvoiceSubmission
    {
        $locked = DB::transaction(function () use ($submission): ElectronicInvoiceSubmission {
            $locked = ElectronicInvoiceSubmission::query()->with('invoice')->lockForUpdate()->findOrFail($submission->getKey());
            if (in_array($locked->status, [ElectronicInvoiceSubmission::StatusAccepted, ElectronicInvoiceSubmission::StatusSubmitted], true)) {
                return $locked;
            }
            $locked->increment('attempt_count');
            $locked->forceFill(['last_attempt_at' => now(), 'error_classification' => null, 'error_message' => null])->save();

            return $locked->refresh()->load('invoice');
        }, 3);

        if (in_array($locked->status, [ElectronicInvoiceSubmission::StatusAccepted, ElectronicInvoiceSubmission::StatusSubmitted], true)) {
            return $locked;
        }

        try {
            $result = $this->provider($locked->provider)->submit($locked->payload, $locked->payload_hash);
        } catch (Throwable $exception) {
            DB::transaction(function () use ($locked): void {
                $failed = ElectronicInvoiceSubmission::query()->with('invoice')->lockForUpdate()->findOrFail($locked->getKey());
                $failed->forceFill([
                    'status' => ElectronicInvoiceSubmission::StatusFailed,
                    'error_classification' => 'retryable_transport',
                    'error_message' => __('The tax authority is temporarily unavailable. The submission remains safe to retry.'),
                ])->save();
                $failed->invoice->forceFill(['electronic_invoice_status' => 'submission_failed'])->save();
            }, 3);

            throw $exception;
        }

        return DB::transaction(function () use ($locked, $result): ElectronicInvoiceSubmission {
            $locked = ElectronicInvoiceSubmission::query()->with('invoice')->lockForUpdate()->findOrFail($locked->getKey());
            if ($locked->status === ElectronicInvoiceSubmission::StatusAccepted) {
                return $locked;
            }

            $status = match ($result['status']) {
                'accepted' => ElectronicInvoiceSubmission::StatusAccepted,
                'rejected' => ElectronicInvoiceSubmission::StatusRejected,
                default => ElectronicInvoiceSubmission::StatusSubmitted,
            };
            $locked->forceFill([
                'status' => $status, 'provider_reference' => $result['reference'] ?? null,
                'submitted_at' => now(), 'accepted_at' => $status === ElectronicInvoiceSubmission::StatusAccepted ? now() : null,
                'error_classification' => $status === ElectronicInvoiceSubmission::StatusRejected ? 'provider_business_rejection' : null,
                'error_message' => $status === ElectronicInvoiceSubmission::StatusRejected ? ($result['metadata']['reason'] ?? __('Provider rejected the document.')) : null,
                'response_metadata' => $result['metadata'] ?? [],
            ])->save();
            $locked->invoice->forceFill([
                'electronic_invoice_status' => $status,
                'electronic_invoice_uuid' => $result['reference'] ?? null,
                'electronic_invoice_response' => $result['metadata'] ?? [],
                'electronic_invoice_submitted_at' => now(),
            ])->save();

            return $locked->refresh();
        }, 3);
    }

    private function provider(string $provider): ElectronicInvoiceProvider
    {
        return match ($provider) {
            'mock' => app(MockElectronicInvoiceProvider::class),
            'http' => app(HttpElectronicInvoiceProvider::class),
            default => throw new DomainException(__('Unsupported Electronic Invoice provider configuration.')),
        };
    }
}

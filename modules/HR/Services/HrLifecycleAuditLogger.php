<?php

namespace Modules\HR\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Core\Services\ActivityLogger;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Throwable;

final class HrLifecycleAuditLogger
{
    /** @var list<string> */
    private array $privateValueKeys = [
        'amount',
        'basic_salary',
        'deductions',
        'details',
        'gross',
        'latitude',
        'longitude',
        'national_id',
        'net',
        'notes',
        'payable',
        'reason',
        'reference',
        'resolution_notes',
        'salary',
    ];

    /** @var list<string> */
    private array $compensationKeyFragments = [
        'allowance',
        'bank',
        'compensation',
        'insurance_contribution',
        'salary',
        'social_insurance',
        'tax',
        'wage',
    ];

    /** @var list<string> */
    private array $personallyIdentifiableKeyFragments = [
        'address',
        'email',
        'emergency_contact',
        'mobile',
        'national_id',
        'passport',
        'phone',
    ];

    public function __construct(private readonly ActivityLogger $activityLogger) {}

    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(
        Request $request,
        string $action,
        int $companyId,
        array $properties = [],
        ?Model $subject = null,
        ?string $deduplicationKey = null,
        bool $redactSensitiveProperties = false,
        ?Model $causer = null,
    ): void {
        try {
            if ($companyId <= 0) {
                return;
            }

            if ($deduplicationKey !== null) {
                $this->logStrict(
                    $request,
                    $action,
                    $companyId,
                    $properties,
                    $subject,
                    $deduplicationKey,
                    $redactSensitiveProperties,
                    $causer,
                );

                return;
            }

            $safeProperties = $this->safeProperties($properties, $redactSensitiveProperties);

            $this->activityLogger->log($request, 'hr', $action, 'success', [
                'company_id' => $companyId,
                'subject' => $subject,
                'causer' => $causer ?? $request->user(),
                'properties_only' => true,
                'properties' => $safeProperties,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Persist a required lifecycle entry without swallowing failures.
     *
     * The caller must invoke this method inside the same database transaction as
     * the business transition. The unique column makes retries atomic without a
     * check-then-insert race.
     *
     * @param  array<string, mixed>  $properties
     */
    public function logStrict(
        Request $request,
        string $action,
        int $companyId,
        array $properties,
        ?Model $subject,
        string $deduplicationKey,
        bool $redactSensitiveProperties = false,
        ?Model $causer = null,
    ): void {
        if ($companyId <= 0) {
            throw new RuntimeException('HR lifecycle audit requires an explicit company.');
        }

        if (trim($deduplicationKey) === '') {
            throw new RuntimeException('HR lifecycle audit requires a stable deduplication key.');
        }

        $deduplicationHash = hash('sha256', implode('|', ['hr', $action, (string) $companyId, $deduplicationKey]));
        $safeProperties = $this->safeProperties($properties, $redactSensitiveProperties);
        $safeProperties['deduplication_hash'] = $deduplicationHash;
        $causer ??= $request->user();
        $activity = new Activity;
        $connection = $activity->getConnection();
        $tableName = $activity->getTable();
        $now = now();

        if ($connection->transactionLevel() < 1) {
            throw new RuntimeException('Strict HR lifecycle audit must share an active database transaction.');
        }

        $inserted = $connection->table($tableName)->insertOrIgnore([
            'log_name' => 'hr',
            'description' => 'hr.'.$action.'.success',
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'causer_type' => $causer?->getMorphClass(),
            'causer_id' => $causer?->getKey(),
            'properties' => json_encode($safeProperties, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
            'event' => $action,
            'batch_uuid' => null,
            'company_id' => $companyId,
            'module' => 'hr',
            'action' => $action,
            'status' => 'success',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'public_id' => (string) Str::uuid(),
            'deduplication_key' => $deduplicationHash,
        ]);

        if ($inserted === 1) {
            return;
        }

        $matchingEntryExists = $connection->table($tableName)
            ->where('deduplication_key', $deduplicationHash)
            ->where('company_id', $companyId)
            ->where('module', 'hr')
            ->where('action', $action)
            ->where('status', 'success')
            ->exists();

        if (! $matchingEntryExists) {
            throw new RuntimeException('HR lifecycle audit entry could not be persisted.');
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function safeProperties(array $properties, bool $redactSensitiveProperties): array
    {
        $safe = [];

        foreach ($properties as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                if ($redactSensitiveProperties) {
                    $safe[$key] = ['changed' => true, 'redacted' => true];
                }

                continue;
            }

            $safe[$key] = is_array($value)
                ? $this->safeProperties($value, $redactSensitiveProperties)
                : $value;
        }

        return $safe;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = Str::snake(strtolower($key));

        if (in_array($normalized, $this->privateValueKeys, true)) {
            return true;
        }

        if (Str::startsWith($normalized, 'has_')) {
            return false;
        }

        if (Str::endsWith($normalized, ['_details', '_notes', '_reason', '_reference'])) {
            return true;
        }

        if (Str::endsWith($normalized, ['_status', '_id', '_ids', '_doc_num', '_public_uuid'])) {
            return false;
        }

        return Str::contains($normalized, [
            ...$this->compensationKeyFragments,
            ...$this->personallyIdentifiableKeyFragments,
        ]);
    }
}

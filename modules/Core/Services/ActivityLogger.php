<?php

namespace Modules\Core\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Throwable;

class ActivityLogger
{
    /**
     * @var list<string>|null
     */
    protected ?array $activityLogColumns = null;

    public function __construct(
        private readonly RequestMemo $memo,
    ) {}

    /**
     * @var list<string>
     */
    protected array $sensitiveKeys = [
        '_token',
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'remember_token',
    ];

    /**
     * @param  array<string, mixed>  $context
     */
    public function log(Request $request, string $module, string $action, string $status, array $context = []): ?ActivityContract
    {
        $subject = $this->subject($context);
        $causer = $this->causer($request, $status, $context);

        $logger = activity($module)
            ->useLog($module)
            ->event($action)
            ->withProperties($this->properties($request, $context))
            ->tap(function ($activity) use ($request, $module, $action, $status, $context): void {
                $this->fillExtraColumns($activity, $request, $module, $action, $status, $context);
            });

        if ($subject instanceof Model) {
            $logger->performedOn($subject);
        }

        if ($causer instanceof Model) {
            $logger->causedBy($causer);
        } else {
            $logger->causedByAnonymous();
        }

        return $logger->log($this->description($module, $action, $status));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function properties(Request $request, array $context): array
    {
        if (($context['properties_only'] ?? false) === true) {
            return $this->cleanProperties($this->safeData($context['properties'] ?? []));
        }

        $properties = [
            'identifier' => $context['identifier'] ?? $request->input('login') ?? $request->input('email'),
            'email' => $context['email'] ?? null,
            'phone' => $context['phone'] ?? null,
            'username' => $context['username'] ?? null,
            'reason' => $context['failure_reason'] ?? $context['reason'] ?? null,
            'guard' => $context['guard'] ?? 'web',
            'auth_log_id' => $context['auth_log_id'] ?? null,
            'user_id' => $this->subject($context)?->getKey(),
            'request_fields' => $this->requestFields($request),
        ];

        if (isset($context['properties']) && is_array($context['properties'])) {
            $properties = array_merge($properties, $this->safeData($context['properties']));
        }

        return collect($properties)
            ->reject(fn (mixed $value): bool => $value === null || $value === '' || $value === [])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    protected function cleanProperties(array $properties): array
    {
        return collect($properties)
            ->reject(fn (mixed $value): bool => $value === null)
            ->all();
    }

    /**
     * @return list<string>
     */
    protected function requestFields(Request $request): array
    {
        return collect(array_keys($request->except($this->sensitiveKeys)))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function safeData(array $data): array
    {
        return collect($data)
            ->reject(fn (mixed $value, string|int $key): bool => is_string($key) && in_array($key, $this->sensitiveKeys, true))
            ->map(function (mixed $value): mixed {
                if ($value instanceof Model) {
                    return [
                        'type' => $value->getTable(),
                        'label' => $value->getAttribute('name') ?: $value->getAttribute('title') ?: $value->getAttribute('label'),
                        'doc_num' => $value->getAttribute('doc_num'),
                    ];
                }

                if (is_array($value)) {
                    return $this->safeData($value);
                }

                return $value;
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function subject(array $context): ?Model
    {
        $subject = $context['subject'] ?? $context['user'] ?? null;

        return $subject instanceof Model ? $subject : null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function causer(Request $request, string $status, array $context): ?Model
    {
        $causer = $context['causer'] ?? null;

        if ($causer instanceof Model) {
            return $causer;
        }

        if ($status === 'success' && ($context['user'] ?? null) instanceof Model) {
            return $context['user'];
        }

        $requestUser = $request->user();

        return $requestUser instanceof Model ? $requestUser : null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function fillExtraColumns(mixed $activity, Request $request, string $module, string $action, string $status, array $context): void
    {
        $values = [
            'public_id' => (string) Str::uuid(),
            'company_id' => $this->companyId($context),
            'module' => $module,
            'action' => $action,
            'status' => $status,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url' => $request->fullUrl(),
            'method' => $request->method(),
        ];

        foreach ($values as $column => $value) {
            if ($this->hasActivityLogColumn($column)) {
                $activity->{$column} = $value;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function companyId(array $context): mixed
    {
        if (array_key_exists('company_id', $context)) {
            return $context['company_id'];
        }

        $subject = $this->subject($context);

        return $subject instanceof Model && isset($subject->company_id)
            ? $subject->company_id
            : null;
    }

    protected function hasActivityLogColumn(string $column): bool
    {
        if ($this->activityLogColumns === null) {
            $tableName = config('activitylog.table_name', 'activity_log');
            $schema = Schema::connection(config('activitylog.database_connection'));

            $this->activityLogColumns = $this->memo->remember("schema.columns.{$tableName}", function () use ($schema, $tableName): array {
                try {
                    return $schema->hasTable($tableName)
                        ? $schema->getColumnListing($tableName)
                        : [];
                } catch (Throwable) {
                    return [];
                }
            });
        }

        return in_array($column, $this->activityLogColumns, true);
    }

    protected function description(string $module, string $action, string $status): string
    {
        return $module.'.'.$action.'.'.$status;
    }
}

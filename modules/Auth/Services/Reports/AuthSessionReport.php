<?php

namespace Modules\Auth\Services\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Auth\Models\UserPresenceSession;
use Modules\Auth\Services\UserPresenceService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\Reports\ReportSanitizer;

class AuthSessionReport
{
    public function __construct(
        private readonly DateFormatService $dates,
        private readonly UserPresenceService $presence,
        private readonly ReportSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<UserPresenceSession>
     */
    public function query(array $filters = [], bool $listing = false): Builder
    {
        $query = UserPresenceSession::query()
            ->leftJoin('users', 'users.id', '=', 'user_presence_sessions.user_id')
            ->select($listing ? $this->listingColumns() : [
                'user_presence_sessions.*',
                'users.name as user_name',
                'users.doc_num as user_doc_num',
                'users.username as username',
                'users.email as email',
                'users.phone as phone',
                'users.status as account_status',
            ]);

        $this->presence->applyFreshActiveSessionConstraints($query);

        return $this->applyFilters($query, $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<UserPresenceSession>
     */
    public function listingQuery(array $filters = []): Builder
    {
        return $this->query($filters, listing: true);
    }

    /**
     * @return list<string>
     */
    private function listingColumns(): array
    {
        return [
            'user_presence_sessions.id',
            'user_presence_sessions.public_id',
            'user_presence_sessions.user_id',
            'user_presence_sessions.status',
            'user_presence_sessions.ip_address',
            'user_presence_sessions.browser_name',
            'user_presence_sessions.os_name',
            'user_presence_sessions.device_type',
            'user_presence_sessions.login_at',
            'user_presence_sessions.last_seen_at',
            'user_presence_sessions.last_activity_at',
            'user_presence_sessions.locked_at',
            'user_presence_sessions.logout_at',
            'user_presence_sessions.expires_at',
            'user_presence_sessions.offline_reason',
            'user_presence_sessions.branch_id',
            'user_presence_sessions.branch_doc_num',
            'user_presence_sessions.branch_name',
            'user_presence_sessions.financial_period_id',
            'user_presence_sessions.financial_period_doc_num',
            'user_presence_sessions.financial_period_name',
            'user_presence_sessions.created_at',
            'users.name as user_name',
            'users.doc_num as user_doc_num',
            'users.username as username',
            'users.email as email',
            'users.phone as phone',
            'users.status as account_status',
        ];
    }

    /**
     * @param  Builder<UserPresenceSession>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<UserPresenceSession>
     */
    public function applyFilters(Builder $query, array $filters): Builder
    {
        $this->dateRange($query, $filters, 'user_presence_sessions.login_at');

        $status = $this->stringFilter($filters['status'] ?? null);

        if ($status !== null) {
            $this->presence->applyEffectivePresenceStatusConstraint($query, $status);
        }

        foreach (['device_type', 'browser_name', 'os_name'] as $field) {
            $value = $this->stringFilter($filters[$field] ?? null);

            if ($value !== null) {
                $query->where("user_presence_sessions.{$field}", $value);
            }
        }

        $accountStatus = $this->stringFilter($filters['account_status'] ?? null);

        if ($accountStatus !== null) {
            $query->where('users.status', $accountStatus);
        }

        $ip = $this->stringFilter($filters['ip'] ?? null);

        if ($ip !== null) {
            $query->where('user_presence_sessions.ip_address', 'LIKE', "%{$ip}%");
        }

        $user = $this->stringFilter($filters['user'] ?? null);

        if ($user !== null) {
            $query->where(function (Builder $query) use ($user): void {
                $query->where('users.name', 'LIKE', "%{$user}%")
                    ->orWhere('users.doc_num', 'LIKE', "%{$user}%")
                    ->orWhere('users.username', 'LIKE', "%{$user}%")
                    ->orWhere('users.email', 'LIKE', "%{$user}%")
                    ->orWhere('users.phone', 'LIKE', "%{$user}%");
            });
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function filtersFromRequest(Request $request): array
    {
        return $request->only([
            'date_from',
            'date_to',
            'status',
            'account_status',
            'user',
            'device_type',
            'browser_name',
            'os_name',
            'ip',
        ]);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            __('auth_sessions.fields.user'),
            __('auth_sessions.fields.branch'),
            __('auth_sessions.fields.financial_period'),
            __('auth_sessions.fields.account_status'),
            __('auth_sessions.fields.presence'),
            __('auth_sessions.fields.device'),
            __('auth_sessions.fields.ip_address'),
            __('auth_sessions.fields.login_at'),
            __('auth_sessions.fields.last_seen_at'),
            __('auth_sessions.fields.duration'),
        ];
    }

    /**
     * @return list<string>
     */
    public function map(UserPresenceSession $session): array
    {
        $row = $this->row($session);

        return [
            $row['user'],
            $row['branch_label'],
            $row['financial_period_label'],
            $row['account_status'],
            $row['presence'],
            $row['device'],
            $row['ip_address'],
            $row['login_at'],
            $row['last_seen_at'],
            $row['duration'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function row(UserPresenceSession $session): array
    {
        $presenceStatus = $this->presence->effectivePresenceStatus($session);

        return [
            'user' => $this->userLabel($session),
            'branch_label' => $this->operatingContextLabel($session->branch_name, $session->branch_doc_num) ?: __('auth_sessions.messages.not_selected'),
            'financial_period_label' => $this->operatingContextLabel($session->financial_period_name, $session->financial_period_doc_num) ?: __('auth_sessions.messages.not_selected'),
            'account_status' => $this->accountStatusLabel($session->account_status),
            'account_status_class' => $this->accountStatusClass($session->account_status),
            'presence' => $this->presenceStatusLabel($presenceStatus),
            'presence_class' => $this->presenceStatusClass($presenceStatus),
            'device' => $this->deviceSummary($session),
            'ip_address' => $this->displayText($session->ip_address),
            'login_at' => $this->date($session->login_at),
            'last_seen_at' => $this->date($session->last_seen_at),
            'duration' => $this->duration($session),
            'offline_reason' => $this->offlineReasonLabel($session->offline_reason),
            'summary' => $this->summary($session),
        ];
    }

    /**
     * @return array{summary: string, sections: list<array{title: string, collapsed?: bool, items: list<array{label: string, value: mixed, type?: string}>}>}
     */
    public function details(UserPresenceSession $session): array
    {
        $row = $this->row($session);
        $technicalContext = $this->technicalContextJson($session);

        return [
            'summary' => $row['summary'],
            'sections' => [
                [
                    'title' => __('auth_sessions.fields.summary'),
                    'items' => [
                        ['label' => __('auth_sessions.fields.summary'), 'value' => $row['summary']],
                        ['label' => __('auth_sessions.fields.user'), 'value' => $row['user']],
                    ],
                ],
                [
                    'title' => __('auth_sessions.fields.session_information'),
                    'items' => [
                        ['label' => __('auth_sessions.fields.account_status'), 'value' => $row['account_status']],
                        ['label' => __('auth_sessions.fields.presence'), 'value' => $row['presence']],
                        ['label' => __('auth_sessions.fields.login_at'), 'value' => $row['login_at']],
                        ['label' => __('auth_sessions.fields.last_seen_at'), 'value' => $row['last_seen_at']],
                        ['label' => __('auth_sessions.fields.last_activity_at'), 'value' => $this->date($session->last_activity_at)],
                        ['label' => __('auth_sessions.fields.locked_at'), 'value' => $this->date($session->locked_at)],
                        ['label' => __('auth_sessions.fields.logout_at'), 'value' => $this->date($session->logout_at)],
                        ['label' => __('auth_sessions.fields.expires_at'), 'value' => $this->date($session->expires_at)],
                        ['label' => __('auth_sessions.fields.offline_reason'), 'value' => $row['offline_reason']],
                        ['label' => __('auth_sessions.fields.ip_address'), 'value' => $row['ip_address']],
                        ['label' => __('auth_sessions.fields.device'), 'value' => $row['device']],
                        ['label' => __('auth_sessions.fields.user_agent'), 'value' => $this->userAgentSummary($session->user_agent)],
                        ['label' => __('auth_sessions.fields.duration'), 'value' => $row['duration']],
                    ],
                ],
                [
                    'title' => __('auth_sessions.fields.operating_context'),
                    'items' => [
                        ['label' => __('auth_sessions.fields.branch'), 'value' => $row['branch_label']],
                        ['label' => __('auth_sessions.fields.financial_period'), 'value' => $row['financial_period_label']],
                    ],
                ],
                [
                    'title' => __('auth_sessions.fields.technical_context'),
                    'collapsed' => true,
                    'items' => [
                        [
                            'label' => __('auth_sessions.fields.context_json'),
                            'value' => $technicalContext ?? __('auth_sessions.messages.no_technical_details'),
                            'type' => $technicalContext !== null ? 'json' : 'text',
                        ],
                    ],
                ],
            ],
        ];
    }

    public function userLabel(UserPresenceSession $session): string
    {
        $parts = array_values(array_filter([
            $this->displayText($session->user_name),
            $this->displayText($session->user_doc_num),
        ], fn (string $value): bool => $value !== ''));

        return $parts === [] ? '' : implode(' / ', $parts);
    }

    public function operatingContextLabel(mixed $name, mixed $docNum): string
    {
        $parts = array_values(array_filter([
            $this->displayText($name),
            $this->displayText($docNum),
        ], fn (string $value): bool => $value !== ''));

        return $parts === [] ? '' : implode(' / ', $parts);
    }

    public function duration(UserPresenceSession $session): string
    {
        $start = $session->login_at ?? $session->created_at;
        $end = $session->logout_at ?? now();

        if (! $start instanceof Carbon) {
            return '';
        }

        return $start->diffForHumans($end, true);
    }

    public function summary(UserPresenceSession $session): string
    {
        return __('auth_sessions.summaries.session_for_user', [
            'user' => $this->userLabel($session),
            'status' => $this->presenceStatusLabel($this->presence->effectivePresenceStatus($session)),
        ]);
    }

    public function userAgentSummary(?string $userAgent): string
    {
        $userAgent = $this->displayText($userAgent);

        if ($userAgent === '') {
            return '';
        }

        return mb_substr($userAgent, 0, 180);
    }

    public function date(mixed $date): string
    {
        return $this->dates->formatDateTime($date, '');
    }

    public function presenceStatusLabel(mixed $status): string
    {
        return $this->translatedValue('auth_sessions.presence_statuses', $status);
    }

    public function presenceStatusClass(mixed $status): string
    {
        return match (trim((string) $status)) {
            'online' => 'success',
            'idle' => 'warning',
            'locked' => 'info',
            'offline' => 'secondary',
            default => 'secondary',
        };
    }

    public function accountStatusClass(mixed $status): string
    {
        return match (trim((string) $status)) {
            'active' => 'success',
            'inactive' => 'warning',
            'blocked' => 'danger',
            default => 'secondary',
        };
    }

    public function accountStatusLabel(mixed $status): string
    {
        return $this->translatedValue('users.statuses', $status);
    }

    public function offlineReasonLabel(mixed $reason): string
    {
        $reason = $this->displayText($reason);

        return $reason === '' ? '' : $this->translatedValue('auth_sessions.offline_reasons', $reason);
    }

    public function deviceTypeLabel(mixed $deviceType): string
    {
        $deviceType = $this->displayText($deviceType);

        return $deviceType === '' ? '' : $this->translatedValue('auth_sessions.device_types', $deviceType);
    }

    public function deviceSummary(UserPresenceSession $session): string
    {
        $browser = $this->displayText($session->browser_name);
        $os = $this->displayText($session->os_name);
        $device = $this->deviceTypeLabel($session->device_type);

        if ($browser === '' && $os === '' && $device === '') {
            return '';
        }

        if ($browser !== '' && $os !== '' && $device !== '') {
            return __('auth_sessions.messages.device_summary', [
                'browser' => $browser,
                'os' => $os,
                'device' => $device,
            ]);
        }

        return implode(' - ', array_filter([$browser, $os, $device]));
    }

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function userOptions(Request $request): array
    {
        $term = $this->stringFilter($request->input('q')) ?? '';

        $users = UserPresenceSession::query()
            ->join('users', 'users.id', '=', 'user_presence_sessions.user_id')
            ->select([
                'users.doc_num',
                'users.name',
                'users.username',
                'users.email',
                'users.phone',
            ])
            ->distinct();

        $this->presence->applyFreshActiveSessionConstraints($users);

        $users = $users
            ->when($term !== '', function (Builder $query) use ($term): void {
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('users.doc_num', 'LIKE', "%{$term}%")
                        ->orWhere('users.name', 'LIKE', "%{$term}%")
                        ->orWhere('users.username', 'LIKE', "%{$term}%")
                        ->orWhere('users.email', 'LIKE', "%{$term}%")
                        ->orWhere('users.phone', 'LIKE', "%{$term}%");
                });
            })
            ->orderBy('users.name')
            ->limit(20)
            ->get();

        return [
            'results' => $users->map(fn (UserPresenceSession $user): array => [
                'id' => (string) $user->doc_num,
                'text' => implode(' / ', array_filter([$user->name, $user->username, $user->email, $user->phone, $user->doc_num])),
            ])->all(),
            'pagination' => ['more' => false],
        ];
    }

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function presenceStatusOptions(Request $request): array
    {
        $term = mb_strtolower($this->stringFilter($request->input('q')) ?? '');

        $statuses = collect([
            UserPresenceService::StatusOnline,
            UserPresenceService::StatusIdle,
            UserPresenceService::StatusLocked,
        ])
            ->filter(fn (string $status): bool => $term === ''
                || str_contains($status, $term)
                || str_contains(mb_strtolower($this->presenceStatusLabel($status)), $term))
            ->map(fn (string $status): array => ['id' => $status, 'text' => $this->presenceStatusLabel($status)])
            ->values()
            ->all();

        return ['results' => $statuses, 'pagination' => ['more' => false]];
    }

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function accountStatusOptions(Request $request): array
    {
        $term = $this->stringFilter($request->input('q')) ?? '';
        $statuses = collect(['active', 'inactive', 'blocked'])
            ->filter(fn (string $status): bool => $term === '' || str_contains($status, mb_strtolower($term)) || str_contains(mb_strtolower($this->accountStatusLabel($status)), mb_strtolower($term)))
            ->map(fn (string $status): array => ['id' => $status, 'text' => $this->accountStatusLabel($status)])
            ->values()
            ->all();

        return ['results' => $statuses, 'pagination' => ['more' => false]];
    }

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function deviceOptions(Request $request): array
    {
        return $this->distinctOptions($request, 'device_type', fn (string $value): string => $this->deviceTypeLabel($value));
    }

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function browserOptions(Request $request): array
    {
        return $this->distinctOptions($request, 'browser_name');
    }

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function osOptions(Request $request): array
    {
        return $this->distinctOptions($request, 'os_name');
    }

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function offlineReasonOptions(Request $request): array
    {
        return ['results' => [], 'pagination' => ['more' => false]];
    }

    /**
     * @param  Builder<UserPresenceSession>  $query
     * @param  array<string, mixed>  $filters
     */
    private function dateRange(Builder $query, array $filters, string $column): void
    {
        $from = $this->dateFilter($filters['date_from'] ?? null);
        $to = $this->dateFilter($filters['date_to'] ?? null);

        if ($from instanceof Carbon) {
            $query->where($column, '>=', $from->startOfDay());
        }

        if ($to instanceof Carbon) {
            $query->where($column, '<=', $to->endOfDay());
        }
    }

    private function dateFilter(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->dates->parseDate($value);
    }

    private function stringFilter(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = $this->displayText($value);

        return $value === '' ? null : $value;
    }

    private function translatedValue(string $root, mixed $value): string
    {
        $value = $this->displayText($value);

        if ($value === '') {
            return '';
        }

        $translations = __($root);
        $translated = is_array($translations) ? data_get($translations, $value) : null;

        return is_string($translated) && $translated !== ''
            ? $translated
            : Str::of(str_replace(['.', '_'], ' ', $value))->headline()->toString();
    }

    private function technicalContextJson(UserPresenceSession $session): ?string
    {
        if (! is_array($session->context) || $session->context === []) {
            return null;
        }

        $sanitized = $this->sanitizer->redactOriginalPropertiesForReport($session->context);

        if ($sanitized === []) {
            return null;
        }

        $json = json_encode($sanitized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) && $json !== '' ? $json : null;
    }

    /**
     * @param  null|callable(string): string  $labeler
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    private function distinctOptions(Request $request, string $column, ?callable $labeler = null): array
    {
        $term = $this->stringFilter($request->input('q')) ?? '';

        $query = UserPresenceSession::query()
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->when($term !== '', fn (Builder $query): Builder => $query->where($column, 'LIKE', "%{$term}%"));

        $this->presence->applyFreshActiveSessionConstraints($query);

        $values = $query
            ->distinct()
            ->orderBy($column)
            ->limit(30)
            ->pluck($column);

        return [
            'results' => $values
                ->map(function (mixed $value) use ($labeler): array {
                    $value = $this->displayText($value);

                    return [
                        'id' => $value,
                        'text' => $labeler ? $labeler($value) : $value,
                    ];
                })
                ->filter(fn (array $option): bool => $option['id'] !== '')
                ->values()
                ->all(),
            'pagination' => ['more' => false],
        ];
    }

    private function displayText(mixed $value): string
    {
        return $this->sanitizer->displayText($value);
    }
}

<?php

namespace Modules\Auth\Services\Reports;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Auth\Models\AuthLog;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\Reports\ReportSanitizer;

class AuthLogReport
{
    public function __construct(
        private readonly DateFormatService $dates,
        private readonly ReportSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AuthLog>
     */
    public function query(array $filters = [], bool $listing = false): Builder
    {
        $query = AuthLog::query()
            ->leftJoin('users', 'users.id', '=', 'auth_logs.user_id')
            ->select($listing ? $this->listingColumns() : [
                'auth_logs.*',
                'users.name as user_name',
                'users.doc_num as user_doc_num',
            ]);

        return $this->applyFilters($query, $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AuthLog>
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
            'auth_logs.id',
            'auth_logs.public_id',
            'auth_logs.user_id',
            'auth_logs.login',
            'auth_logs.identifier',
            'auth_logs.email',
            'auth_logs.phone',
            'auth_logs.username',
            'auth_logs.event',
            'auth_logs.status',
            'auth_logs.remember_me',
            'auth_logs.ip_address',
            'auth_logs.client_ip',
            'auth_logs.user_agent',
            'auth_logs.browser_name',
            'auth_logs.browser_version',
            'auth_logs.os_name',
            'auth_logs.os_version',
            'auth_logs.device_type',
            'auth_logs.platform',
            'auth_logs.country',
            'auth_logs.region',
            'auth_logs.city',
            'auth_logs.latitude',
            'auth_logs.longitude',
            'auth_logs.location_accuracy',
            'auth_logs.geo_source',
            'auth_logs.guard',
            'auth_logs.failure_reason',
            'auth_logs.method',
            'auth_logs.url',
            'auth_logs.path',
            'auth_logs.context',
            'auth_logs.client_context',
            'auth_logs.location_context',
            'auth_logs.created_at',
            'users.name as user_name',
            'users.doc_num as user_doc_num',
        ];
    }

    /**
     * @param  Builder<AuthLog>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<AuthLog>
     */
    public function applyFilters(Builder $query, array $filters): Builder
    {
        $this->dateRange($query, $filters, 'auth_logs.created_at');

        foreach (['event', 'status'] as $field) {
            $value = $this->stringFilter($filters[$field] ?? null);

            if ($value !== null) {
                $query->where("auth_logs.{$field}", $value);
            }
        }

        $failureReason = $this->stringFilter($filters['failure_reason'] ?? null);

        if ($failureReason !== null) {
            $query->where('auth_logs.failure_reason', $failureReason);
        }

        $rememberMe = $this->stringFilter($filters['remember_me'] ?? null);

        if ($rememberMe !== null && in_array($rememberMe, ['0', '1'], true)) {
            $query->where('auth_logs.remember_me', $rememberMe === '1');
        }

        $ip = $this->stringFilter($filters['ip'] ?? null);

        if ($ip !== null) {
            $query->where(function (Builder $query) use ($ip): void {
                $query->where('auth_logs.ip_address', 'LIKE', "%{$ip}%")
                    ->orWhere('auth_logs.client_ip', 'LIKE', "%{$ip}%");
            });
        }

        $user = $this->stringFilter($filters['user'] ?? null);

        if ($user !== null) {
            $query->where(function (Builder $query) use ($user): void {
                $query->where('users.name', 'LIKE', "%{$user}%")
                    ->orWhere('users.doc_num', 'LIKE', "%{$user}%")
                    ->orWhere('auth_logs.login', 'LIKE', "%{$user}%")
                    ->orWhere('auth_logs.identifier', 'LIKE', "%{$user}%")
                    ->orWhere('auth_logs.email', 'LIKE', "%{$user}%")
                    ->orWhere('auth_logs.phone', 'LIKE', "%{$user}%")
                    ->orWhere('auth_logs.username', 'LIKE', "%{$user}%");
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
            'event',
            'status',
            'user',
            'ip',
            'failure_reason',
            'remember_me',
        ]);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            __('auth_logs.fields.date_time'),
            __('auth_logs.fields.user'),
            __('auth_logs.fields.activity'),
            __('auth_logs.fields.result'),
            __('auth_logs.fields.identifier'),
            __('auth_logs.fields.device'),
            __('auth_logs.fields.location'),
            __('auth_logs.fields.map_link'),
            __('auth_logs.fields.ip_address'),
            __('auth_logs.fields.summary'),
        ];
    }

    /**
     * @return list<string>
     */
    public function map(AuthLog $authLog): array
    {
        $row = $this->row($authLog);

        return [
            $row['date_time'],
            $row['user'],
            $row['activity'],
            $row['result'],
            $row['identifier'],
            $row['device'],
            $row['location'],
            $row['location_url'],
            $row['ip_address'],
            $row['summary'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function row(AuthLog $authLog): array
    {
        $location = $this->locationData($authLog);

        return [
            'date_time' => $this->date($authLog->created_at),
            'user' => $this->userLabel($authLog->user_name ?? null, $authLog->user_doc_num ?? null),
            'activity' => $this->eventLabel($authLog->event),
            'result' => $this->statusLabel($authLog->status),
            'result_class' => $this->statusClass($authLog->status),
            'identifier' => $this->identifierLabel($authLog),
            'branch_label' => $this->operatingContextLabel($authLog->branch_name, $authLog->branch_doc_num) ?: __('auth_logs.messages.not_selected'),
            'financial_period_label' => $this->operatingContextLabel($authLog->financial_period_name, $authLog->financial_period_doc_num) ?: __('auth_logs.messages.not_selected'),
            'device' => $this->deviceSummary($authLog),
            'location' => $location['label'],
            'location_url' => $location['url'],
            'location_map_label' => $location['map_label'],
            'location_accuracy_label' => $location['accuracy_label'],
            'location_source_label' => $location['source_label'],
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
            'coordinates' => $location['coordinates'],
            'ip_address' => $this->ipSummary($authLog),
            'summary' => $this->summary($authLog),
        ];
    }

    /**
     * @return array{summary: string, sections: list<array{title: string, collapsed?: bool, items: list<array{label: string, value: mixed, type?: string}>}>}
     */
    public function details(AuthLog $authLog): array
    {
        $row = $this->row($authLog);
        $technicalItems = $this->technicalContextItems($authLog);
        $ipAddress = $this->displayText($authLog->ip_address);
        $clientIp = $this->displayText($authLog->client_ip);
        $networkItems = [
            ['label' => __('auth_logs.fields.ip_address'), 'value' => $ipAddress],
            ['label' => __('auth_logs.fields.method'), 'value' => $this->displayText($authLog->method)],
            ['label' => __('auth_logs.fields.system_page'), 'value' => $this->pathLabel($authLog)],
            ['label' => __('auth_logs.fields.path'), 'value' => $this->displayText($authLog->path)],
            ['label' => __('auth_logs.fields.url'), 'value' => $this->displayText($authLog->url)],
        ];

        if ($clientIp !== '' && $clientIp !== $ipAddress) {
            array_splice($networkItems, 1, 0, [[
                'label' => __('auth_logs.fields.client_ip'),
                'value' => $clientIp,
            ]]);
        }

        return [
            'summary' => $row['summary'],
            'sections' => [
                [
                    'title' => __('auth_logs.fields.basic_info'),
                    'items' => [
                        ['label' => __('auth_logs.fields.date_time'), 'value' => $row['date_time']],
                        ['label' => __('auth_logs.fields.user'), 'value' => $row['user']],
                        ['label' => __('auth_logs.fields.identifier'), 'value' => $row['identifier']],
                        ['label' => __('auth_logs.fields.activity'), 'value' => $row['activity']],
                        ['label' => __('auth_logs.fields.result'), 'value' => $row['result']],
                        ['label' => __('auth_logs.fields.summary'), 'value' => $row['summary']],
                    ],
                ],
                [
                    'title' => __('auth_logs.fields.network_details'),
                    'items' => $networkItems,
                ],
                [
                    'title' => __('auth_logs.fields.operating_context'),
                    'items' => [
                        ['label' => __('auth_logs.fields.branch'), 'value' => $row['branch_label']],
                        ['label' => __('auth_logs.fields.financial_period'), 'value' => $row['financial_period_label']],
                    ],
                ],
                [
                    'title' => __('auth_logs.fields.device_details'),
                    'items' => [
                        ['label' => __('auth_logs.fields.device'), 'value' => $row['device']],
                        ['label' => __('auth_logs.fields.browser'), 'value' => $this->browserLabel($authLog)],
                        ['label' => __('auth_logs.fields.operating_system'), 'value' => $this->osLabel($authLog)],
                        ['label' => __('auth_logs.fields.platform'), 'value' => $this->displayText($authLog->platform)],
                        ['label' => __('auth_logs.fields.user_agent'), 'value' => $this->displayText($authLog->user_agent)],
                    ],
                ],
                [
                    'title' => __('auth_logs.fields.location_details'),
                    'items' => [
                        ['label' => __('auth_logs.fields.location'), 'value' => $row['location']],
                        ['label' => __('auth_logs.fields.coordinates'), 'value' => $row['coordinates']],
                        ['label' => __('auth_logs.fields.latitude'), 'value' => $row['latitude']],
                        ['label' => __('auth_logs.fields.longitude'), 'value' => $row['longitude']],
                        ['label' => __('auth_logs.fields.accuracy'), 'value' => $row['location_accuracy_label']],
                        ['label' => __('auth_logs.fields.location_source'), 'value' => $row['location_source_label']],
                        ['label' => __('auth_logs.fields.location_captured_at'), 'value' => $this->locationCapturedAt($authLog)],
                        [
                            'label' => __('auth_logs.fields.map_link'),
                            'value' => $row['location_url'] === '' ? '' : __('auth_logs.actions.view_on_map'),
                            'url' => $row['location_url'],
                            'type' => $row['location_url'] === '' ? 'text' : 'link',
                        ],
                    ],
                ],
                [
                    'title' => __('auth_logs.fields.security_context'),
                    'items' => [
                        ['label' => __('auth_logs.fields.failure_reason'), 'value' => $this->failureReasonLabel($authLog->failure_reason)],
                        ['label' => __('auth_logs.fields.remember_me'), 'value' => $this->rememberMeLabel($authLog)],
                        ['label' => __('auth_logs.fields.guard'), 'value' => $this->displayText($authLog->guard)],
                    ],
                ],
                [
                    'title' => __('auth_logs.fields.advanced_details'),
                    'collapsed' => true,
                    'items' => $technicalItems === []
                        ? [['label' => __('auth_logs.fields.advanced_details'), 'value' => __('auth_logs.messages.no_technical_details')]]
                        : $technicalItems,
                ],
            ],
        ];
    }

    public function location(AuthLog $authLog): string
    {
        return $this->locationData($authLog)['label'];
    }

    /**
     * @return array{label: string, url: string, map_label: string, accuracy_label: string, source_label: string, latitude: string, longitude: string, coordinates: string}
     */
    public function locationData(AuthLog $authLog): array
    {
        $locationParts = array_values(array_filter([
            $this->displayText($authLog->city),
            $this->displayText($authLog->region),
            $this->displayText($authLog->country),
        ], fn (string $value): bool => $value !== ''));
        $locationLabel = $locationParts === [] ? '' : implode(', ', $locationParts);
        $coordinates = $this->coordinatesLabel($authLog->latitude, $authLog->longitude);
        $mapUrl = $this->mapUrl($authLog->latitude, $authLog->longitude) ?? '';

        if ($mapUrl !== '') {
            $label = $locationLabel !== '' ? $locationLabel : $coordinates;

            return [
                'label' => $label,
                'url' => $mapUrl,
                'map_label' => __('auth_logs.messages.location_map_label', [
                    'location' => $label,
                    'action' => __('auth_logs.actions.view_on_map'),
                ]),
                'accuracy_label' => $this->accuracyLabel($authLog->location_accuracy),
                'source_label' => $this->locationSourceLabel($authLog->geo_source),
                'latitude' => $this->formatCoordinate($authLog->latitude),
                'longitude' => $this->formatCoordinate($authLog->longitude),
                'coordinates' => $coordinates,
            ];
        }

        if ($this->locationDenied($authLog)) {
            $label = __('auth_logs.messages.location_denied');

            return [
                'label' => $label,
                'url' => '',
                'map_label' => $label,
                'accuracy_label' => '',
                'source_label' => $this->locationSourceLabel($authLog->geo_source),
                'latitude' => '',
                'longitude' => '',
                'coordinates' => '',
            ];
        }

        if ($this->locationUnavailable($authLog)) {
            $label = __('auth_logs.messages.location_unavailable');

            return [
                'label' => $label,
                'url' => '',
                'map_label' => $label,
                'accuracy_label' => $this->accuracyLabel($authLog->location_accuracy),
                'source_label' => $this->locationSourceLabel($authLog->geo_source),
                'latitude' => '',
                'longitude' => '',
                'coordinates' => '',
            ];
        }

        $label = $locationLabel !== '' ? $locationLabel : '';

        return [
            'label' => $label,
            'url' => '',
            'map_label' => $label,
            'accuracy_label' => $this->accuracyLabel($authLog->location_accuracy),
            'source_label' => $this->locationSourceLabel($authLog->geo_source),
            'latitude' => '',
            'longitude' => '',
            'coordinates' => '',
        ];
    }

    public function isValidCoordinate(mixed $latitude, mixed $longitude): bool
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return false;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        return $latitude >= -90.0
            && $latitude <= 90.0
            && $longitude >= -180.0
            && $longitude <= 180.0;
    }

    public function mapUrl(mixed $latitude, mixed $longitude): ?string
    {
        if (! $this->isValidCoordinate($latitude, $longitude)) {
            return null;
        }

        return 'https://www.google.com/maps?q='.$this->formatCoordinate($latitude).','.$this->formatCoordinate($longitude);
    }

    public function userLabel(?string $name, ?string $docNum): string
    {
        $parts = array_values(array_filter([
            $this->displayText($name),
            $this->displayText($docNum),
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

    public function date(mixed $date): string
    {
        return $this->dates->formatDateTime($date, '');
    }

    public function identifierLabel(AuthLog $authLog): string
    {
        foreach ([$authLog->identifier, $authLog->login, $authLog->email, $authLog->phone, $authLog->username] as $value) {
            $display = $this->displayText($value);

            if ($display !== '') {
                return $display;
            }
        }

        return '';
    }

    public function deviceSummary(AuthLog $authLog): string
    {
        $browser = $this->browserLabel($authLog);
        $os = $this->osLabel($authLog);
        $device = $this->deviceTypeLabel($authLog->device_type);

        if ($browser === '' && $os === '' && $device === '') {
            return '';
        }

        if ($browser !== '' && $os !== '' && $device !== '') {
            return __('auth_logs.messages.device_summary', [
                'browser' => $browser,
                'os' => $os,
                'device' => $device,
            ]);
        }

        if ($browser !== '' && $os !== '') {
            return __('auth_logs.messages.browser_on_os', [
                'browser' => $browser,
                'os' => $os,
            ]);
        }

        return implode(' - ', array_filter([$browser, $os, $device]));
    }

    public function browserLabel(AuthLog $authLog): string
    {
        return implode(' ', array_values(array_filter([
            $this->displayText($authLog->browser_name),
            $this->displayText($authLog->browser_version),
        ], fn (string $value): bool => $value !== '')));
    }

    public function osLabel(AuthLog $authLog): string
    {
        return implode(' ', array_values(array_filter([
            $this->displayText($authLog->os_name),
            $this->displayText($authLog->os_version),
        ], fn (string $value): bool => $value !== '')));
    }

    public function deviceTypeLabel(mixed $deviceType): string
    {
        $deviceType = $this->displayText($deviceType);

        return $deviceType === '' ? '' : $this->translatedValue('auth_logs.device_types', $deviceType);
    }

    public function ipSummary(AuthLog $authLog): string
    {
        $ipAddress = $this->displayText($authLog->ip_address);
        $clientIp = $this->displayText($authLog->client_ip);

        return $ipAddress !== '' ? $ipAddress : $clientIp;
    }

    private function coordinatesLabel(mixed $latitude, mixed $longitude): string
    {
        if (! $this->isValidCoordinate($latitude, $longitude)) {
            return '';
        }

        return $this->formatCoordinate($latitude).', '.$this->formatCoordinate($longitude);
    }

    private function formatCoordinate(mixed $coordinate): string
    {
        return is_numeric($coordinate) ? number_format((float) $coordinate, 7, '.', '') : '';
    }

    private function accuracyLabel(mixed $accuracy): string
    {
        if (! is_numeric($accuracy)) {
            return '';
        }

        return __('auth_logs.messages.accuracy_meters', [
            'accuracy' => rtrim(rtrim(number_format((float) $accuracy, 2, '.', ''), '0'), '.'),
        ]);
    }

    private function locationSourceLabel(mixed $source): string
    {
        $source = $this->displayText($source);

        if ($source === '') {
            return '';
        }

        return $this->translatedValue('auth_logs.location_sources', $source);
    }

    private function locationCapturedAt(AuthLog $authLog): string
    {
        $capturedAt = data_get($authLog->location_context, 'captured_at')
            ?: data_get($authLog->client_context, 'location.captured_at')
            ?: data_get($authLog->context, 'location.captured_at');

        if (! is_string($capturedAt) || trim($capturedAt) === '') {
            return '';
        }

        return $this->date($capturedAt);
    }

    public function rememberMeLabel(AuthLog $authLog): string
    {
        return $authLog->remember_me === null
            ? ''
            : ($authLog->remember_me ? __('common.actions.yes') : __('common.actions.no'));
    }

    public function summary(AuthLog $authLog): string
    {
        $user = $this->userLabel($authLog->user_name ?? null, $authLog->user_doc_num ?? null);
        $identifier = $this->identifierLabel($authLog);
        $target = $user !== '' ? $user : $identifier;

        if ($target !== '') {
            return __('auth_logs.summaries.event_for_user', [
                'event' => $this->eventLabel($authLog->event),
                'user' => $target,
            ]);
        }

        return $this->eventLabel($authLog->event);
    }

    public function eventLabel(mixed $event): string
    {
        return $this->translatedValue('auth_logs.events', $event);
    }

    public function statusLabel(mixed $status): string
    {
        return $this->translatedValue('auth_logs.statuses', $status);
    }

    public function statusClass(mixed $status): string
    {
        return match (trim((string) $status)) {
            'success' => 'success',
            'failed', 'error' => 'danger',
            'blocked' => 'warning',
            'warning' => 'warning',
            'info' => 'info',
            default => 'secondary',
        };
    }

    public function failureReasonLabel(mixed $reason): string
    {
        $reason = $this->displayText($reason);

        return $reason === '' ? '' : $this->translatedValue('auth_logs.failure_reasons', $reason);
    }

    public function pathLabel(AuthLog $authLog): string
    {
        $path = trim((string) ($authLog->path ?? parse_url((string) $authLog->url, PHP_URL_PATH)));

        if ($path === '') {
            return __('auth_logs.pages.system_page');
        }

        if (str_starts_with($path, '/lang/') || str_starts_with($path, 'lang/')) {
            return __('auth_logs.pages.language_switch');
        }

        if (str_contains($path, 'login')) {
            return __('auth_logs.pages.login');
        }

        if (str_contains($path, 'lock-screen')) {
            return __('auth_logs.pages.lock_screen');
        }

        if (str_contains($path, 'password')) {
            return __('auth_logs.pages.password_reset');
        }

        if (str_contains($path, 'session/')) {
            return __('auth_logs.pages.session');
        }

        if (str_contains($path, 'admin/auth-logs')) {
            return __('auth_logs.pages.auth_logs');
        }

        if (str_contains($path, 'admin/auth-sessions')) {
            return __('auth_logs.pages.auth_sessions');
        }

        return __('auth_logs.pages.system_page');
    }

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function userOptions(Request $request): array
    {
        $term = $this->stringFilter($request->input('q')) ?? '';

        $users = User::query()
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('auth_logs')
                    ->whereColumn('auth_logs.user_id', 'users.id');
            })
            ->when($term !== '', function (Builder $query) use ($term): void {
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('users.doc_num', 'LIKE', "%{$term}%")
                        ->orWhere('users.name', 'LIKE', "%{$term}%")
                        ->orWhere('users.username', 'LIKE', "%{$term}%")
                        ->orWhere('users.email', 'LIKE', "%{$term}%");
                });
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['doc_num', 'name', 'username', 'email']);

        return [
            'results' => $users->map(fn (User $user): array => [
                'id' => (string) $user->doc_num,
                'text' => implode(' / ', array_filter([$user->name, $user->username, $user->email, $user->doc_num])),
            ])->all(),
            'pagination' => ['more' => false],
        ];
    }

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function eventOptions(Request $request): array
    {
        return $this->distinctOptions($request, 'event', fn (string $value): string => $this->eventLabel($value));
    }

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function statusOptions(Request $request): array
    {
        return $this->distinctOptions($request, 'status', fn (string $value): string => $this->statusLabel($value));
    }

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function failureReasonOptions(Request $request): array
    {
        return $this->distinctOptions($request, 'failure_reason', fn (string $value): string => $this->failureReasonLabel($value));
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
    public function countryOptions(Request $request): array
    {
        return $this->distinctOptions($request, 'country');
    }

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function cityOptions(Request $request): array
    {
        return $this->distinctOptions($request, 'city');
    }

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function guardOptions(Request $request): array
    {
        return $this->distinctOptions($request, 'guard');
    }

    /**
     * @param  Builder<AuthLog>  $query
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

    private function locationDenied(AuthLog $authLog): bool
    {
        foreach ([$authLog->location_context, $authLog->client_context, $authLog->context] as $context) {
            if (! is_array($context)) {
                continue;
            }

            $state = (string) (data_get($context, 'permission_state') ?: data_get($context, 'state') ?: data_get($context, 'status') ?: '');

            if (str_contains(mb_strtolower($state), 'denied')) {
                return true;
            }
        }

        return trim((string) $authLog->geo_source) === 'denied';
    }

    private function locationUnavailable(AuthLog $authLog): bool
    {
        foreach ([$authLog->location_context, $authLog->client_context, $authLog->context] as $context) {
            if (! is_array($context)) {
                continue;
            }

            if (data_get($context, 'unavailable') === true || data_get($context, 'timeout') === true) {
                return true;
            }

            $state = (string) (data_get($context, 'permission_state') ?: data_get($context, 'state') ?: data_get($context, 'status') ?: '');

            if (str_contains(mb_strtolower($state), 'unavailable') || str_contains(mb_strtolower($state), 'timeout')) {
                return true;
            }
        }

        return in_array(trim((string) $authLog->geo_source), ['unavailable', 'timeout'], true);
    }

    /**
     * @return list<array{label: string, value: mixed, type?: string}>
     */
    private function technicalContextItems(AuthLog $authLog): array
    {
        $items = [];

        foreach ([
            'payload_summary' => __('auth_logs.fields.payload_summary'),
            'context' => __('auth_logs.fields.context_details'),
            'client_context' => __('auth_logs.fields.client_context'),
            'device_context' => __('auth_logs.fields.device_context'),
            'location_context' => __('auth_logs.fields.location_context'),
            'network_context' => __('auth_logs.fields.network_context'),
        ] as $property => $label) {
            $value = $authLog->{$property};

            if (! is_array($value) || $value === []) {
                continue;
            }

            $json = $this->technicalJson($value);

            if ($json !== null) {
                $items[] = [
                    'label' => $label,
                    'value' => $json,
                    'type' => 'json',
                ];
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function technicalJson(array $data): ?string
    {
        $sanitized = $this->sanitizer->redactOriginalPropertiesForReport($data);

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

        $values = AuthLog::query()
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->when($term !== '', fn (Builder $query): Builder => $query->where($column, 'LIKE', "%{$term}%"))
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

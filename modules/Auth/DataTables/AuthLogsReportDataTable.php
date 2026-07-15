<?php

namespace Modules\Auth\DataTables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Auth\Models\AuthLog;
use Modules\Auth\Services\Reports\AuthLogReport;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\Reports\ReportSanitizer;
use Yajra\DataTables\Facades\DataTables;

class AuthLogsReportDataTable
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $rowCache = [];

    public function __construct(
        private readonly AuthLogReport $report,
        private readonly DataTableSearchService $searchService,
        private readonly ReportSanitizer $sanitizer,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $this->rowCache = [];
        $filters = $this->report->filtersFromRequest($request);
        $query = $this->report->listingQuery($filters);
        $canViewDetails = (bool) $request->user()?->can('auth.logs.details');
        $statusOrder = $this->statusRankOrderSql('auth_logs.status');

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->searchService->terms($request->input('search.value'));

                if ($terms === []) {
                    return;
                }

                $this->searchService->applyMultiTermSearch($query, $terms, [
                    'text' => [
                        'auth_logs.public_id',
                        'auth_logs.event',
                        'auth_logs.status',
                        'auth_logs.login',
                        'auth_logs.identifier',
                        'auth_logs.email',
                        'auth_logs.phone',
                        'auth_logs.username',
                        'auth_logs.ip_address',
                        'auth_logs.client_ip',
                        'auth_logs.browser_name',
                        'auth_logs.os_name',
                        'auth_logs.device_type',
                        'auth_logs.country',
                        'auth_logs.region',
                        'auth_logs.city',
                        'auth_logs.guard',
                        'auth_logs.failure_reason',
                        'auth_logs.path',
                        'users.name',
                        'users.doc_num',
                    ],
                    'dates' => ['auth_logs.created_at'],
                    'date_text' => ['auth_logs.created_at'],
                ]);
            })
            ->editColumn('created_at', fn (AuthLog $authLog): string => $this->text($this->row($authLog)['date_time']))
            ->addColumn('user_label', fn (AuthLog $authLog): string => $this->ellipsis($this->row($authLog)['user']))
            ->addColumn('event_label', fn (AuthLog $authLog): string => $this->ellipsis($this->row($authLog)['activity']))
            ->addColumn('status_label', fn (AuthLog $authLog): string => $this->statusBadge($this->row($authLog)))
            ->addColumn('ip_label', fn (AuthLog $authLog): string => $this->text($this->row($authLog)['ip_address']))
            ->addColumn('location_label', fn (AuthLog $authLog): string => $this->locationHtml($this->row($authLog)))
            ->addColumn('device_label', fn (AuthLog $authLog): string => $this->deviceHtml($authLog))
            ->addColumn('actions', fn (AuthLog $authLog): string => view('modules.auth.auth-logs.partials.actions', [
                'authLog' => $authLog,
                'canViewDetails' => $canViewDetails,
            ])->render())
            ->orderColumn('created_at', 'auth_logs.created_at $1')
            ->orderColumn('event_label', 'auth_logs.event $1')
            ->orderColumn('status_label', $statusOrder['sql'].' $1, auth_logs.created_at $1', $statusOrder['bindings'])
            ->orderColumn('user_label', 'users.name $1, users.doc_num $1')
            ->orderColumn('ip_label', "COALESCE(NULLIF(auth_logs.ip_address, ''), NULLIF(auth_logs.client_ip, '')) $1")
            ->orderColumn('location_label', 'auth_logs.country $1, auth_logs.region $1, auth_logs.city $1, auth_logs.latitude $1, auth_logs.longitude $1')
            ->orderColumn('device_label', 'auth_logs.browser_name $1, auth_logs.browser_version $1, auth_logs.os_name $1, auth_logs.os_version $1, auth_logs.device_type $1')
            ->orderColumn('actions', false)
            ->removeColumn('id')
            ->removeColumn('public_id')
            ->removeColumn('user_id')
            ->removeColumn('user_name')
            ->removeColumn('user_doc_num')
            ->removeColumn('event')
            ->removeColumn('status')
            ->removeColumn('remember_me')
            ->removeColumn('login')
            ->removeColumn('identifier')
            ->removeColumn('email')
            ->removeColumn('phone')
            ->removeColumn('username')
            ->removeColumn('user_agent')
            ->removeColumn('ip_address')
            ->removeColumn('client_ip')
            ->removeColumn('browser_name')
            ->removeColumn('browser_version')
            ->removeColumn('os_name')
            ->removeColumn('os_version')
            ->removeColumn('device_type')
            ->removeColumn('platform')
            ->removeColumn('country')
            ->removeColumn('region')
            ->removeColumn('city')
            ->removeColumn('latitude')
            ->removeColumn('longitude')
            ->removeColumn('location_accuracy')
            ->removeColumn('geo_source')
            ->removeColumn('guard')
            ->removeColumn('failure_reason')
            ->removeColumn('method')
            ->removeColumn('url')
            ->removeColumn('path')
            ->removeColumn('session_fingerprint')
            ->removeColumn('payload_summary')
            ->removeColumn('context')
            ->removeColumn('client_context')
            ->removeColumn('device_context')
            ->removeColumn('location_context')
            ->removeColumn('network_context')
            ->rawColumns(['user_label', 'event_label', 'status_label', 'device_label', 'location_label', 'actions'])
            ->toJson();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(AuthLog $authLog): array
    {
        $key = (string) ($authLog->public_id ?? $authLog->getKey() ?? spl_object_id($authLog));

        return $this->rowCache[$key] ??= $this->report->row($authLog);
    }

    private function badge(mixed $value, string $class): string
    {
        $value = $this->displayText($value);

        return $value === ''
            ? ''
            : '<span class="badge badge-subtle-'.$class.'">'.e($value).'</span>';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function statusBadge(array $row): string
    {
        $value = $this->displayText($row['result'] ?? '');
        $class = trim((string) ($row['result_class'] ?? 'secondary'));

        return $this->badge($value, $class);
    }

    private function ellipsis(mixed $value): string
    {
        $value = $this->displayText($value);

        if ($value === '') {
            return '';
        }

        return sprintf('<span class="dt-ellipsis-content" title="%s">%s</span>', e($value), e($value));
    }

    private function deviceHtml(AuthLog $authLog): string
    {
        $browser = $this->displayText($this->report->browserLabel($authLog));
        $os = $this->displayText($this->report->osLabel($authLog));
        $device = $this->displayText($this->report->deviceTypeLabel($authLog->device_type));
        $meta = array_values(array_filter([$os, $device], fn (string $value): bool => $value !== ''));

        if ($browser === '' && $meta === []) {
            return '';
        }

        $html = '<div class="auth-log-device">';

        if ($browser !== '') {
            $html .= sprintf(
                '<div class="fw-semibold text-900 auth-log-device-main">%s</div>',
                e($browser)
            );
        }

        if ($meta !== []) {
            $html .= sprintf(
                '<div class="small text-muted auth-log-device-meta">%s</div>',
                implode(' &middot; ', array_map(fn (string $value): string => e($value), $meta))
            );
        }

        return $html.'</div>';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function locationHtml(array $row): string
    {
        $label = $this->displayText($row['location'] ?? $row['location_map_label'] ?? '');
        $url = trim((string) ($row['location_url'] ?? ''));
        $coordinates = $this->displayText($row['coordinates'] ?? '');

        if ($label === '') {
            return '';
        }

        if ($url === '') {
            return $this->ellipsis($label);
        }

        $mapLabel = __('auth_logs.actions.view_on_map');
        $locationSubtext = $label !== $coordinates ? $label : '';

        return sprintf(
            '<span class="auth-log-location dt-ellipsis-content" title="%s"><a href="%s" target="_blank" rel="noopener noreferrer">%s</a>%s</span>',
            e($label),
            e($url),
            e($mapLabel),
            $locationSubtext === '' ? '' : '<small class="d-block text-500">'.e($locationSubtext).'</small>'
        );
    }

    private function text(mixed $value): string
    {
        $value = $this->displayText($value);

        return $value === '' ? '' : e($value);
    }

    private function nullableText(mixed $value): ?string
    {
        $value = $this->displayText($value);

        return $value === '' ? null : e($value);
    }

    private function displayText(mixed $value): string
    {
        return $this->sanitizer->displayText($value);
    }

    /**
     * @return array{sql: string, bindings: list<string>}
     */
    private function statusRankOrderSql(string $column): array
    {
        return [
            'sql' => "CASE {$column}
                WHEN ? THEN 1
                WHEN ? THEN 2
                WHEN ? THEN 3
                WHEN ? THEN 4
                WHEN ? THEN 5
                WHEN ? THEN 5
                ELSE 99
            END",
            'bindings' => ['success', 'info', 'warning', 'blocked', 'failed', 'error'],
        ];
    }
}

<?php

namespace Modules\Auth\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Auth\Models\UserPresenceSession;
use Modules\Auth\Services\Reports\AuthSessionReport;
use Modules\Auth\Services\UserPresenceService;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\Reports\ReportSanitizer;
use Yajra\DataTables\Facades\DataTables;

class AuthSessionsReportDataTable
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $rowCache = [];

    public function __construct(
        private readonly AuthSessionReport $report,
        private readonly UserPresenceService $presence,
        private readonly DataTableSearchService $searchService,
        private readonly ReportSanitizer $sanitizer,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $this->rowCache = [];
        $filters = $this->report->filtersFromRequest($request);
        $query = $this->report->listingQuery($filters);
        $canViewDetails = (bool) $request->user()?->can('auth.sessions.details');
        $canForceLogout = (bool) $request->user()?->can('auth.sessions.force_logout');
        $currentSessionPublicId = $this->currentSessionPublicId($request);
        $presenceRankOrder = $this->presence->effectivePresenceStatusRankSql();
        $accountStatusOrder = $this->accountStatusRankOrderSql();
        $durationOrder = $this->durationSecondsOrderSql($query);

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->searchService->terms($request->input('search.value'));

                if ($terms === []) {
                    return;
                }

                $this->searchService->applyMultiTermSearch($query, $terms, [
                    'text' => [
                        'user_presence_sessions.public_id',
                        'user_presence_sessions.status',
                        'user_presence_sessions.ip_address',
                        'user_presence_sessions.browser_name',
                        'user_presence_sessions.os_name',
                        'user_presence_sessions.device_type',
                        'user_presence_sessions.offline_reason',
                        'user_presence_sessions.branch_doc_num',
                        'user_presence_sessions.branch_name',
                        'user_presence_sessions.financial_period_doc_num',
                        'user_presence_sessions.financial_period_name',
                        'users.name',
                        'users.doc_num',
                        'users.username',
                        'users.email',
                        'users.phone',
                        'users.status',
                    ],
                    'dates' => [
                        'user_presence_sessions.login_at',
                        'user_presence_sessions.last_seen_at',
                        'user_presence_sessions.last_activity_at',
                        'user_presence_sessions.logout_at',
                    ],
                    'date_text' => [
                        'user_presence_sessions.login_at',
                        'user_presence_sessions.last_seen_at',
                        'user_presence_sessions.last_activity_at',
                        'user_presence_sessions.logout_at',
                    ],
                ]);
            })
            ->addColumn('user_label', fn (UserPresenceSession $session): string => $this->ellipsis($this->row($session)['user']))
            ->addColumn('branch_label', fn (UserPresenceSession $session): ?string => $this->nullableText($this->row($session)['branch_label']))
            ->addColumn('financial_period_label', fn (UserPresenceSession $session): ?string => $this->nullableText($this->row($session)['financial_period_label']))
            ->addColumn('account_status_label', fn (UserPresenceSession $session): string => $this->badge($this->row($session)['account_status'], $this->row($session)['account_status_class']))
            ->addColumn('presence_status_label', fn (UserPresenceSession $session): string => $this->badge($this->row($session)['presence'], $this->row($session)['presence_class']))
            ->addColumn('device_label', fn (UserPresenceSession $session): string => $this->ellipsis($this->row($session)['device']))
            ->editColumn('login_at', fn (UserPresenceSession $session): string => $this->text($this->row($session)['login_at']))
            ->editColumn('last_seen_at', fn (UserPresenceSession $session): string => $this->text($this->row($session)['last_seen_at']))
            ->addColumn('duration_label', fn (UserPresenceSession $session): string => $this->text($this->row($session)['duration']))
            ->addColumn('actions', fn (UserPresenceSession $session): string => view('modules.auth.auth-sessions.partials.actions', [
                'session' => $session,
                'canViewDetails' => $canViewDetails,
                'canForceLogout' => $canForceLogout,
                'isCurrentSession' => $this->isCurrentSession($session, $currentSessionPublicId),
                'isForceLogoutEligible' => $this->presence->isFreshActiveSession($session),
            ])->render())
            ->orderColumn('user_label', 'users.name $1, users.doc_num $1')
            ->orderColumn('branch_label', 'user_presence_sessions.branch_name $1, user_presence_sessions.branch_doc_num $1')
            ->orderColumn('financial_period_label', 'user_presence_sessions.financial_period_name $1, user_presence_sessions.financial_period_doc_num $1')
            ->orderColumn('account_status_label', $accountStatusOrder['sql'].' $1, users.name $1', $accountStatusOrder['bindings'])
            ->orderColumn('presence_status_label', $presenceRankOrder['sql'].' $1, user_presence_sessions.last_seen_at $1, user_presence_sessions.login_at $1', $presenceRankOrder['bindings'])
            ->orderColumn('device_label', 'user_presence_sessions.browser_name $1, user_presence_sessions.os_name $1, user_presence_sessions.device_type $1')
            ->orderColumn('ip_address', 'user_presence_sessions.ip_address $1')
            ->orderColumn('login_at', 'user_presence_sessions.login_at $1')
            ->orderColumn('last_seen_at', 'user_presence_sessions.last_seen_at $1')
            ->orderColumn('duration_label', $durationOrder['sql'].' $1', $durationOrder['bindings'])
            ->orderColumn('actions', false)
            ->removeColumn('id')
            ->removeColumn('public_id')
            ->removeColumn('user_id')
            ->removeColumn('user_name')
            ->removeColumn('user_doc_num')
            ->removeColumn('username')
            ->removeColumn('email')
            ->removeColumn('phone')
            ->removeColumn('account_status')
            ->removeColumn('status')
            ->removeColumn('browser_name')
            ->removeColumn('os_name')
            ->removeColumn('device_type')
            ->removeColumn('offline_reason')
            ->removeColumn('branch_id')
            ->removeColumn('branch_doc_num')
            ->removeColumn('branch_name')
            ->removeColumn('financial_period_id')
            ->removeColumn('financial_period_doc_num')
            ->removeColumn('financial_period_name')
            ->removeColumn('last_activity_at')
            ->removeColumn('locked_at')
            ->removeColumn('logout_at')
            ->removeColumn('expires_at')
            ->removeColumn('created_at')
            ->removeColumn('session_fingerprint')
            ->removeColumn('context')
            ->removeColumn('user_agent')
            ->rawColumns(['user_label', 'account_status_label', 'presence_status_label', 'device_label', 'actions'])
            ->toJson();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(UserPresenceSession $session): array
    {
        $key = (string) ($session->public_id ?? $session->getKey() ?? spl_object_id($session));

        return $this->rowCache[$key] ??= $this->report->row($session);
    }

    private function badge(mixed $value, string $class): string
    {
        $value = $this->displayText($value);

        return $value === ''
            ? ''
            : '<span class="badge badge-subtle-'.$class.'">'.e($value).'</span>';
    }

    private function text(mixed $value): string
    {
        $value = $this->displayText($value);

        return $value === '' ? '' : e($value);
    }

    private function ellipsis(mixed $value): string
    {
        $value = $this->displayText($value);

        if ($value === '') {
            return '';
        }

        return sprintf('<span class="dt-ellipsis-content" title="%s">%s</span>', e($value), e($value));
    }

    private function nullableText(mixed $value): ?string
    {
        $value = $this->displayText($value);

        return $value === '' ? null : e($value);
    }

    private function currentSessionPublicId(Request $request): ?string
    {
        $currentFingerprint = $this->presence->sessionFingerprint($request);

        if ($currentFingerprint === null) {
            return null;
        }

        $publicId = UserPresenceSession::query()
            ->where('session_fingerprint', $currentFingerprint)
            ->value('public_id');

        return is_string($publicId) && $publicId !== '' ? $publicId : null;
    }

    private function isCurrentSession(UserPresenceSession $session, ?string $currentSessionPublicId): bool
    {
        $sessionPublicId = $this->displayText($session->public_id);

        return $currentSessionPublicId !== null
            && $sessionPublicId !== ''
            && hash_equals($currentSessionPublicId, $sessionPublicId);
    }

    /**
     * @return array{sql: string, bindings: list<string>}
     */
    private function accountStatusRankOrderSql(): array
    {
        return [
            'sql' => 'CASE users.status
                WHEN ? THEN 1
                WHEN ? THEN 2
                WHEN ? THEN 3
                ELSE 99
            END',
            'bindings' => ['active', 'inactive', 'blocked'],
        ];
    }

    /**
     * @return array{sql: string, bindings: list<mixed>}
     */
    private function durationSecondsOrderSql(Builder $query): array
    {
        $now = Carbon::now();

        return match ($query->getQuery()->getConnection()->getDriverName()) {
            'pgsql' => [
                'sql' => 'GREATEST(0, EXTRACT(EPOCH FROM (COALESCE(user_presence_sessions.logout_at, CAST(? AS timestamp)) - COALESCE(user_presence_sessions.login_at, user_presence_sessions.created_at, CAST(? AS timestamp)))))',
                'bindings' => [$now, $now],
            ],
            'mysql', 'mariadb' => [
                'sql' => 'GREATEST(0, TIMESTAMPDIFF(SECOND, COALESCE(user_presence_sessions.login_at, user_presence_sessions.created_at, ?), COALESCE(user_presence_sessions.logout_at, ?)))',
                'bindings' => [$now, $now],
            ],
            'sqlsrv' => [
                'sql' => 'CASE
                    WHEN DATEDIFF(second, COALESCE(user_presence_sessions.login_at, user_presence_sessions.created_at, ?), COALESCE(user_presence_sessions.logout_at, ?)) < 0 THEN 0
                    ELSE DATEDIFF(second, COALESCE(user_presence_sessions.login_at, user_presence_sessions.created_at, ?), COALESCE(user_presence_sessions.logout_at, ?))
                END',
                'bindings' => [$now, $now, $now, $now],
            ],
            default => [
                'sql' => "MAX(0, strftime('%s', COALESCE(user_presence_sessions.logout_at, ?)) - strftime('%s', COALESCE(user_presence_sessions.login_at, user_presence_sessions.created_at, ?)))",
                'bindings' => [$now, $now],
            ],
        };
    }

    private function displayText(mixed $value): string
    {
        return $this->sanitizer->displayText($value);
    }
}

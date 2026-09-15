<?php

namespace Modules\Core\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\UserNotification;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NotificationAccessService;
use Modules\Core\Services\NotificationService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\SafeRedirectUrlService;
use Modules\Core\Services\SessionIdentityService;
use stdClass;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly DateFormatService $dates,
        private readonly SessionIdentityService $sessionIdentity,
        private readonly NotificationAccessService $access,
        private readonly SafeRedirectUrlService $safeRedirects,
        private readonly OperatingContextService $operatingContext,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'module' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'in:all,read,unread'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $query = $this->access->queryFor($user)->delivered();
        $search = trim((string) ($filters['search'] ?? ''));

        $query
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('body', 'like', "%{$search}%");
                });
            })
            ->when(filled($filters['module'] ?? null), fn ($query) => $query->where('module', $filters['module']))
            ->when(filled($filters['type'] ?? null), fn ($query) => $query->where('type', $filters['type']))
            ->when(($filters['state'] ?? 'all') === 'read', fn ($query) => $query->whereNotNull('read_at'))
            ->when(($filters['state'] ?? 'all') === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->when(filled($filters['from'] ?? null), fn ($query) => $query->whereDate('delivered_at', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($query) => $query->whereDate('delivered_at', '<=', $filters['to']));

        $notifications = $query
            ->with('branch:id,name')
            ->latest('delivered_at')
            ->latest('id')
            ->cursorPaginate(30)
            ->withQueryString();

        $modules = $this->access->queryFor($user)
            ->delivered()
            ->whereNotNull('module')
            ->distinct()
            ->orderBy('module')
            ->pluck('module');
        $types = $this->access->queryFor($user)
            ->delivered()
            ->distinct()
            ->orderBy('type')
            ->pluck('type');

        return view('modules.Core.notifications.index', compact('notifications', 'modules', 'types', 'filters'));
    }

    public function poll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $poll = $this->notifications->pollData($user);
        $poll['session_identity'] = $this->sessionIdentity->for($request);
        $poll['notifications'] = $poll['notifications']
            ->map(fn (stdClass $notification): array => $this->payload($notification))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $poll,
        ]);
    }

    public function diagnostics(): View
    {
        $events = UserNotification::query()
            ->whereNotNull('event_uuid')
            ->select(['event_uuid', 'module', 'type'])
            ->selectRaw('COUNT(*) as recipient_count')
            ->selectRaw('MAX(created_at) as occurred_at')
            ->selectRaw("SUM(CASE WHEN push_status = 'accepted' THEN 1 ELSE 0 END) as accepted_count")
            ->selectRaw("SUM(CASE WHEN push_status = 'failed' THEN 1 ELSE 0 END) as failed_count")
            ->selectRaw("SUM(CASE WHEN push_status IN ('queued', 'sending') THEN 1 ELSE 0 END) as pending_count")
            ->groupBy(['event_uuid', 'module', 'type'])
            ->latest('occurred_at')
            ->limit(30)
            ->get();
        $pushStatuses = UserNotification::query()
            ->whereNotNull('push_status')
            ->select('push_status')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('push_status')
            ->orderBy('push_status')
            ->pluck('aggregate', 'push_status');
        $queue = [
            'pending' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : null,
            'failed' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null,
        ];
        $configuration = [
            'vapid_subject' => filled(config('webpush.vapid.subject')),
            'vapid_public_key' => filled(config('webpush.vapid.public_key')),
            'vapid_private_key' => filled(config('webpush.vapid.private_key')),
            'queue_connection' => (string) config('queue.default'),
        ];
        $runtime = Cache::get('notifications.runtime.last_dispatch');

        return view('modules.Core.notifications.diagnostics', compact(
            'events',
            'pushStatuses',
            'queue',
            'configuration',
            'runtime',
        ));
    }

    public function read(Request $request, UserNotification $notification): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->access->allows($user, $notification), 404);

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return response()->json([
            'success' => true,
            'message' => __('notifications.messages.marked_read'),
            'data' => [
                'notification' => $this->payload($notification->refresh()),
                'unread_count' => $this->notifications->unreadCount($user),
            ],
        ]);
    }

    public function readAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $throughId = $this->access->queryFor($user)
            ->delivered()
            ->max('id');

        if ($throughId !== null) {
            $this->access->queryFor($user)
                ->delivered()
                ->where('id', '<=', $throughId)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

        return response()->json([
            'success' => true,
            'message' => __('notifications.messages.all_marked_read'),
            'data' => [
                'unread_count' => $this->notifications->unreadCount($user),
            ],
        ]);
    }

    public function open(Request $request, UserNotification $notification): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->access->allows($user, $notification), 404);

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        if (! $this->selectNotificationContext($request, $notification)) {
            return redirect($this->safeRedirects->fallback())
                ->with('warning', __('notifications.messages.context_unavailable'));
        }

        $target = $this->safeRedirects->sanitizeIntended($notification->url)
            ?? $this->safeRedirects->fallback();

        return redirect($target);
    }

    private function selectNotificationContext(Request $request, UserNotification $notification): bool
    {
        $metadata = is_array($notification->metadata) ? $notification->metadata : [];
        $financialPeriodId = $metadata['financial_period_id'] ?? null;

        if (! is_numeric($notification->company_id) || ! is_numeric($notification->branch_id) || ! is_numeric($financialPeriodId)) {
            return true;
        }

        $company = Company::query()->find($notification->company_id);
        $branch = Branch::query()->find($notification->branch_id);
        $financialPeriod = FinancialPeriod::query()->find((int) $financialPeriodId);

        if (! $company || ! $branch || ! $financialPeriod) {
            return false;
        }

        try {
            $this->operatingContext->select($request, $company->doc_num, $branch->doc_num, $financialPeriod->doc_num);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(UserNotification|stdClass $notification): array
    {
        return [
            'id' => $notification->public_uuid,
            'sequence' => (int) ($notification->id ?? 0),
            'event_id' => $notification->event_uuid,
            'type' => $notification->type,
            'category' => $notification->category,
            'module' => $notification->module,
            'severity' => $notification->severity,
            'requires_action' => (bool) $notification->requires_action,
            'sound_key' => $notification->sound_key,
            'suppress_in_app_alert' => (bool) $notification->suppress_in_app_alert,
            'conversation_uuid' => $notification->conversation_uuid ?? null,
            'title' => $notification->title,
            'body' => $notification->body,
            'url' => route('admin.notifications.open', ['notification' => $notification->public_uuid], false),
            'is_read' => $notification->read_at !== null,
            'time' => $this->dates->formatDateTime($notification->delivered_at ?: $notification->created_at, ''),
        ];
    }
}

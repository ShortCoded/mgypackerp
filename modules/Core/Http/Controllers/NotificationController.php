<?php

namespace Modules\Core\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Models\UserNotification;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NotificationService;
use Modules\Core\Services\SessionIdentityService;
use stdClass;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly DateFormatService $dates,
        private readonly SessionIdentityService $sessionIdentity,
    ) {}

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

    public function read(Request $request, UserNotification $notification): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless((int) $notification->user_id === (int) $user->getKey(), 404);

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

        UserNotification::query()
            ->forUser($user)
            ->delivered()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => __('notifications.messages.all_marked_read'),
            'data' => [
                'unread_count' => 0,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(UserNotification|stdClass $notification): array
    {
        return [
            'id' => $notification->public_uuid,
            'type' => $notification->type,
            'category' => $notification->category,
            'title' => $notification->title,
            'body' => $notification->body,
            'url' => $notification->url,
            'is_read' => $notification->read_at !== null,
            'time' => $this->dates->formatDateTime($notification->delivered_at ?: $notification->created_at, ''),
        ];
    }
}

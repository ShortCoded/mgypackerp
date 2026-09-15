<?php

namespace App\Http\Controllers;

use App\Http\Requests\DestroyPushSubscriptionRequest;
use App\Http\Requests\StorePushSubscriptionRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Modules\Core\Services\SessionIdentityService;

class PushSubscriptionController extends Controller
{
    public const SessionEndpointKey = 'push_subscription_endpoint';

    public function store(StorePushSubscriptionRequest $request, SessionIdentityService $sessions): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        $subscription = $user->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
            $validated['content_encoding'] ?? 'aes128gcm',
        );
        $subscription->forceFill(['session_identity' => $sessions->for($request)])->save();
        $request->session()->put(self::SessionEndpointKey, $validated['endpoint']);

        return response()->json([
            'success' => true,
            'message' => __('notifications.push.enabled'),
            'data' => [
                'subscription_id' => $subscription->getKey(),
            ],
        ]);
    }

    public function destroy(DestroyPushSubscriptionRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->deletePushSubscription($request->validated('endpoint'));
        $request->session()->forget(self::SessionEndpointKey);

        return response()->json([
            'success' => true,
            'message' => __('notifications.push.disabled'),
        ]);
    }
}

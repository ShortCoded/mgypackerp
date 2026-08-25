<?php

namespace App\Http\Controllers;

use App\Http\Requests\DestroyPushSubscriptionRequest;
use App\Http\Requests\StorePushSubscriptionRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class PushSubscriptionController extends Controller
{
    public function store(StorePushSubscriptionRequest $request): JsonResponse
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

        return response()->json([
            'success' => true,
            'message' => __('notifications.push.disabled'),
        ]);
    }
}

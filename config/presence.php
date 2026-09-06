<?php

return [
    'online_threshold_seconds' => env('PRESENCE_ONLINE_THRESHOLD_SECONDS', 90),
    'duplicate_login_active_threshold_seconds' => env('PRESENCE_DUPLICATE_LOGIN_ACTIVE_THRESHOLD_SECONDS', 120),
    'idle_threshold_seconds' => env('PRESENCE_IDLE_THRESHOLD_SECONDS', 300),
    'offline_threshold_seconds' => env('PRESENCE_OFFLINE_THRESHOLD_SECONDS', 180),
    'activity_touch_throttle_seconds' => env('PRESENCE_ACTIVITY_TOUCH_THROTTLE_SECONDS', 30),
];

<?php

return [
    'title' => 'Notifications',
    'empty' => 'No notifications yet.',
    'actions' => [
        'mark_all_read' => 'Mark all as read',
        'view_all' => 'View all',
    ],
    'sound' => [
        'title' => 'Notification sound',
        'on' => 'Sound on',
        'off' => 'Sound off',
    ],
    'push' => [
        'title' => 'Push notifications',
        'enable' => 'Enable push',
        'disable' => 'Disable push',
        'enabled' => 'Push notifications are enabled on this device.',
        'disabled' => 'Push notifications are disabled on this device.',
        'denied' => 'Notifications are blocked in this browser. Allow them in the site settings to continue.',
        'unavailable' => 'Push notifications are unavailable until PWA and VAPID settings are configured.',
        'failed' => 'Push notification settings could not be saved. Please try again.',
    ],
    'types' => [
        'task_assigned' => 'Task assigned',
        'task_updated' => 'Task updated',
        'task_due_soon' => 'Task due soon',
        'calendar_reminder' => 'Calendar reminder',
        'chat_message' => 'New message',
    ],
    'messages' => [
        'task_assigned' => 'You were assigned to ":title".',
        'task_updated' => 'Task ":title" was updated.',
        'task_due_soon' => 'Task ":title" is due soon at :time.',
        'calendar_reminder' => 'Event ":title" starts at :time.',
        'chat_message' => 'New message from :name',
        'marked_read' => 'Notification marked as read.',
        'all_marked_read' => 'All notifications marked as read.',
    ],
];

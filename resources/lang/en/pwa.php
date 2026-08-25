<?php

return [
    'title' => 'Web App Settings',
    'subtitle' => 'Manage how the ERP appears when installed on phones, tablets, and desktops.',
    'defaults' => [
        'description' => 'Short Coded ERP application.',
    ],
    'sections' => [
        'availability' => 'Web app activation',
        'identity' => 'App identity',
        'behavior' => 'App behavior',
        'manifest' => 'Technical manifest',
        'icons' => 'App icons',
        'offline' => 'Offline fallback',
    ],
    'fields' => [
        'enabled' => 'Enable Web App',
        'app_name' => 'App name',
        'short_name' => 'Short name',
        'description' => 'Description',
        'start_url' => 'Start URL',
        'scope' => 'Scope',
        'display' => 'Display mode',
        'orientation' => 'Orientation',
        'theme_color' => 'Theme color',
        'background_color' => 'Background color',
        'locale' => 'Language / locale',
        'direction' => 'Text direction',
        'service_worker_enabled' => 'Enable service worker',
        'offline_enabled' => 'Enable offline fallback',
        'offline_title' => 'Offline page title',
        'offline_message' => 'Offline page message',
        'cache_name' => 'Cache name',
        'icon_192' => 'PWA icon 192x192',
        'icon_512' => 'PWA icon 512x512',
        'icon_maskable' => 'Maskable icon',
        'apple_touch_icon' => 'Apple touch icon',
    ],
    'icons' => [
        'current_icon' => 'Current icon',
        'default_icon' => 'Default icon',
        'selected_icon' => 'Selected icon',
    ],
    'display_modes' => [
        'standalone' => 'Standalone',
        'fullscreen' => 'Fullscreen',
        'minimal-ui' => 'Minimal UI',
        'browser' => 'Browser',
    ],
    'orientations' => [
        'any' => 'Automatic',
        'portrait' => 'Portrait',
        'landscape' => 'Landscape',
    ],
    'directions' => [
        'auto' => 'Automatic',
        'rtl' => 'RTL',
        'ltr' => 'LTR',
    ],
    'actions' => [
        'save' => 'Save Web App Settings',
        'choose_icon' => 'Choose icon',
        'replace_icon' => 'Replace icon',
        'remove_icon' => 'Remove current icon',
    ],
    'help' => [
        'availability' => 'When enabled, users can install the ERP from supported browsers.',
        'icon_192' => 'Used by browsers for smaller installed app icons.',
        'icon_512' => 'Used by browsers for large installed app icons.',
        'icon_maskable' => 'Used by supported launchers that crop icons into device-specific shapes.',
        'apple_touch_icon' => 'Used for iOS home screen icons.',
    ],
    'offline' => [
        'default_title' => 'You are offline',
        'default_message' => 'The ERP could not reach the server. Check your connection and try again.',
    ],
    'connectivity' => [
        'offline' => 'You are offline. Changes will not be submitted until the connection returns.',
        'online' => 'Connection restored.',
    ],
    'update' => [
        'available' => 'A new version is available.',
        'reload' => 'Reload',
    ],
    'messages' => [
        'updated' => 'Web app settings updated successfully.',
    ],
    'validation' => [
        'selected_file_not_image' => 'The selected file is not an image.',
        'selected_file_unavailable' => 'The selected file is not available in the current company.',
        'selected_file_hidden_from_picker' => 'This file cannot be selected because it is hidden from the picker.',
    ],
];

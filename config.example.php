<?php
return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'user' => 'your_database_user',
        'password' => 'your_database_password',
        'database' => 'your_database_name',
    ],
    // Keep this empty for same-domain hosting. Add exact origins only when
    // the page and PHP APIs are on different domains.
    'allowed_origins' => [
        // 'https://example.com',
        // 'https://localhost',      // Capacitor Android
        // 'capacitor://localhost',  // Capacitor iOS
    ],
    'room' => [
        'ttl_hours' => 2,
        'max_payload_bytes' => 512 * 1024,
        'max_room_messages' => 240,
        'poll_limit' => 80,
    ],
    'library' => [
        'max_content_bytes' => 4 * 1024 * 1024,
        'list_limit' => 60,
    ],
    'cloud' => [
        'max_sync_bytes' => 16 * 1024 * 1024,
    ],
    'auth' => [
        // Bearer sessions are rotated at login and expire automatically.
        'session_ttl_days' => 30,
        'max_sessions_per_user' => 8,
    ],
];

<?php

return [
    'encryption_key' => env('ENCRYPTION_KEY'),
    'version' => env('SAFECHAT_VERSION', '0.1.0'),
    'msg_limit' => (int) env('MSG_LIMIT', 50),
    'max_msg_length' => (int) env('MAX_MSG_LENGTH', 2000),
    'rate_limit_window' => (int) env('RATE_LIMIT_WINDOW', 60),
    'rate_limit_send' => (int) env('RATE_LIMIT_SEND', 30),
    'rate_limit_unlock' => (int) env('RATE_LIMIT_UNLOCK', 10),
    'rate_limit_init' => (int) env('RATE_LIMIT_INIT', 20),
    'rate_limit_mutate' => (int) env('RATE_LIMIT_MUTATE', 60),
    'rate_limit_export' => (int) env('RATE_LIMIT_EXPORT', 6),
    'rate_limit_admin' => (int) env('RATE_LIMIT_ADMIN', 2),
    'admin_device_ids' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ADMIN_DEVICE_IDS', ''))
    ))),
    'session_lifetime' => (int) env('SAFECHAT_SESSION_LIFETIME', 86400),
    'max_upload_size' => (int) env('MAX_UPLOAD_SIZE', 5242880),
    'log_level' => env('SAFECHAT_LOG_LEVEL', 'warning'),
    'csrf_token_name' => '_csrf',
    'install' => [
        'enabled' => (bool) env('SAFECHAT_INSTALL_ENABLED', false),
        'token' => env('SAFECHAT_INSTALL_TOKEN'),
        'marker' => env('SAFECHAT_INSTALL_MARKER', storage_path('app/safechat.installed')),
    ],
    'allow_db_reset' => (bool) env('SAFECHAT_ALLOW_DB_RESET', false),
];

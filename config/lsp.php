<?php

declare(strict_types=1);

return [
    'two_factor' => [
        'reauth_ttl_minutes' => env('LSP_2FA_REAUTH_TTL_MINUTES', 15),
    ],

    'recovery' => [
        'key_bytes' => env('LSP_RECOVERY_KEY_LENGTH', 32),
    ],

    'test_defaults' => [
        'time_limit_seconds' => env('LSP_DEFAULT_TIME_LIMIT_SECONDS', 180),
        'practice_seconds' => env('LSP_DEFAULT_PRACTICE_SECONDS', 30),
    ],

    'support_thresholds' => [
        // LQ-Skala: MW=100, SD=15
        'auffaellig_lq_below' => 85,
        'foerderbedarf_lq_below' => 70,
    ],

    'pdf' => [
        'gotenberg_url' => env('GOTENBERG_URL', 'http://pdf:3000'),
        'document_retention_days' => 30,
    ],

    'backup' => [
        'retention' => [
            'daily' => 7,
            'weekly' => 4,
            'monthly' => 12,
        ],
    ],
];

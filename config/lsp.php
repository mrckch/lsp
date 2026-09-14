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

    // Schüler einer Schule teilen sich meist eine NAT-IP. Gedrosselt wird daher
    // primär pro Login-Code bzw. pro laufendem Versuch; das IP-Limit ist nur ein
    // grober Deckel gegen massenhaftes Code-Raten.
    'rate_limits' => [
        'student_login_per_ip' => (int) env('LSP_STUDENT_LOGIN_PER_IP', 300),
        'student_login_per_code' => (int) env('LSP_STUDENT_LOGIN_PER_CODE', 10),
        'student_answers_per_attempt' => (int) env('LSP_STUDENT_ANSWERS_PER_ATTEMPT', 120),
        'student_answers_per_ip' => (int) env('LSP_STUDENT_ANSWERS_PER_IP', 60),
    ],

    // Kommagetrennte IPs/CIDRs des Reverse-Proxys, deren X-Forwarded-*-Header gelten.
    // '*' = allen vertrauen (nur, wenn der App-Port ausschließlich vom Proxy erreichbar ist).
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    'backup' => [
        // Uhrzeit des täglichen Scheduler-Backups (backup:run)
        'schedule_time' => env('LSP_BACKUP_TIME', '02:30'),
        'retention' => [
            'daily' => 7,
            'weekly' => 4,
            'monthly' => 12,
        ],
        // Storage-Verzeichnisse (relativ zur 'local'-Disk), die mit ins Backup gehen.
        // 'lsp/backups' ist bewusst NICHT enthalten (Backup würde sich selbst einschließen).
        'include_paths' => [
            'lsp/imports',
            'lsp/print-jobs',
            'lsp/exports',
        ],
        // Größenlimit pro einzelner Datei in Bytes; größere Dateien werden mit Hinweis übersprungen.
        // Schützt vor Riesen-Backups (z. B. 200MB-PDFs).
        'max_file_size_bytes' => 50 * 1024 * 1024,
    ],

    'audit' => [
        // Audit-Einträge älter als X Tage werden vom Cron 'audit:archive' soft-archiviert
        // (archived_at gesetzt, kein Hard-Delete). DSGVO-Lifecycle: Daten bleiben erhalten,
        // tauchen aber per Default nicht mehr in der Filament-Liste auf.
        'archive_after_days' => env('LSP_AUDIT_ARCHIVE_AFTER_DAYS', 90),
        // Hard-Delete-Phase: archivierte Einträge älter als Y Tage werden vom Cron
        // 'audit:purge' endgültig gelöscht. Default 730 Tage (2 Jahre nach Archivierung).
        // Auf 0 setzen, um Hard-Delete zu deaktivieren.
        'purge_after_days' => env('LSP_AUDIT_PURGE_AFTER_DAYS', 730),
    ],
];

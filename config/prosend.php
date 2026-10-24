<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sender Verification
    |--------------------------------------------------------------------------
    |
    | When enabled, only sender identities an administrator has marked as
    | verified with their provider can send. Production always requires
    | it by default; local development may send from unverified senders.
    |
    */

    'require_verified_senders' => (bool) env('PROSEND_REQUIRE_VERIFIED_SENDERS', env('APP_ENV') === 'production'),

    /*
    |--------------------------------------------------------------------------
    | Compose Limits
    |--------------------------------------------------------------------------
    */

    'compose' => [
        'max_recipients' => (int) env('PROSEND_COMPOSE_MAX_RECIPIENTS', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    |
    | Attachments are stored on a private disk and attached when the email is
    | sent. Keep the limits below what your provider accepts (Brevo allows a
    | total message size of roughly 10 MB).
    |
    */

    'attachments' => [
        'disk' => env('PROSEND_ATTACHMENTS_DISK', 'local'),
        'directory' => 'email-attachments',
        'max_files' => (int) env('PROSEND_ATTACHMENTS_MAX_FILES', 5),
        'max_file_size_kb' => (int) env('PROSEND_ATTACHMENTS_MAX_FILE_SIZE_KB', 5120),
        'accepted_mime_types' => [
            'application/pdf',
            'image/png',
            'image/jpeg',
            'image/gif',
            'text/plain',
            'text/csv',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Campaigns
    |--------------------------------------------------------------------------
    |
    | Campaign emails are spread out at the campaign's per-minute rate. Above
    | the confirmation threshold, launching requires typing the recipient
    | count, which protects against sending to a large list by mistake.
    |
    */

    'campaigns' => [
        'default_send_rate_per_minute' => (int) env('PROSEND_DEFAULT_SEND_RATE', 60),
        'max_send_rate_per_minute' => (int) env('PROSEND_MAX_SEND_RATE', 600),
        'large_audience_threshold' => (int) env('PROSEND_LARGE_AUDIENCE_THRESHOLD', 500),
        'chunk_size' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sending
    |--------------------------------------------------------------------------
    |
    | Temporary failures are retried with these delays (in seconds). After the
    | last one the message is marked as failed and can be retried manually.
    |
    */

    'sending' => [
        'queue' => env('PROSEND_QUEUE', 'default'),
        'retry_delays' => [60, 300, 900, 3600],
        'connection_timeout' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How long records are kept before `prosend:prune` removes them (approved
    | Phase 3 decision L16). Bodies and attachments go first; the message
    | record itself, with its events, is kept longer for reporting.
    |
    */

    'retention' => [
        'message_bodies_days' => (int) env('PROSEND_RETAIN_BODIES_DAYS', 90),
        'messages_months' => (int) env('PROSEND_RETAIN_MESSAGES_MONTHS', 13),
        'api_request_logs_days' => (int) env('PROSEND_RETAIN_API_LOGS_DAYS', 30),
        'api_usage_months' => (int) env('PROSEND_RETAIN_API_USAGE_MONTHS', 13),
        'audit_logs_months' => (int) env('PROSEND_RETAIN_AUDIT_MONTHS', 24),
    ],

];

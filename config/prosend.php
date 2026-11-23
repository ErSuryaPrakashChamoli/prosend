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
    | Marketing Footer
    |--------------------------------------------------------------------------
    |
    | Marketing email must say who sent it and where the business can be
    | reached; CAN-SPAM requires a valid postal address. It is shown in
    | the footer of every marketing email, under the unsubscribe link.
    |
    */

    'marketing' => [
        'postal_address' => env('PROSEND_POSTAL_ADDRESS'),
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
    | Every campaign is submitted, then approved by an administrator before
    | it sends. With a second approver required, nobody can approve a
    | campaign they created or submitted. If the eligible audience has
    | grown by more than the margin when sending starts (for example
    | after an import into a targeted list), it needs approving again.
    |
    */

    'campaigns' => [
        'default_send_rate_per_minute' => (int) env('PROSEND_DEFAULT_SEND_RATE', 60),
        'max_send_rate_per_minute' => (int) env('PROSEND_MAX_SEND_RATE', 600),
        'large_audience_threshold' => (int) env('PROSEND_LARGE_AUDIENCE_THRESHOLD', 500),
        'chunk_size' => 500,
        'approval' => [
            'require_second_approver' => (bool) env('PROSEND_CAMPAIGN_REQUIRE_SECOND_APPROVER', false),
            'audience_growth_margin_percent' => (int) env('PROSEND_CAMPAIGN_APPROVAL_GROWTH_PERCENT', 10),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sending
    |--------------------------------------------------------------------------
    |
    | Temporary failures are retried with these delays (in seconds). After the
    | last one the message is marked as failed and can be retried manually.
    |
    | Recipient domain limits cap emails per minute to one receiving domain,
    | across all providers, for example ['gmail.com' => 60]. Email over a
    | limit waits its turn. Brevo throttles per domain itself; these
    | matter most when sending through your own mail server.
    |
    */

    'sending' => [
        'queue' => env('PROSEND_QUEUE', 'default'),
        'retry_delays' => [60, 300, 900, 3600],
        'connection_timeout' => 30,
        'recipient_domain_limits' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Sandbox providers (Mailtrap Email Sandbox) capture email instead of
    | delivering it. They are refused in production unless allowed here,
    | so real email can never be routed into a sandbox by mistake.
    |
    | SMTP hosts are resolved and checked every time ProSend connects, and
    | the connection is pinned to the checked address. Link-local and cloud
    | metadata addresses are always refused. Private and loopback addresses
    | (a relay on your own network, or Mailpit) must be allowed explicitly.
    |
    */

    'providers' => [
        'allow_sandbox' => (bool) env('PROSEND_ALLOW_SANDBOX_PROVIDERS', env('APP_ENV') !== 'production'),
        'allow_private_hosts' => (bool) env('PROSEND_SMTP_ALLOW_PRIVATE_HOSTS', env('APP_ENV') === 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | The load balancers or reverse proxies in front of ProSend, as IP addresses
    | or CIDR ranges separated by commas, or * to trust any (only when the app
    | cannot be reached except through the proxy). Without this, ProSend sees
    | the proxy's address for every request, so API IP allowlists and the
    | failed-key throttle apply to the proxy instead of the real client.
    | Leave empty when clients connect directly.
    |
    */

    'trusted_proxies' => env('PROSEND_TRUSTED_PROXIES'),

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

    /*
    |--------------------------------------------------------------------------
    | Automation and Webhooks
    |--------------------------------------------------------------------------
    |
    | Automation emails are capped per prospect and per rule each day. Rule runs and webhook deliveries can use their
    | own queue (run a worker for it too, e.g. --queue=default,automation). Webhooks never go to private or loopback
    | addresses unless allowed here, separately from SMTP hosts.
    |
    */

    'automation' => [
        'queue' => env('PROSEND_AUTOMATION_QUEUE', env('PROSEND_QUEUE', 'default')),
        'max_emails_per_prospect_per_day' => (int) env('PROSEND_AUTOMATION_MAX_EMAILS_PER_PROSPECT', 3),
        'max_emails_per_rule_per_day' => (int) env('PROSEND_AUTOMATION_MAX_EMAILS_PER_RULE', 200),
    ],

    'webhooks' => [
        'allow_private_hosts' => (bool) env('PROSEND_WEBHOOKS_ALLOW_PRIVATE_HOSTS', false),
    ],

    'retention' => [
        'message_bodies_days' => (int) env('PROSEND_RETAIN_BODIES_DAYS', 90),
        'messages_months' => (int) env('PROSEND_RETAIN_MESSAGES_MONTHS', 13),
        'api_request_logs_days' => (int) env('PROSEND_RETAIN_API_LOGS_DAYS', 30),
        'api_usage_months' => (int) env('PROSEND_RETAIN_API_USAGE_MONTHS', 13),
        'audit_logs_months' => (int) env('PROSEND_RETAIN_AUDIT_MONTHS', 24),
        'automation_runs_days' => (int) env('PROSEND_RETAIN_AUTOMATION_RUNS_DAYS', 90),
        'webhook_deliveries_days' => (int) env('PROSEND_RETAIN_WEBHOOK_DELIVERIES_DAYS', 30),
    ],

];

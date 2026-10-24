# ProSend

ProSend is an email operations platform built on Laravel 13 and Filament 5. It manages contacts and consent, sends individual emails and rate-limited campaigns through Brevo (or any SMTP provider), and tracks every message from queue to delivery.

## Requirements

- PHP 8.5, Composer, Node.js
- MySQL (the default database is `db_email`)
- A Brevo account with a verified sender, or another SMTP provider

## First-time setup

```sh
composer install
npm install && npm run build
php artisan migrate
php artisan prosend:setup
```

`prosend:setup` does two things, and is safe to run again:

1. Imports the `MAIL_*` SMTP settings from `.env` as an SMTP provider, stores the password encrypted, and creates an unverified sender identity from `MAIL_FROM_ADDRESS`. For Brevo it sets a daily limit of 300, the free-plan quota; change it to match your plan.
2. Asks for the first administrator's name, email, and password.

Then sign in at `/admin`.

Use `prosend:setup`, not `make:filament-user`, for the first account. Accounts created any other way get the **Viewer** role until an administrator changes it.

## Running ProSend

Every email is sent from the queue, so **a queue worker must be running** or nothing is sent:

```sh
php artisan queue:work --queue=default
```

In production, run the worker under a process supervisor such as Supervisor or systemd, and restart it after each deploy (`php artisan queue:restart`). Scheduled emails and campaigns are delayed queue jobs.

Laravel's scheduler must also run every minute. It re-checks sending domains daily (`prosend:check-domains`, 03:00) and reviews warm-up plans daily (`prosend:evaluate-warmups`, 00:05):

```cron
* * * * * cd /path/to/prosend && php artisan schedule:run >> /dev/null 2>&1
```

`DB_QUEUE_RETRY_AFTER` (660 seconds) must stay longer than the slowest job, which is campaign preparation at 600 seconds. Otherwise a running job can be started a second time.

## Before sending for real

1. **Verify the sender with Brevo.** In Brevo, go to Senders, Domains & Dedicated IPs. Authenticate your domain (SPF, DKIM, DMARC) and verify the From address. Mail from an unverified sender is rejected or lands in spam. A free mailbox such as Gmail is a poor From address for bulk mail; use a domain you own.
2. **Add a Brevo API key** to the provider under Infrastructure > SMTP providers (Brevo: SMTP & API > API keys; it is different from the SMTP key). ProSend then checks sender and domain verification with Brevo directly: use **Check with Brevo** under Deliverability > Sender identities. Without an API key, an administrator confirms verification manually with **Mark as verified**.
3. **Add your domain** under Deliverability > Domain authentication and click **Check now**. The page lists the records Brevo expects, what public DNS shows, and what to fix.
4. **Set up delivery webhooks.** Open the provider under Infrastructure > SMTP providers and choose More > Set up webhook. Copy the URL, which is shown once. In Brevo, add it under Transactional > Settings > Webhook and select delivered, soft bounce, hard bounce, invalid email, blocked, error, spam, unsubscribed, opened, and clicked. Without webhooks ProSend only knows that Brevo *accepted* an email, not whether it was delivered, bounced, or reported as spam.
5. **Set `APP_URL` to the public HTTPS address.** Unsubscribe links and webhook URLs are built from it, and webhooks cannot reach `localhost`.
6. **Set `PROSEND_REQUIRE_VERIFIED_SENDERS=true`.** It defaults to true when `APP_ENV=production`.
7. **For a new domain or account, create a warm-up plan** (see below) before sending campaigns.

## How sending works

- **Compose email** sends to up to 50 addresses (`PROSEND_COMPOSE_MAX_RECIPIENTS`). Each address in To gets its own email, so recipients never see each other. CC and BCC are only allowed with a single To address.
- **Campaigns** send one email to a list, a selection of contacts, or all contacts. Before launch, ProSend shows the eligible, skipped, and duplicate counts, the provider quota left, and the estimated duration. Above 500 recipients (`PROSEND_LARGE_AUDIENCE_THRESHOLD`) you must type the recipient count to confirm. Emails are spaced out at the campaign's per-minute rate, and the provider's per-minute and daily limits are always enforced.
- **Transactional vs. marketing:** marketing email goes only to contacts with recorded consent who have not unsubscribed, and always carries an unsubscribe footer plus one-click `List-Unsubscribe` headers. Transactional email ignores unsubscribes, but never goes to addresses that hard-bounced, complained, or were suppressed manually.
- **Suppression list:** hard bounces, invalid addresses, spam complaints, and unsubscribes are added automatically from Brevo webhooks and unsubscribe links. Eligibility is checked again when each email is sent. Importing a contact never removes a suppression or an unsubscribe. Only administrators can remove a suppression.

### Message statuses

| Status | Meaning |
| --- | --- |
| Queued | Waiting for the queue worker. |
| Retrying | A temporary error (SMTP 4xx or a lost connection); another attempt is scheduled after 1, 5, 15, then 60 minutes. |
| Accepted by provider | Brevo took the email. This does **not** prove it reached the inbox. |
| Delivered | Brevo reported that the receiving server accepted it (webhook). |
| Soft bounce / Hard bounce / Spam complaint | Reported by Brevo. Hard bounces and complaints suppress the address. |
| Failed | Rejected (SMTP 5xx) or out of retries. Configuration problems (bad credentials, unverified sender) can be retried; rejected addresses cannot. |
| Status unknown | The connection broke after the email was handed over, so it may already be on its way. ProSend never retries these automatically, to avoid duplicates. A person can retry one after accepting that risk. |
| Skipped (suppressed) / Cancelled | Not sent. |

Each send job claims its message atomically before contacting the provider, and the message's Message-ID is stored before sending. So a retried or duplicated job cannot send the same email twice, and webhooks always match the right message.

## Roles

| Role | Can |
| --- | --- |
| Administrator | Everything, including SMTP providers, sender identities, domain authentication, warm-up plans, users, removing suppressions, and audit logs. |
| Sender | Manage contacts, lists, segments, and templates; import and export; compose email; and run campaigns. |
| Viewer | Read the dashboard, email activity, contacts, and campaigns. |

Provider changes (never the secrets themselves), role changes, sender verification, campaign launches, pauses, cancellations and retries, and suppression changes are recorded under System > Audit logs.

## Importing contacts

Contacts > Import / export > Import CSV accepts any CSV with an email column, maps the columns, and processes the file on the queue. Rows that fail validation can be downloaded as a CSV from the completion notification. Existing contacts are matched by email, and empty cells never overwrite saved data. Every import must confirm that the contacts asked to receive email; purchased, rented, or scraped lists must not be used.

For small lists, Contacts > Import / export > Paste email addresses accepts addresses separated by commas, spaces, or new lines.

## Templates

Email Operations > Email templates holds reusable content. Every saved change becomes a new numbered version. Restoring an old version saves it as a new version, so history is never rewritten. **Add sample templates** creates welcome, email verification, password reset, account notification, order update, newsletter, and announcement templates.

- Transactional and marketing templates are kept apart: a campaign or composed email can only use a template of its own type, and only active templates are offered.
- Variables: the built-in merge tags are `first_name`, `last_name`, `company_name`, `email`, and `unsubscribe_url`. Any other `{{ name }}` is read from the contact's custom attributes, for example `{{ action_url }}` or `{{ order_number }}`. Values are always escaped, and links built from them are sanitized.
- **Required variables:** contacts without a value for one of these are skipped by campaigns, and rejected in Compose. Recipients never get an email with a blank link.

## Segments

Audiences > Segments are rule-based audiences that update themselves. Rules cover consent, list membership, tags, email domain, company, custom attributes, date added, unsubscribe status, recent opens or clicks (approximate), and recent sends. A campaign evaluates its segment when it starts sending. Suppression and consent checks still apply on top.

## Domain authentication

Deliverability > Domain authentication reports two things separately:

1. **Found in DNS:** SPF, DKIM (Brevo selectors `brevo1`/`brevo2` or `mail`), and DMARC with its policy. A record existing is not proof that it is correct.
2. **Verified by the provider:** with a Brevo API key, Brevo's own `verified` and `authenticated` status, plus the exact records Brevo expects.

A domain is **Ready** only when the provider reports it authenticated (where an API key allows checking) and both DKIM and DMARC records exist. Campaigns show a warning when the sender's domain isn't ready. DNS lookups use the server's resolver and can lag behind recent changes.

## Warm-up planner

Deliverability > Warm-up planner limits daily volume for a provider, or for one domain on it, so sending grows gradually. A plan does nothing until an administrator approves it. Once active, ProSend enforces it: email over today's limit, outside the sending hours, or under a paused plan waits and is sent later. It is never dropped.

- The limit never exceeds the provider's daily limit.
- Each day at 00:05 the plan is reviewed against the last 7 days of real results. The limit rises one step only when hard bounces and complaints are within the plan's limits, at least 80% of the current limit was actually used, and the ramp interval has passed.
- If hard bounces or complaints go over the limits, the plan pauses itself and says why. Throttling, unauthenticated domains, and too little data produce recommendations instead of increases.
- ProSend never simulates opens, clicks, or replies, and never sends to fake mailboxes. Opens and clicks are not used in the review because privacy proxies and scanners make them unreliable. A schedule does not guarantee inbox placement. `App\Services\Warmup\Contracts\DeliverabilityMonitor` is the extension point for a real third-party monitoring service.

## Reports

The dashboard covers delivery stats, sending volume, outcomes, bounce and complaint rate trends, volume by sending domain, provider performance, campaign performance, and queue throughput. All of it is filterable by date, email type, provider, campaign, and sending domain. Email activity and the suppression list have tabs for bounces, complaints, failures, and unsubscribes. Email activity and each campaign's recipients can be exported to CSV.

## Sending API

Other applications can send transactional email through ProSend and read its status, without ever holding SMTP credentials. The full reference is [docs/api/openapi.yaml](docs/api/openapi.yaml) (OpenAPI 3.1).

### API keys

An administrator creates keys under Infrastructure > API keys. Each key has:

- **Scopes:** `emails:send`, `emails:send_marketing` (marketing email also needs `emails:send`), `emails:read` (emails this key sent), and `emails:read_all` (every email).
- **Allowed senders:** the sender identities it may use as From. Required for sending keys.
- **Limits:** requests per minute (default 60), an optional daily send limit, an optional IP allowlist (addresses or CIDR ranges), and an optional expiry.
- **Mode:** a **live** key (`psk_live_…`) sends. A **test** key (`psk_test_…`) runs every check and records the email with the status *Test (not sent)*, but never contacts a provider. Test emails are left out of reports.

The full key is shown **once**, when it is created or rotated. ProSend stores only a hash of the secret, so a lost key cannot be recovered: rotate it. Rotating issues a new key and keeps the old one working for a grace period you choose (none, 1, 3, or 7 days). Revoking stops a key immediately. Creating, editing, rotating, and revoking keys are audit-logged; the logs never include the secret.

Send the key in the `Authorization` header. Keys in URLs are refused, so they don't end up in server logs or browser history.

### Examples

```sh
curl https://prosend.example.com/api/v1/emails \
  -H "Authorization: Bearer $PROSEND_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: order-1042-receipt" \
  -d '{
        "from": "billing@acme.test",
        "to": [{"email": "jane@example.com", "name": "Jane Doe"}],
        "purpose": "transactional",
        "template_id": 12,
        "variables": {"order_number": "1042"},
        "tags": ["receipt"],
        "metadata": {"order_id": "1042"}
      }'
```

```json
{
  "data": [{ "id": "9d1c…", "to": "jane@example.com", "status": "queued" }],
  "meta": { "request_id": "req_01jd…", "idempotent_replay": false, "test_mode": false }
}
```

```sh
curl https://prosend.example.com/api/v1/emails/9d1c… -H "Authorization: Bearer $PROSEND_API_KEY"
curl "https://prosend.example.com/api/v1/emails?status=bounced&tag=receipt" -H "Authorization: Bearer $PROSEND_API_KEY"
curl https://prosend.example.com/api/v1/emails/9d1c…/events -H "Authorization: Bearer $PROSEND_API_KEY"
curl https://prosend.example.com/api/v1/me -H "Authorization: Bearer $PROSEND_API_KEY"
```

### Behaviour to rely on

- **`202` means queued, not delivered.** `accepted` means the provider took the email; only `delivered` (from provider webhooks) confirms delivery.
- **Retry safely with `Idempotency-Key`.** Repeating a request with the same key and body within 24 hours returns the original response, with the header `Idempotent-Replayed: true`, and sends nothing new. The same key with a different body is refused (`422 idempotency_key_reused`).
- **The same rules as the panel apply:** suppressed addresses and, for marketing, recipients without consent are refused (`recipient_not_eligible`); templates must be active and of the same purpose; HTML is sanitised; marketing email gets the unsubscribe footer and headers.
- **Errors** always look like `{"error": {"code": "missing_scope", "message": "…", "request_id": "req_…"}}`. Build on the `code`; the message may change. Quote the `request_id` (also in the `X-Request-Id` header) when asking for help.
- **Rate limits** return `429` with `Retry-After`. Twenty failed authentication attempts from one IP within 10 minutes block that IP for the rest of the window.

Every request is logged (method, path, status, duration, IP, error code; never headers or bodies) and counted per key per day, shown on the key's page.

## Data retention

`prosend:prune` runs daily at 02:30 and deletes, in batches:

| Data | Kept for | Setting |
| --- | --- | --- |
| Email bodies, attachments, and per-send variables | 90 days (the message record stays) | `PROSEND_RETAIN_BODIES_DAYS` |
| Email messages and their events | 13 months | `PROSEND_RETAIN_MESSAGES_MONTHS` |
| API request logs | 30 days | `PROSEND_RETAIN_API_LOGS_DAYS` |
| API usage counts | 13 months | `PROSEND_RETAIN_API_USAGE_MONTHS` |
| Audit logs | 24 months | `PROSEND_RETAIN_AUDIT_MONTHS` |

Messages still queued, retrying, or sending are never pruned.

## Configuration

All ProSend settings live in `config/prosend.php`, and most can be overridden in `.env`; see the `PROSEND_*` keys in `.env.example`. SMTP credentials for providers added in the panel are stored encrypted with `APP_KEY`. **Do not rotate `APP_KEY`** without re-entering provider passwords, or they can no longer be decrypted.

## Development

```sh
composer run dev                 # app server, queue worker, logs, and Vite
php artisan test --compact       # the full test suite (in-memory SQLite)
vendor/bin/pint --format agent   # code style
```

Tests never contact a real provider. `Tests\Fakes\FakeMailProviderDriver` records messages in memory and can simulate any SMTP failure.

## Not built yet

Phase 3 is being built in milestones; the approved plan is in [docs/specs/phase-3.md](docs/specs/phase-3.md). The sending API (milestone 1) is above. Still to come:

- API endpoints for contacts, lists, and campaigns.
- Outgoing webhooks, so your applications learn about deliveries and bounces without polling.
- Two-person approval for contact imports, with a preview before processing.
- Automation workflows.
- Additional providers (Resend, Mailjet, SMTP2GO, Amazon SES) and fallback between providers.

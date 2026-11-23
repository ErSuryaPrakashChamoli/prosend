# ProSend

ProSend is an email operations platform built on Laravel 13 and Filament 5. It manages contacts and consent, sends individual emails and rate-limited campaigns through Brevo, Mailtrap, or any SMTP provider, and tracks every message from queue to delivery.

## Requirements

- PHP 8.5, Composer, Node.js
- MySQL (the default database is `db_email`)
- A Brevo or Mailtrap account with a verified sending domain, or another SMTP provider

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
7. **Set `PROSEND_POSTAL_ADDRESS`** to your business postal address. It appears in the footer of every marketing email; US law (CAN-SPAM) requires one, and campaigns warn while it is empty.
8. **For a new domain or account, create a warm-up plan** (see below) before sending campaigns.

## How sending works

- **Compose email** sends to up to 50 addresses (`PROSEND_COMPOSE_MAX_RECIPIENTS`). Each address in To gets its own email, so recipients never see each other. CC and BCC are only allowed with a single To address.
- **Campaigns** send one email to a list, a selection of contacts, or all contacts. Before submission, and again before approval, ProSend shows the eligible, skipped, and duplicate counts, the provider quota left, and the estimated duration. Above 500 recipients (`PROSEND_LARGE_AUDIENCE_THRESHOLD`) you must type the recipient count to confirm. Emails are spaced out at the campaign's per-minute rate, and the provider's per-minute and daily limits are always enforced.
- **Campaign approval:** every campaign is sent in two steps. A Sender or administrator chooses **Submit for approval**. The campaign is then locked, and administrators get a notification in the panel. An administrator chooses **Approve and send** (or **Approve and schedule**), which records who approved it and for how many recipients. They can also choose **Return to draft** with a reason, and the submitter can **Withdraw** it. With `PROSEND_CAMPAIGN_REQUIRE_SECOND_APPROVER=true`, nobody can approve a campaign they created or submitted; leave it off if you have only one administrator. If the eligible audience has grown by more than `PROSEND_CAMPAIGN_APPROVAL_GROWTH_PERCENT` (10%) by the time sending starts, for example after an import into a targeted list, the campaign goes back to Pending approval instead of sending.
- **Receiving-domain limits** (optional): `recipient_domain_limits` in `config/prosend.php` caps emails per minute to one receiving domain across all providers, for example `'gmail.com' => 60`. Email over a limit waits. Brevo already throttles per domain, so this matters most when sending through your own mail server.
- **Transactional vs. marketing:** marketing email goes only to contacts with recorded consent who have not unsubscribed, and always carries an unsubscribe footer plus one-click `List-Unsubscribe` headers. Transactional email ignores unsubscribes, but never goes to addresses that hard-bounced, complained, or were suppressed manually.
- **Suppression list:** hard bounces, invalid addresses, spam complaints, and unsubscribes are added automatically from Brevo webhooks and unsubscribe links. Eligibility is checked again when each email is sent. Importing a contact never removes a suppression or an unsubscribe. Only administrators can remove a suppression.

### Message statuses

| Status | Meaning |
| --- | --- |
| Queued | Waiting for the queue worker. |
| Retrying | A temporary error (SMTP 4xx or a lost connection); another attempt is scheduled after 1, 5, 15, then 60 minutes. |
| Accepted by provider | The provider took the email. This does **not** prove it reached the inbox. |
| Delivered | Brevo reported that the receiving server accepted it (webhook). |
| Soft bounce / Hard bounce / Spam complaint | Reported by Brevo. Hard bounces and complaints suppress the address. |
| Failed | Rejected (SMTP 5xx) or out of retries. Configuration problems (bad credentials, unverified sender) can be retried; rejected addresses cannot. |
| Status unknown | The connection broke after the email was handed over, so it may already be on its way. ProSend never retries these automatically, to avoid duplicates. A person can retry one after accepting that risk. |
| Skipped (suppressed) / Cancelled | Not sent. |
| Captured in sandbox | A sandbox provider (Mailtrap Email Sandbox) kept the email for inspection. Nobody received it, and it is left out of reports. |

Each send job claims its message atomically before contacting the provider, and the message's Message-ID is stored before sending. So a retried or duplicated job cannot send the same email twice, and webhooks always match the right message.

## SMTP providers and Mailtrap

Infrastructure > SMTP providers holds any number of providers: Brevo, Mailtrap Email Sending (live, transactional or Bulk Stream), Mailtrap Email Sandbox (testing only), and generic SMTP for anything else, such as Amazon SES, SendGrid, Microsoft 365, or Google Workspace SMTP. Each sender identity sends through exactly one provider, so choosing the From address chooses the provider. There is no automatic fallback.

- **Sandbox email is captured, never delivered.** It gets the status *Captured in sandbox*, is marked as a sandbox everywhere a sender can be picked, and campaigns warn before launch. Sandbox providers are refused in production unless `PROSEND_ALLOW_SANDBOX_PROVIDERS=true`.
- **Sandbox and live never mix.** A provider cannot switch between sandbox and live sending, a sender identity cannot move between the two while it has email waiting or a launched campaign, and a retried email is never sent the other way.
- **Network safety.** SMTP hosts are resolved and checked on every connection, and the connection is pinned to the checked address. Link-local and cloud metadata addresses are always refused; private and loopback addresses need `PROSEND_SMTP_ALLOW_PRIVATE_HOSTS=true` (the default only when `APP_ENV=local`). Changing a provider's host, or turning its encryption off, requires entering its password again.

Setup, testing, routing, and troubleshooting are covered in [docs/guides/mailtrap-and-smtp-providers.md](docs/guides/mailtrap-and-smtp-providers.md).

## Roles

| Role | Can |
| --- | --- |
| Administrator | Everything, including approving campaigns, SMTP providers, sender identities, domain authentication, warm-up plans, users, removing suppressions, and audit logs. |
| Sender | Manage contacts, lists, segments, and templates; import and export; compose email; and create, submit, pause, and cancel campaigns. |
| Viewer | Read the dashboard, email activity, contacts, and campaigns. |

Provider changes (never the secrets themselves), role changes, sender verification, campaign submissions, approvals, returns to draft, pauses, cancellations and retries, and suppression changes are recorded under System > Audit logs.

## Prospects

Contacts double as prospects. Besides name, company and consent, each has a job title, industry, website, stage (New, Active, Contacted, Replied, Interested, Converted), owner, last contacted and last reply dates, and notes. The contacts table filters and bulk-updates by stage and owner, and segments can match job title, industry, source and stage.

- **Last contacted** is set when a provider accepts a campaign or Compose email to the contact, and a New or Active prospect then moves to Contacted. Email sent through the API, such as receipts, does not count.
- **Last reply** and the Replied stage are recorded by hand: ProSend has no inbox, so it cannot see replies.
- **Unsubscribed and Do Not Contact are not stages.** They are the contact's unsubscribe and the suppression list, which every send checks. **Do not contact** on a contact's page adds a manual suppression; only an administrator can remove it.

## Importing contacts

Contacts > Import / export > Import CSV or Excel accepts a CSV file or an Excel workbook (.xlsx) with an email column, maps the columns (including job title, industry and website), and processes the file on the queue.

From a workbook, only the first visible worksheet is read, and its first row is the header:
- Formulas import their results.
- Dates arrive as `2026-03-01` (with a time if there is one).
- TRUE/FALSE cells work as consent values.
- Columns beyond the header, such as notes, are ignored.
- A workbook may declare at most 500 columns and contain at most 100,000 rows; bigger files must be split or saved as CSV. Rows that fail validation can be downloaded as a CSV from the completion notification. Existing contacts are matched by email, and empty cells never overwrite saved data. Every import must confirm that the contacts asked to receive email; purchased, rented, or scraped lists must not be used.

For small lists, Contacts > Import / export > Paste email addresses accepts addresses separated by commas, spaces, or new lines.

## Templates

Email Operations > Email templates holds reusable content. Every saved change becomes a new numbered version. Restoring an old version saves it as a new version, so history is never rewritten. **Add sample templates** creates welcome, email verification, password reset, account notification, order update, newsletter, and announcement templates.

- Transactional and marketing templates are kept apart: a campaign or composed email can only use a template of its own type, and only active templates are offered.
- Variables: the built-in merge tags are `name` (first and last name), `first_name`, `last_name`, `company_name`, `job_title`, `industry`, `website`, `email`, and `unsubscribe_url`. When a contact has no `name`, `job_title`, `industry` or `website`, a custom attribute of the same name is used instead. Any other `{{ name }}` is read from the contact's custom attributes, for example `{{ action_url }}` or `{{ order_number }}`. Values are always escaped, and links built from them are sanitized.
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

The dashboard covers delivery stats, sending volume, outcomes, bounce and complaint rate trends, volume by sending domain, provider performance, campaign performance, and queue throughput. All of it is filterable by date, email type, provider, campaign, and sending domain. An audience panel shows current totals regardless of those filters: contacts by stage, contacts eligible for marketing, suppressed addresses, unsubscribes, and campaigns by status. Email activity and the suppression list have tabs for bounces, complaints, failures, and unsubscribes. Email activity and each campaign's recipients can be exported to CSV. In every export, a cell starting with `=`, `+`, `-` or `@` gets a leading apostrophe so spreadsheets do not run it as a formula; the contact importer removes that apostrophe again.

## Sending API

Other applications can send transactional email through ProSend and read its status, without ever holding SMTP credentials. The full reference is [docs/api/openapi.yaml](docs/api/openapi.yaml) (OpenAPI 3.1).

### API keys

An administrator creates keys under Infrastructure > API keys. Each key has:

- **Scopes:** `emails:send`, `emails:send_marketing` (marketing email also needs `emails:send`), `emails:read` (emails this key sent), and `emails:read_all` (every email; includes `emails:read`).
- **Allowed senders:** the sender identities it may use as From. Required for sending keys.
- **Limits:** requests per minute (default 60), an optional daily send limit (every To, CC and BCC address counts as one email), an optional IP allowlist (addresses or CIDR ranges), and an optional expiry. Behind a load balancer or reverse proxy, set `PROSEND_TRUSTED_PROXIES` to its addresses, or ProSend sees the proxy's address instead of the caller's and IP allowlists cannot work.
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
- **Rate limits** return `429` with `Retry-After`. More than 20 requests with invalid keys from one IP within a minute block further invalid attempts from that IP for 10 minutes. Requests with a valid key are never blocked by this.
- **Attachments** must really be the type given in `content_type` (a program renamed to `.pdf` is refused), and the name the recipient sees always ends in an extension of that type.
- **API keys never reach the logs:** anything that looks like a key is masked in request logs and the application log, and a request with a key anywhere in its URL is refused.

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

Messages still queued, retrying, or sending are never pruned, and an attachment file shared by several emails is only deleted once none of them needs it. An email whose content was pruned can no longer be retried.

## Configuration

All ProSend settings live in `config/prosend.php`, and most can be overridden in `.env`; see the `PROSEND_*` keys in `.env.example`. SMTP credentials for providers added in the panel are stored encrypted with `APP_KEY`. **Do not rotate `APP_KEY`** without re-entering provider passwords, or they can no longer be decrypted.

## Development

```sh
composer run dev                 # app server, queue worker, logs, and Vite
php artisan test --compact       # the full test suite (in-memory SQLite)
vendor/bin/pint --format agent   # code style
```

Tests never contact a real provider or DNS. `Tests\Fakes\FakeMailProviderDriver` records messages in memory and can simulate any SMTP failure, and `Tests\Fakes\FakeDnsResolver` answers every lookup.

Tests refuse to run unless the database is in-memory SQLite. A cached configuration (`php artisan config:cache` or `optimize`) overrides `phpunit.xml`, and the tests would then erase the real database, so run `php artisan config:clear` first.

## Not built yet

Phase 3 is being built in milestones; the approved plan is in [docs/specs/phase-3.md](docs/specs/phase-3.md). The sending API (milestone 1) is above. Still to come:

- API endpoints for contacts, lists, and campaigns.
- Outgoing webhooks, so your applications learn about deliveries and bounces without polling.
- Two-person approval for contact imports, with a preview before processing.
- Automation workflows.
- Processing Mailtrap's delivery webhooks. Until then, Mailtrap email stays *Accepted by provider*.
- API-based providers (Resend, Mailjet, SMTP2GO, Amazon SES) and fallback between providers. SMTP endpoints of these work today as generic SMTP.

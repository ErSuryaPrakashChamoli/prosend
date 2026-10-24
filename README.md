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

In production, run the worker under a process supervisor such as Supervisor or systemd, and restart it after each deploy (`php artisan queue:restart`). Scheduled emails and campaigns are delayed queue jobs, so no cron entry or scheduler is needed.

`DB_QUEUE_RETRY_AFTER` (660 seconds) must stay longer than the slowest job, which is campaign preparation at 600 seconds. Otherwise a running job can be started a second time.

## Before sending for real

1. **Verify the sender with Brevo.** In Brevo, go to Senders, Domains & Dedicated IPs. Authenticate your domain (SPF, DKIM, DMARC) and verify the From address. Mail from an unverified sender is rejected or lands in spam. A free mailbox such as Gmail is a poor From address for bulk mail; use a domain you own.
2. **Mark the sender as verified in ProSend** under Deliverability > Sender identities. ProSend cannot check this itself yet, so this records an administrator's confirmation.
3. **Set up delivery webhooks.** Open the provider under Infrastructure > SMTP providers and choose More > Set up webhook. Copy the URL, which is shown once. In Brevo, add it under Transactional > Settings > Webhook and select delivered, soft bounce, hard bounce, invalid email, blocked, error, spam, unsubscribed, opened, and clicked. Without webhooks ProSend only knows that Brevo *accepted* an email, not whether it was delivered, bounced, or reported as spam.
4. **Set `APP_URL` to the public HTTPS address.** Unsubscribe links and webhook URLs are built from it, and webhooks cannot reach `localhost`.
5. **Set `PROSEND_REQUIRE_VERIFIED_SENDERS=true`.** It defaults to true when `APP_ENV=production`.

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
| Administrator | Everything, including SMTP providers, sender identities, users, removing suppressions, and audit logs. |
| Sender | Manage contacts and lists, import and export, compose email, and run campaigns. |
| Viewer | Read the dashboard, email activity, contacts, and campaigns. |

Provider changes (never the secrets themselves), role changes, sender verification, campaign launches, pauses, cancellations and retries, and suppression changes are recorded under System > Audit logs.

## Importing contacts

Contacts > Import / export > Import CSV accepts any CSV with an email column, maps the columns, and processes the file on the queue. Rows that fail validation can be downloaded as a CSV from the completion notification. Existing contacts are matched by email, and empty cells never overwrite saved data. Every import must confirm that the contacts asked to receive email; purchased, rented, or scraped lists must not be used.

For small lists, Contacts > Import / export > Paste email addresses accepts addresses separated by commas, spaces, or new lines.

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

Phase 1 covers the workflows above. Planned next:

- Email templates with versions and sample templates; segments; contact import preview.
- Domain authentication checks (SPF, DKIM, DMARC lookups) and Brevo sender verification through its API.
- A warm-up planner driven by real sending history, and deliverability reports.
- API keys, outgoing webhooks, automation, additional providers (Resend, Mailjet, SMTP2GO, Amazon SES), and fallback between providers.

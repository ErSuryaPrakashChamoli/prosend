<!-- Exported from the reviewed Claude Doc https://claude.ai/artifact/8zXXsBzfQzWukaruXyoY5M on 2026-10-24. All decisions in section L approved on 2026-10-24. Diagrams live in the doc. -->

# ProSend Phase 3 Specification (for approval)

Oct 24, 2026 · @Fynnedge

Phase 3 is specified here and not started: no code, packages, migrations, API keys or deployments were changed to produce this document. Work begins only after you answer the decisions in section L that block milestone M1; the rest can follow before their milestone.

Three findings shape everything below:

- **The missing specification cannot be recovered.** No copy exists in the repository; the only source is your original message in this session, which stops mid-sentence in section 13. Section B proposes a continuation for your approval.
- **The two-person import approval rule and import template SG-INV-1.2 do not exist** in the repository, the original specification, or this session. ProSend imports today with no approval step. Section G proposes the rule; the template itself must come from you.
- **Laravel Sanctum is not installed.** Section D recommends ProSend's own hashed API keys instead, so Phase 3 needs no new package unless you choose otherwise.

Labels used throughout:

| Label | Meaning |
| --- | --- |
| RECOVERED | Taken from a source, cited inline |
| PROPOSED | New; becomes a requirement only when you approve it |
| VERIFIED | An external provider fact checked against its official documentation, linked |
| BUILT | Already implemented in Phases 1 and 2 |

## A. Recovered specification

Sections 1 to 12 and the first part of section 13 survive in your original message; nothing after the words "Use provider webhooks or" exists anywhere. Requirements from your Phase 3 brief of 24 October are treated as stated by you and cited as "Brief §n". The original message is cited as "Spec §n".

### Sources searched

| Source | Where | What it holds |
| --- | --- | --- |
| Original specification | First pasted message in this session | Spec §1 to §12 complete, §13 cut off mid-sentence |
| CLAUDE.md | Repository root | Laravel Boost coding guidelines; no product requirements |
| AGENTS.md | Repository root | Laravel Boost setup steps; no product requirements |
| README.md | Repository root | Operations guide for Phases 1 and 2, written in this session |
| Master specification, ADRs, decision register | Searched the whole repository | None exist |
| Phase 1 and 2 reports | This session's chat only | Summarised in README.md |
| Migrations, models, routes, tests | database/migrations (27), app/, routes/, tests/ (253 tests) | Phases 1 and 2 as built; no Phase 3 code |
| Session memory | Claude Code project memory | Records that the specification was truncated |

A text search of the repository for "SG-INV", "two-person", "maker-checker", "four-eyes" and "decision register" found nothing.

### Recovered requirements that Phase 3 must honour

| Requirement | Source | Status |
| --- | --- | --- |
| API access for external applications | Spec §3 item 17 | Phase 3 |
| Webhook integration | Spec §3 item 18 | Phase 3 |
| Email automation | Spec §3 item 16 | Phase 3 |
| Billing-ready architecture for future SaaS use | Spec §3 item 20 | Phase 3 design only (section L) |
| Navigation: API Keys, Webhooks, Queue Monitor, Failed Emails, Sending Accounts | Spec §4 Infrastructure | Phase 3 |
| Navigation: Transactional Emails, Scheduled Emails, Automation Workflows | Spec §4 Email operations | Phase 3 |
| Navigation: Import Contacts, Unsubscribes | Spec §4 Audiences | Unsubscribes BUILT as a tab; Import Contacts in Phase 3 |
| Navigation: Delivery Diagnostics, System Health, Application Settings | Spec §4 | Phase 3 |
| Show only navigation the user may access | Spec §4 | BUILT; applies to new pages |
| Future providers: Resend, Mailjet, SMTP2GO, Amazon SES | Spec §7 | Phase 3 |
| Safe failover: no blind resend when the outcome is ambiguous; idempotency and delivery-state tracking | Spec §7 Provider fallback | Phase 3 (state tracking BUILT) |
| Do not bypass provider restrictions, limits or suspensions | Spec §7 | BUILT; applies to new providers |
| Preview imports, map columns, report rejected rows, large imports on queues | Spec §9 | Mapping, rejected rows and queues BUILT; preview in Phase 3 |
| Routine imports cannot re-add suppressed contacts | Spec §9 | BUILT |
| Campaign state "Validated" between Draft and Scheduled | Spec §11 | Not built (checks run at launch instead); section L |
| Track message ID, provider message ID, attempts, acceptance, delivery, bounce class, error code | Spec §13 | BUILT |
| Record only delivery events backed by evidence; SMTP success is not delivery | Spec §13 | BUILT |
| Never hardcode credentials; never expose secrets in logs, UI, API responses, Git or docs | Spec §2 | BUILT; applies to API keys and webhook secrets |

### Not recoverable

- The rest of Spec §13 and every section after it, including any detail on the API, webhooks, users and roles, testing and documentation.
- The two-person import approval rule. Brief §7 calls it existing, but no source defines it and the current importer has no approval step.
- Import template SG-INV-1.2. No file, column list or reference to it exists, so it cannot be preserved until you supply it.

## B. Proposed continuation (PROPOSED)

Everything in this section is new and becomes a requirement only if you approve it (decision L1). Sections 14 to 16 are detailed later in this document; the rest are stated here in full.

| Section | Proposed requirement | Detail |
| --- | --- | --- |
| 13, rest | Delivery confirmation comes from provider webhooks, or from a provider's event API where one exists. With neither, a message stays "Accepted by provider" and is never shown as delivered. | Section F |
| 13.1 Retention | Keep message records and events 13 months, rendered bodies and attachments 90 days, audit logs 24 months. A nightly job prunes in batches. | Section H |
| 14 API access | Versioned REST API for external applications, authenticated by scoped, hashed API keys. | Section D |
| 15 Outgoing webhooks | Signed, asynchronous notifications of email and campaign events to customer endpoints, with SSRF protection. | Section E |
| 16 Automation | Bounded workflows triggered by approved events or schedules, with loop and volume limits. | Section G |
| 17 Users, roles and permissions | Keep the Admin, Sender and Viewer roles. Add an import approver permission. Require multi-factor sign-in for administrators. | Sections G and L |
| 18 Operations pages | Queue Monitor, Failed Emails, Delivery Diagnostics, System Health and Application Settings pages for administrators. | Section C |
| 19 Privacy | Erase a contact on request: delete the contact, keep a hashed suppression so they are never re-imported, anonymise past message records. | Section H |
| 20 Testing | No test reaches a real provider, DNS or network. Every phase ends with an independent code review. Existing tests are never deleted. | Section J |
| 21 Documentation | The README operations guide, an OpenAPI 3.1 file for the API, and copy-paste examples, kept in step with each release. | Section D |
| 22 Billing readiness | Count API calls and sends per key and per month now. Defer multi-tenant accounts until commercialisation is decided. | Section L |

## C. Phase 3 implementation plan

Phase 3 ships in six milestones, each ending with its tests passing, an independent code review and your sign-off before the next starts. The order puts duplicate-send prevention and the provider interface first, because the API, webhooks, automation and fallback all send through them.

&#91;embedded content: Phase 3 milestones · arrows show what each one builds on\]

M3 depends on nothing new and can run alongside M1 or M2 once decisions L11 and L12 are made; M6 closes the phase.

The API routes are registered by hand in bootstrap/app.php. Laravel's `install:api` command is not used, because it installs Sanctum ([Laravel 13 docs](https://github.com/laravel/docs/blob/13.x/sanctum.md)).

### Feature by feature

Each feature below lists what it must do; sections D to J hold the detail it points to.

**1. Sending API (M1).** An external application sends transactional email with one HTTPS call and never sees SMTP credentials.

- Workflow: the app posts the email; ProSend validates it, checks suppression and sender permission, queues one message per recipient, and returns their ids with 202. The app then polls the status, or receives webhooks.
- Data: new columns on email\_messages for the API key, source, tags and metadata (section H).
- Endpoints: `POST /api/v1/emails`, `GET /api/v1/emails`, `GET /api/v1/emails/{id}` and `GET /api/v1/emails/{id}/events` (section D).
- Authorization: scope `emails:send`; marketing email also needs `emails:send_marketing` (decision L5); the From address must be one of the key's allowed sender identities.
- Errors: 401 bad key, 403 missing scope or sender, 422 validation or suppressed recipient, 429 rate limited.
- Acceptance: an email sent with a valid key reaches "Accepted by provider" through the existing pipeline. A suppressed address is refused with its reason. No request ever exposes a credential.
- External: none beyond the configured provider.

**2. API key lifecycle (M1).** Administrators create, scope, rotate and revoke keys and see their usage.

- Workflow: an admin creates a key in Infrastructure > API Keys, picks scopes, allowed senders and limits, and copies the key, which is shown once. Rotation issues a new key and keeps the old one valid for a chosen grace period of 0 to 7 days. Revocation takes effect on the next request.
- Data: api\_keys, api\_key\_daily\_usage (section H).
- Authorization: administrators only. A key can never create or change keys.
- Acceptance: the stored value is a hash; the full key appears only in the creation response; a revoked or expired key gets 401 within one request; usage counts match requests made.

**3. API authentication and rate limiting (M1).**

- `Authorization: Bearer <key>` header only. Keys in query strings are rejected so they never land in access logs.
- Per-key limits per minute and sends per day; per-IP throttling of failed authentication; standard rate-limit headers and `Retry-After` on 429.
- Acceptance: requests over the limit get 429 and send nothing.

**4 and 5. Outgoing webhooks (M2).** Customers subscribe an HTTPS endpoint to chosen events and receive signed, retried deliveries.

- Workflow: create the endpoint, choose events, copy the signing secret (shown once), send a test event. Events are queued per endpoint and retried with backoff; every attempt is logged, and failed deliveries can be redelivered by hand.
- Data: webhook\_endpoints, webhook\_events, webhook\_deliveries, webhook\_delivery\_attempts.
- Authorization: administrators in the panel; `webhooks:manage` through the API.
- Acceptance: a delivery counts as delivered only on a 2xx response. Signatures verify with the documented algorithm. Private and metadata addresses are refused at creation and on every attempt.

**6. Automation (M4).** Bounded workflows send approved email in response to approved events or schedules.

- Workflow: a Sender builds a draft (trigger, then steps); activation validates loops and limits; runs are logged step by step.
- Data: automation\_workflows, automation\_workflow\_versions, automation\_runs, automation\_run\_steps, automation\_events.
- Authorization: Senders draft; activation of workflows that send marketing email needs an Admin (decision L13).
- Acceptance: a contact enters a workflow at most once per trigger event. No workflow can trigger itself. Every send goes through suppression, consent, warm-up and provider limits.

**7. Additional providers (M5).** Each provider is a separate approval (decision L9). A provider is added behind the existing driver interface and tested with a fake before any real credentials are used.

**8. Safe fallback (M5).** A sender may name a fallback provider. The message moves to it only when the first provider definitely did not accept it (section F).

**9. Import preview and approval (M3).** CSV uploads are validated, mapped, previewed, approved by a second person, then processed in the background (section G).

**10. Idempotency and duplicate prevention (M1).** An `Idempotency-Key` header makes retries of the same request return the original result instead of sending again. This adds to the per-message claim and the no-fallback-on-ambiguity rule already built (section D).

**11. API documentation (M1, completed in M6).** An OpenAPI 3.1 file, a reference page in the panel, and curl, PHP and JavaScript examples for every endpoint.

**12. Security, testing and operations (all milestones).** See the threat model (section I), the tests (section J) and deployment (section K).

## D. API specification (PROPOSED)

The API is versioned under `/api/v1`, authenticated only by ProSend API keys, and returns JSON in one envelope for every success and error. It runs without sessions or cookies, so a signed-in panel user can never call it by accident.

### Authentication: ProSend API keys (recommended over Sanctum, decision L2)

- Format `psk_live_<key id, 12 characters>_<secret, 40 characters>`, from 238 bits of randomness. The recognisable prefix lets secret scanners and log filters find leaked keys.
- Only a SHA-256 hash of the secret is stored. The key id is indexed for lookup, and the hash is compared in constant time.
- The full key is shown once, in the creation response. Afterwards the panel shows only `psk_live_<key id>_…` and the last four characters.
- Each key has a name, scopes, allowed sender identities, a per-minute limit, a daily send limit, optional IP allowlist (CIDR), optional expiry, last-used time and IP, and revocation details.
- Rotation creates a successor key; the old key keeps working for a grace period of 0 to 7 days, then stops.
- Keys belong to the installation, created by an administrator. They are not tied to a user, so removing a person does not break integrations; the creator is recorded for audit. Multi-tenant scoping is decision L3.
- The `Authorization` header and request bodies are never logged. A log filter masks anything matching the key pattern.

Sanctum would also store hashed tokens with abilities ([Laravel 13 Sanctum docs](https://github.com/laravel/docs/blob/13.x/sanctum.md)), but its tokens belong to a user model and it adds a package. Per-key sender lists, IP allowlists, daily send caps and rotation grace would all need custom work on top.

### Scopes

| Scope | Allows |
| --- | --- |
| `emails:send` | Send transactional email |
| `emails:send_marketing` | Send marketing email (consent, unsubscribe and suppression rules apply) |
| `emails:read` | Read emails this key sent |
| `emails:read_all` | Read every email |
| `contacts:read` / `contacts:write` | Read contacts; create and update them (never unsuppress) |
| `campaigns:read` / `campaigns:write` | Read campaigns; create and edit drafts |
| `campaigns:launch` | Launch, pause, resume and cancel campaigns |
| `webhooks:manage` | Manage outgoing webhook endpoints and redeliver |
| `events:write` | Post custom events that can trigger automations |

### Endpoints

| Method and path | Scope | Purpose |
| --- | --- | --- |
| `GET /api/v1/me` | any | Key name, scopes, limits, remaining quota |
| `POST /api/v1/emails` | `emails:send` | Send to 1 to 50 recipients; each gets its own message |
| `GET /api/v1/emails` | `emails:read` | List with filters: status, tag, recipient, created range |
| `GET /api/v1/emails/{id}` | `emails:read` | Status, timestamps, error, attempts |
| `GET /api/v1/emails/{id}/events` | `emails:read` | Event history |
| `GET /api/v1/contacts`, `GET /api/v1/contacts/{id}` | `contacts:read` | List and read; lookup by email allowed |
| `PUT /api/v1/contacts` | `contacts:write` | Create or update by email; consent fields need a source |
| `PATCH /api/v1/contacts/{id}` | `contacts:write` | Update fields, lists, tags |
| `POST /api/v1/contacts/{id}/unsubscribe` | `contacts:write` | Record an unsubscribe (there is no endpoint to undo it) |
| `GET /api/v1/lists`, `POST /api/v1/lists/{id}/contacts` | `contacts:read`, `contacts:write` | Read lists; add members |
| `GET /api/v1/campaigns`, `GET /api/v1/campaigns/{id}` | `campaigns:read` | Read campaigns and progress |
| `POST /api/v1/campaigns`, `PATCH /api/v1/campaigns/{id}` | `campaigns:write` | Create and edit drafts |
| `POST /api/v1/campaigns/{id}/readiness` | `campaigns:read` | The same readiness report the panel shows |
| `POST /api/v1/campaigns/{id}/launch` | `campaigns:launch` | Requires `confirm_recipient_count` equal to the eligible count |
| `POST /api/v1/campaigns/{id}/pause`, `/resume`, `/cancel` | `campaigns:launch` | Lifecycle controls |
| `GET /api/v1/webhook-endpoints`, `POST /api/v1/webhook-endpoints` | `webhooks:manage` | List and create |
| `PATCH` and `DELETE /api/v1/webhook-endpoints/{id}` | `webhooks:manage` | Update, delete |
| `POST /api/v1/webhook-endpoints/{id}/rotate-secret`, `/test` | `webhooks:manage` | New secret with overlap; send a test event |
| `GET /api/v1/webhook-endpoints/{id}/deliveries`, `POST /api/v1/webhook-deliveries/{id}/redeliver` | `webhooks:manage` | Delivery log; manual redelivery |
| `POST /api/v1/events` | `events:write` | Custom event for automation triggers |

Suppressions are readable in the panel only. Removing a suppression stays an administrator action in the panel, never in the API.

### Sending an email

```json
POST /api/v1/emails
Authorization: Bearer psk_live_…
Idempotency-Key: order-1042-receipt

{
  "from": "billing@acme.test",
  "to": [{"email": "jane@example.com", "name": "Jane Doe"}],
  "reply_to": "support@acme.test",
  "subject": "Your receipt for order 1042",
  "template_id": 12,
  "variables": {"order_number": "1042", "action_url": "https://acme.test/orders/1042"},
  "tags": ["receipt"],
  "metadata": {"order_id": "1042"},
  "purpose": "transactional",
  "scheduled_at": null
}
```

```json
202 Accepted
{
  "data": [{"id": "01JD9…", "to": "jane@example.com", "status": "queued"}],
  "meta": {"request_id": "req_01JD9…", "idempotent_replay": false}
}
```

- Content is `html` and optional `text`, or a `template_id` with `variables`; template purpose must match `purpose`.
- `variables` fill template variables for this send only and are escaped like contact data. Required template variables missing for a recipient reject the request with 422.
- Attachments: base64 with filename and content type; the same type, count and size limits as Compose; total request body at most 10 MB.
- Custom headers are not accepted, which prevents header injection and spoofing.
- `202` means queued, not sent. Status and webhooks report what happens next.

### Responses, errors and pagination

```json
422 Unprocessable Content
{
  "error": {
    "code": "recipient_suppressed",
    "message": "jane@example.com is suppressed (Hard bounce).",
    "fields": {"to.0.email": ["suppressed (Hard bounce)"]},
    "request_id": "req_01JD9…"
  }
}
```

| Status | When |
| --- | --- |
| 200, 201, 202, 204 | Read, created, queued for sending, deleted |
| 400 | Malformed JSON |
| 401 | Missing, invalid, expired or revoked key |
| 403 | Missing scope, sender not allowed for this key, or IP not allowed |
| 404 | Not found, or not visible to this key (never reveals whether it exists) |
| 409 | Idempotency key still in progress, or a state conflict such as launching a running campaign |
| 413 | Body larger than 10 MB |
| 422 | Validation or a business rule (suppressed, no consent, missing variables) |
| 429 | Rate or daily limit reached; `Retry-After` set |
| 500, 503 | Server error or maintenance; safe to retry with the same idempotency key |

Error codes are stable strings, documented in the reference; messages may change. Lists use cursor pagination: `limit` (default 25, maximum 100) and `cursor`, with `meta.next_cursor`. Responses are built with Eloquent API Resources.

Versioning: additive changes ship within v1. A breaking change ships as v2, and v1 gets `Deprecation` and `Sunset` headers at least six months before removal.

### Rate limits

- Per key: `rate_limit_per_minute`, default 60, plus `daily_send_limit`. The response carries `X-RateLimit-Limit`, `X-RateLimit-Remaining` and `Retry-After` on 429.
- Per IP: 20 failed authentications per minute, then 429 for 10 minutes.
- Sends from the API still obey provider quotas, warm-up plans and per-minute provider limits, so a key cannot exceed what the provider allows.

### Idempotency

- `POST` endpoints accept `Idempotency-Key` (1 to 255 characters), stored per API key for 24 hours.
- Same key and same request: the original response is returned with `Idempotent-Replayed: true`, and nothing is sent again.
- Same key with a different body: 422 `idempotency_key_reused`.
- Same key while the first request is still running: 409 `idempotency_in_progress`.
- The key is recorded on the messages it created, so status lookups and support can trace it.
- Duplicate prevention after queuing is unchanged and already built: the atomic per-message claim, and no automatic retry when the outcome is unknown.

## E. Outgoing webhook specification (PROPOSED)

Outgoing webhooks follow the [Standard Webhooks specification](https://github.com/standard-webhooks/standard-webhooks/blob/main/spec/standard-webhooks.md) (decision L7). Receivers can then verify ProSend's signatures with existing open-source libraries instead of custom code. A delivery counts as delivered only when the endpoint answers 2xx; queued or sent is never reported as delivered.

### Events

| Event | Sent when | Source |
| --- | --- | --- |
| `email.accepted` | The provider accepted a message (not proof of inbox delivery) | Brief §4 |
| `email.delivered` | The provider confirmed delivery by webhook | Brief §4 |
| `email.bounced` | A hard or soft bounce was reported; `bounce_type` says which | Brief §4 |
| `email.complained` | The recipient reported spam | Brief §4 |
| `email.failed` | The message could not be sent; `retryable` says whether a retry may help | Brief §4 |
| `email.unsubscribed` | The recipient unsubscribed (link, one-click or provider) | Brief §4 |
| `campaign.completed` | A campaign finished; `final_status` is completed, partially\_failed or failed | Brief §4 |
| `email.delivery_unknown` | The connection broke after hand-over, so the outcome is unknown | PROPOSED |
| `email.suppressed` | A message was skipped because the address is suppressed or lacks consent | PROPOSED |

### Payload and signature

```json
POST https://hooks.customer.test/prosend
webhook-id: evt_01JD9M5Q8Z
webhook-timestamp: 1793030400
webhook-signature: v1,K5oZfzN95Z9UVu1EsfQmfVNQhnkZ2pj9o9NDN/H/pI4=
content-type: application/json

{
  "type": "email.bounced",
  "timestamp": "2026-10-24T09:20:00Z",
  "data": {
    "email_id": "01JD9…",
    "recipient": "jane@example.com",
    "campaign_id": null,
    "bounce_type": "hard",
    "reason": "550 5.1.1 User unknown",
    "tags": ["receipt"],
    "metadata": {"order_id": "1042"}
  }
}
```

- The signature is HMAC-SHA256 over `webhook-id.webhook-timestamp.body`, base64-encoded and prefixed `v1,`, exactly as the specification defines.
- Each endpoint has its own secret: 32 random bytes, base64-encoded with a `whsec_` prefix, stored encrypted and shown once.
- Rotation sends both signatures, space-separated as the specification allows, for 24 hours.
- `webhook-id` is the event's id and is identical on every retry and redelivery, so receivers can deduplicate on it.
- Receivers should reject timestamps more than 5 minutes old, the tolerance used by the official PHP library ([source](https://github.com/standard-webhooks/standard-webhooks/blob/main/libraries/php/src/Webhook.php)).
- Payloads carry ids, status, recipient address, tags and metadata. They never carry email bodies or attachments, and stay under 20 KB as the specification recommends.

### Delivery, retries and logs

- Deliveries run on their own `webhooks` queue, so a slow endpoint never delays email sending.
- Timeout: 15 seconds for the whole request, 5 seconds to connect. Redirects are not followed, and a 3xx counts as a failure, as the specification recommends.
- Retry schedule, from the specification's example: immediately, then 5 seconds, 5 minutes, 30 minutes, 2 hours, 5 hours, 10 hours, 14 hours, 20 hours and 24 hours, about 75 hours in all, with small random jitter.
- Endpoints can lower the attempt count (1 to 10) but not shorten the gaps (decision L8).
- 2xx is success. 410 Gone disables the endpoint at once. 429, 502 and 503 honour `Retry-After` when it is later than the next scheduled attempt. Every other response or error counts as a failure and is retried.
- Every attempt is logged: time, resolved IP, response status, first 2 KB of the response body, duration and error. Logs are kept 30 days.
- An endpoint is disabled automatically when every delivery in the last 72 hours failed and there were at least 20 of them. Administrators get a panel notification and an email.
- Events are created once, in the same transaction as the status change that causes them, so a crash cannot lose or duplicate an event.

### Manual redelivery

- Administrators, or keys with `webhooks:manage`, can redeliver one delivery or all failed deliveries for an endpoint within a time range.
- A redelivery reuses the original `webhook-id`, so a receiver that already processed it can ignore it.
- Redelivery is limited to events from the last 30 days and to 1,000 deliveries per request. Each one asks for confirmation and is audited.

### SSRF protection

Every destination is checked when the endpoint is saved, and again on every attempt, because DNS can change between the two.

- HTTPS only, ports 443 or 8443. No user name or password in the URL, no IP-literal hosts, at most 2,048 characters. Plain HTTP is accepted only when APP\_ENV is local.
- The host is resolved, and every A and AAAA answer is checked. Any address in these ranges is refused:
  - loopback (127.0.0.0/8, ::1), private (10/8, 172.16/12, 192.168/16, fc00::/7)
  - link-local (169.254/16, fe80::/10), which covers the 169.254.169.254 cloud metadata address
  - shared address space (100.64/10), unspecified (0/8, ::), multicast and reserved ranges
  - IPv4-mapped IPv6 forms of all of the above, and the server's own addresses
- The request is pinned to the checked IP address (cURL's resolve option, passed through Laravel's `withOptions`), so a second DNS answer cannot redirect it (DNS rebinding).
- No redirects, a response size cap of 64 KB, and no proxy settings taken from the environment.
- Optional: a network egress firewall that allows the webhook worker out only on 443 and 8443 (section K).

## F. Provider abstraction and fallback (PROPOSED)

Only Resend and Brevo's HTTP API document an idempotency key for sending. Mailjet, SMTP2GO, Amazon SES and every SMTP connection do not, so after an ambiguous failure on those, the only safe move is to wait for evidence, never to resend elsewhere. Fallback is therefore off by default and allowed only when the first provider definitely did not accept the message.

### Verified capabilities

Facts below were checked on each provider's official documentation on 24 October 2026; links are in each cell.

| Provider | Send interface | Idempotent send | Delivery events and their authentication | Status readable by API | Documented limits | Laravel support |
| --- | --- | --- | --- | --- | --- | --- |
| Brevo (BUILT, SMTP today) | SMTP relay, or `POST /v3/smtp/email` returning `messageId` ([docs](https://developers.brevo.com/reference/sendtransacemail)) | HTTP API only: `idempotencyKey` in `headers`; the docs state 30 minutes, a 2021 changelog says 15, and the field casing differs between pages ([docs](https://developers.brevo.com/docs/heterogenous-versions-batch-emails)) | Webhooks with bearer token, basic auth, custom headers or IP allowlist; no payload signature ([docs](https://developers.brevo.com/docs/secured-webhooks)) | Domains and senders (BUILT in Phase 2) | Send endpoint 1,000 requests/s; other endpoints 100/hour ([docs](https://developers.brevo.com/docs/api-limits)) | `symfony/brevo-mailer` via `Mail::extend` ([Laravel 13](https://laravel.com/docs/13.x/mail)); SMTP needs nothing |
| Resend | `POST https://api.resend.com/emails`, up to 50 recipients, returns `id`; SMTP at smtp.resend.com ([docs](https://resend.com/docs/api-reference/emails/send-email)) | `Idempotency-Key` header, kept 24 hours; different payload returns 409 ([docs](https://resend.com/docs/dashboard/emails/idempotency-keys)) | Svix-signed webhooks (`svix-id`, `svix-timestamp`, `svix-signature`) with retries over about 28 hours ([docs](https://resend.com/docs/webhooks/verify-webhooks-requests)) | Domain status and records ([docs](https://resend.com/docs/api-reference/domains/get-domain)) | 10 requests/s per team; free plan 100/day, 3,000/month ([docs](https://resend.com/docs/api-reference/rate-limit)) | Built-in `resend` driver, needs `resend/resend-php` ([Laravel 13](https://laravel.com/docs/13.x/mail)) |
| Mailjet | `POST https://api.mailjet.com/v3.1/send` with basic auth; up to 50 messages per call; per-message status ([docs](https://dev.mailjet.com/email/guides/send-api-v31/)) | None documented; `DeduplicateCampaign` only blocks repeats within one campaign ([docs](https://dev.mailjet.com/docs/email-api/send-api-v31/group-campaign)) | Webhooks secured by basic auth in the URL; no signature; retried every 30 s for 24 h ([docs](https://dev.mailjet.com/docs/email-api/webhooks/webhooks-overview)) | Sender status, SPF and DKIM status ([docs](https://dev.mailjet.com/openapi/openapi-mailjet/sender-addresses-and-domains/get_v3_sender)); messages by `CustomID` ([docs](https://dev.mailjet.com/openapi/openapi-mailjet/messages/get_v3_message)) | Per-key per-second limits, numbers not published ([docs](https://dev.mailjet.com/docs/email-api/email-api-overview/rate-limits)) | No built-in driver; `symfony/mailjet-mailer` exists ([Symfony](https://symfony.com/doc/current/mailer.html)) |
| SMTP2GO | `POST https://api.smtp2go.com/v3/email/send`, returns `email_id`; returns 200 even when sends fail ([docs](https://developers.smtp2go.com/reference/send-standard-email)) | None documented | Webhooks with bearer or basic auth, custom headers or IP allowlist; no signature; 35 retries over 48 h ([docs](https://developers.smtp2go.com/docs/webhooks-overview)) | Domain DKIM and return-path status; single sender status ([docs](https://developers.smtp2go.com/reference/view-sender-domains)) | Free 200/day and 1,000/month; new paid accounts 1,000/day under review ([docs](https://developers.smtp2go.com/docs/rate-limiting)) | No built-in driver or Symfony bridge; SMTP or the HTTP API with Laravel's HTTP client |
| Amazon SES | SES v2 `SendEmail`, returns `MessageId`; may accept without sending ([docs](https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_SendEmail.html)) | None documented | Configuration sets publish to SNS, EventBridge and others; SNS signatures must be verified ([docs](https://docs.aws.amazon.com/sns/latest/dg/sns-verify-signature-of-message.html)) | Identity, DKIM and MAIL FROM status ([docs](https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_GetEmailIdentity.html)); quota and sandbox state ([docs](https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_GetAccount.html)) | Sandbox 200/24 h and 1/s, verified recipients only ([docs](https://docs.aws.amazon.com/ses/latest/dg/request-production-access.html)) | Built-in `ses` driver, needs `aws/aws-sdk-php` (not installed) ([Laravel 13](https://laravel.com/docs/13.x/mail)) |

Open points the documentation does not settle: Brevo's exact idempotency field casing, retention and duplicate status code; Mailjet's success status code and plan quotas; whether Resend idempotency keys are per team or per key. Each is tested in a sandbox before that provider is enabled.

### Abstraction

The existing `MailProviderDriver` interface (BUILT) grows from "build a mailer" into a full contract, so business logic never names a vendor:

- `send(message)` returns one of three results: **accepted** (with the provider's id), **not accepted** (permanent or temporary, with a code), or **ambiguous**.
- `capabilities()` declares the transport (SMTP or HTTP API), idempotent send and its time window, webhook authentication method, and whether domain, sender, quota and message-lookup APIs exist.
- `verifyInboundWebhook(request)` and `parseInboundWebhook(request)` turn each provider's events into ProSend's event types. The current Brevo processor becomes the first implementation.
- Optional: `domainStatus()`, `senderStatus()`, `quota()` and `findMessage()`, used only where the capability is declared.
- Every attempt is stored in a new email\_message\_attempts table (provider, attempt number, outcome, provider message id), so webhooks from any provider still match their message.

HTTP API outcomes are classified like this:

| Result | Classification |
| --- | --- |
| 2xx with a message id | Accepted |
| Connection, DNS or TLS failure before the request was sent | Not accepted, temporary |
| 401, 403 | Not accepted, configuration problem (provider marked failing) |
| 400, 422 validation errors | Not accepted, permanent |
| 429 or a quota error | Not accepted, temporary; wait for the provider's reset time |
| 5xx, or a timeout after the request was sent | Ambiguous; may retry the same provider with the same idempotency key only within its window |
| SES `SendingPausedException`, `AccountSuspendedException`, `MessageRejected` | Not accepted, needs a person; never falls back |

### Fallback rules

&#91;embedded content: fallback decision · one send attempt, three possible answers\]

Only the highlighted path moves a message to another provider; every other answer either waits for evidence or stops.

- Fallback is configured per sender identity as an ordered list of provider routes. The identity must be verified separately at each provider on the list.
- A message moves to the next route only when the current one was **not accepted for an availability reason**: connection failure, provider outage, authentication failure, or the provider disabled in ProSend.
- Quota overflow (the first provider's daily limit reached) is a separate opt-in (decision L10).
- A message never moves when:
  - the outcome is ambiguous, or the message is already accepted or unknown;
  - the recipient was rejected (5.1.x), since another provider would be rejected too;
  - the content was rejected;
  - the provider suspended or paused the account, or blocked it for policy (5.7.x). Routing around enforcement would bypass the provider's restrictions, which Spec §7 forbids.
- Each fallback is recorded as an attempt and an event, appears in Email activity, and counts against the fallback provider's quota and warm-up plan.

### Reconciling ambiguous outcomes

1. If the provider supports idempotent sends, the job retries **the same provider** with the message's id as the key, inside the provider's window. The provider deduplicates it.
2. Otherwise, if the provider can look messages up (Mailjet by `CustomID`), a reconciliation job asks after 10 minutes and records what it finds.
3. Otherwise ProSend waits up to 72 hours for a webhook event carrying the stored Message-ID or correlation header.
4. Still nothing: the message stays "Status unknown" and only a person can retry it, with the duplicate warning that exists today (BUILT).

## G. Automation and import approval workflows (PROPOSED)

Both workflows are bounded on purpose. An automation can only use triggers and steps from a fixed list and can never trigger itself. An import cannot touch contacts until a second person approves exactly what was previewed.

### Automation workflows

**Triggers** (approved list only):

| Trigger | Fires when |
| --- | --- |
| Contact created | A contact is added by the panel, API or an approved import |
| Added to list / tag added | A contact joins a list or gains a tag |
| Consent granted | A contact's consent becomes Granted |
| Email event | A message from ProSend is delivered, bounces, or is clicked (opens are excluded as unreliable) |
| Custom event | An application posts an event to `POST /api/v1/events` whose name is registered in the panel |
| Schedule | A daily or weekly time, or a date attribute such as `renewal_date` minus N days |

**Steps**: send email (a template is required), wait (a duration, or until a sending window), condition (segment-style rules on the contact, or whether an earlier email was delivered or clicked), add or remove a tag or list, set a custom attribute, end. Calling external URLs is not a step; outgoing webhooks cover that.

**Limits that cannot be switched off:**

- At most 20 steps per workflow and 90 days of total waiting.
- One run per contact per trigger event, enforced by a unique key on (workflow, trigger event, contact).
- Re-entry policy per workflow: never, or after N days.
- Frequency caps: at most 3 automated emails per contact per day across all workflows by default, and a daily send cap per workflow.
- Loop prevention:
  - A step's effect never fires its own workflow.
  - Actions record the run that caused them; chains stop at depth 3.
  - Activation rejects any set of workflows whose actions and triggers form a cycle.
- Every send goes through the existing pipeline: suppression, consent for marketing, sender verification, warm-up plans and provider limits.

**Lifecycle**: Draft, then Active, Paused or Archived. Editing an active workflow creates a new version; runs already in progress finish on the version they started. Activating a workflow that sends marketing email needs an administrator (decision L13).

**Execution and failure handling**:

- Each run records its trigger, current step, next run time and status (running, waiting, completed, exited, failed).
- Every step attempt is logged with its outcome.
- Due runs are picked up by a scheduled sweep that takes a row lock, so two workers never advance the same run.
- A failed step retries 3 times with backoff, then the run fails and appears in the panel.
- A send that is suppressed or blocked ends that run for that contact as exited, never as failed.

**Acceptance**:

- Firing the same trigger event twice creates one run.
- Two workflows that would trigger each other cannot both be active.
- A contact who unsubscribes mid-run receives no further marketing step.

### Contact import with preview and two-person approval

The two-person rule is new (decision L11): no source defines it today. Template SG-INV-1.2 cannot be preserved until you provide it (decision L12). The design supports named import templates, so SG-INV-1.2 becomes one fixed entry once its columns and rules are known.

&#91;embedded content: import approval · three parties, one gate\]

The numbered steps below give the detail of each box.

1. **Upload**: a Sender uploads a CSV, stored privately. The file's SHA-256 is recorded. Limits: 50 MB and 500,000 rows.
2. **Check structure** (background job): UTF-8 or UTF-16 encoding, delimiter, header row, row count; a file that fails is rejected with the reason.
3. **Map columns**: automatic guesses, then the submitter confirms, or a saved template such as SG-INV-1.2 fixes the mapping.
4. **Analyse every row** (background job):
   - Classify each row as new, update, unchanged, skipped (suppressed, unsubscribed, or a consent change an import may not make) or rejected (invalid address, missing required field, bad value).
   - Duplicates inside the file keep the first occurrence and list the rest as rejected.
5. **Preview**: counts per class, sample rows of each, and a download of rejected rows with reasons.
6. **Submit for approval**: the submitter confirms the lawful basis and submits. The approval is tied to the file hash, the mapping and the analysed counts.
7. **Approve or reject**: a different user with the import approver permission reviews the same preview and approves or rejects it with a note.
   - The server refuses approval by the submitter, regardless of role.
   - An approval expires after 7 days.
   - Changing the file or mapping cancels it.
8. **Process** (background job, in chunks): each row is checked again against the current suppression list and contacts; anything that changed since the preview is skipped and reported.
9. **Report**: final counts, who submitted and who approved, timings, and rejected-row download. Every step is in the audit log.

The Filament "Import CSV" action on the Contacts page is replaced by this flow (decision L19), so the approval rule cannot be bypassed. "Paste email addresses" is also subject to approval above a small threshold, proposed as 25 addresses.

**Acceptance**:

- An import never changes a contact before approval.
- The submitter can never approve their own import.
- An expired or altered approval cannot be used.
- The final report matches the preview, except rows that changed in between, which are listed.

## H. Database impact assessment (PROPOSED)

Phase 3 adds 20 tables and up to eleven nullable columns, and changes or drops nothing that exists. Every migration is additive, reversible on an empty table, and runs on the live database without a reset or a data rewrite. The one backfill copies each sender identity's current provider into the new routes table and leaves the original column in place.

### New tables

| Table | Milestone | Holds | Key constraints | Retention |
| --- | --- | --- | --- | --- |
| api\_keys | M1 | Name, key id, secret hash, scopes, allowed senders, limits, IP allowlist, expiry, revocation, rotation link, creator | Unique key id | Kept (revoked keys kept for audit) |
| api\_key\_daily\_usage | M1 | Requests, sends, 4xx and 5xx per key per day | Unique (key, date) | 13 months |
| api\_request\_logs | M1 | Method, path, status, duration, key, IP, request id; never headers or bodies | Index (key, created\_at) | 30 days |
| idempotency\_keys | M1 | Key, request hash, stored response, created resources, lock | Unique (api key, key) | 24 hours |
| email\_message\_attempts | M1 | Provider, attempt number, outcome, code, provider message id, timings | Index (message), unique provider message id | 13 months |
| sender\_identity\_providers | M5 | Identity per provider, verification, priority | Unique (identity, provider) | Kept |
| webhook\_endpoints | M2 | URL, events, encrypted secret, status, failure counters, limits, creator or key | none extra | Kept |
| webhook\_events | M2 | Event id, type, payload, occurred time | Unique event id | 30 days |
| webhook\_deliveries | M2 | Endpoint, event, status, attempts, next attempt, last result | Unique (endpoint, event) | 30 days |
| webhook\_delivery\_attempts | M2 | Time, resolved IP, status, first 2 KB of body, duration, error | Index (delivery) | 30 days |
| automation\_workflows | M4 | Name, status, current version, limits, creator, activator | none extra | Kept |
| automation\_workflow\_versions | M4 | Trigger and steps as stored definition | Unique (workflow, version) | Kept |
| automation\_event\_types | M4 | Registered custom event names | Unique name | Kept |
| automation\_events | M4 | Incoming trigger events (custom and internal) | Unique (source, external id) | 90 days |
| automation\_runs | M4 | Workflow version, contact, trigger event, status, step, next run, causing run, depth | Unique (workflow, trigger event, contact) | 13 months |
| automation\_run\_steps | M4 | Each step attempt and its outcome | Index (run) | 13 months |
| import\_batches | M3 | File path and hash, mapping, template, counts, status, submitter, approver, decision note, expiry | none extra | 13 months; files deleted after 30 days |
| import\_rows | M3 | Row number, raw values, classification, errors, matched contact | Index (batch, classification) | 30 days |
| import\_templates | M3 | Named column mappings and rules (SG-INV-1.2 once supplied) | Unique name and version | Kept |
| operational\_settings | M6 | Application Settings page values (not secrets) | Unique key | Kept |

### New nullable columns on existing tables

| Table | Columns | Why |
| --- | --- | --- |
| email\_messages | `source`, `api_key_id`, `automation_run_id`, `idempotency_key`, `tags`, `metadata` | Trace API and automation sends; filter by tag; return metadata in webhooks |
| campaigns | `api_key_id` | Campaigns created through the API |
| contacts | `erased_at` | Only if erasure (B §19) is approved |
| users | `can_approve_imports` | Only if the approver permission (decision L11) is a per-user flag. Multi-factor sign-in (decision L17) adds \`app\_authentication\_secret\` and \`app\_authentication\_recovery\_codes\` |

Indexes added: email\_messages (api\_key\_id, created\_at), email\_messages (source, created\_at), and email\_messages (idempotency\_key).

### Volume and growth

- The largest new tables are webhook\_delivery\_attempts and api\_request\_logs, at one row per attempt and per request. Both are pruned after 30 days by a nightly batched job.
- import\_rows can hold 500,000 rows for one large file; rows are deleted 30 days after the import finishes.
- No existing table gets a NOT NULL column, a type change or a dropped column, so no migration rewrites existing data or locks a large table for long.

## I. Security threat model

The highest risk Phase 3 adds is a leaked API key used to send mass email under your domain. It is contained by per-key sender lists, rate and daily caps, a separate marketing scope, and instant revocation. Every threat below has at least one automated test (section J).

| Threat | What is at risk | Mitigation | Test |
| --- | --- | --- | --- |
| API key leaked in logs, URLs or screenshots | Sending reputation, contact data | Header-only keys, hash at rest, shown once, masked prefix, log filter for the key pattern | Key in query string returns 401; logs contain no key |
| API key guessed or brute-forced | Same | 238-bit secrets, constant-time comparison, per-IP throttle on failed authentication | Repeated bad keys reach 429 |
| Compromised key sends spam | Domain and provider account | Allowed sender identities, per-key caps, marketing scope, provider quotas and warm-up still apply, instant revocation, usage alerts | Over-cap send refused; revoked key refused on the next request |
| Scope escalation | All API resources | Scope check on every route plus model policies; keys cannot create keys; 404 instead of 403 for records a key cannot see | Every endpoint without its scope returns 403 |
| Sender spoofing | Recipients' trust, domain | From must be a verified identity allowed for the key; no custom headers | Unlisted From returns 403 |
| Duplicate sends after retries | Recipients, reputation | Idempotency keys, atomic per-message claim (BUILT), no fallback on ambiguity, unique automation runs | Same idempotency key sends once; ambiguous failure never falls back |
| SSRF through webhook URLs | Internal services, cloud credentials | HTTPS-only allowlist of ports, DNS resolution with private and metadata ranges refused, IP pinning, no redirects, checked again per attempt | Private, loopback, metadata and rebinding cases refused |
| Forged ProSend webhooks at customer endpoints | Customer systems | Standard Webhooks HMAC signatures, timestamps, per-endpoint secrets, rotation | Signature verifies with the reference algorithm; a changed body fails |
| Forged provider webhooks to ProSend | Message status, suppression list | Per-provider verification: Brevo token (BUILT), Resend Svix signature, SES SNS signature, basic auth or bearer for Mailjet and SMTP2GO, plus fingerprints (BUILT) | Bad token or signature returns 404 or 401; replay stored once |
| Automation loop or runaway sending | Recipients, quotas | No self-trigger, depth limit 3, cycle check at activation, per-contact and per-workflow caps | Cyclic workflows cannot both be active |
| Importing a purchased or scraped list | Legal exposure, reputation | Lawful-basis confirmation, two-person approval, preview, suppression check at processing, audit trail | Self-approval refused; suppressed rows never imported |
| Spreadsheet formula injection in exports and rejected-row files | Staff who open the files | Values starting with =, +, -, @ are neutralised | Export cells are prefixed |
| Oversized or malicious payloads | Availability | 10 MB body limit, attachment type and size limits, recipient limits, rate limits | 413 and 422 cases |
| Template or content injection | Recipients | No user-controlled Blade or PHP; HTML sanitised; variables escaped (BUILT) | Script and javascript: links removed |
| Secrets exposed from the database | Provider accounts, webhook receivers | Encrypted casts for provider keys and webhook secrets; documented APP\_KEY handling | Raw columns contain no plaintext |
| An insider approves their own import or key misuse | Data quality, reputation | Server-side separation of duties, audit log for keys, approvals, redeliveries and settings | Every sensitive action writes an audit entry |

Accepted residual risks: two colluding users can still approve an import, and a key holder can send whatever its scopes allow until it is revoked.

## J. Test plan and acceptance criteria

Every area in Brief §8 gets feature tests in Pest before its milestone closes. The 253 existing tests keep passing unchanged. No test reaches a real provider, DNS server or URL: the existing fake provider driver and fake DNS resolver are reused, `Http::preventStrayRequests()` guards every HTTP test, and time is frozen where dates matter.

| Brief §8 area | Cases tested | Milestone |
| --- | --- | --- |
| Unauthorized API access | No key, malformed key, unknown key, key in query string, expired key, IP outside allowlist: each 401 or 403, and nothing sent | M1 |
| API key revocation | Revoked key refused on the next request; rotated key valid until grace ends, then refused | M1 |
| Permission enforcement | Each endpoint without its scope returns 403; another key's emails return 404; panel role matrix for the new pages | M1 to M6 |
| Rate limiting | Per-minute limit returns 429 with `Retry-After`; daily send cap; failed-authentication throttle per IP | M1 |
| Idempotency | Same key and body replays without sending; different body returns 422; concurrent request returns 409; expiry after 24 hours | M1 |
| Duplicate-send prevention | Parallel jobs for one message send once; provider retry with the same idempotency key; automation trigger replay creates one run | M1, M4, M5 |
| Webhook signature verification | Signature matches the Standard Webhooks reference vectors; changed body, wrong secret and old timestamp fail; dual signatures during rotation | M2 |
| Webhook retry behaviour | Schedule followed; 2xx stops retries; 410 disables; `Retry-After` honoured; 3xx counts as failure; auto-disable threshold; redelivery keeps the event id | M2 |
| SSRF protection | Loopback, private, link-local, metadata, CGNAT, IPv6 and IPv4-mapped addresses, IP literals, userinfo, bad ports and DNS rebinding between check and connect: all refused | M2 |
| Automation loop prevention | Self-trigger ignored; depth limit stops chains; activation refuses cyclic workflows; frequency caps hold | M4 |
| Suppression enforcement | API, automation and import paths all refuse suppressed and non-consenting recipients the same way the panel does | M1, M3, M4 |
| Provider failures | Each provider's documented error classes map to accepted, not accepted or ambiguous; quota and suspension errors never fall back | M5 |
| Ambiguous send outcomes | Timeout after hand-over never falls back; idempotent providers retry the same provider only; reconciliation updates status from a webhook | M1, M5 |
| Import approval separation | Submitter cannot approve; approval by an unpermitted user refused; expired or altered approval refused; nothing changes before approval | M3 |
| Import validation | Encoding, delimiter, header, duplicate rows, invalid emails, bad consent values, suppressed rows, SG-INV-1.2 mapping once supplied | M3 |
| Audit logging | Key created, rotated and revoked; webhook endpoint changes and redeliveries; import submitted, approved and rejected; workflow activated; settings changed. Entries never contain secrets | All |

### Milestone acceptance criteria

- [ ] **M1**: an external client sends a templated email with an idempotency key, retries it, and receives exactly one email; status shows each lifecycle step; all M1 tests pass.
- [ ] **M2**: a test endpoint receives signed events for accept, deliver, bounce and complaint; failed deliveries retry on schedule; private destinations are refused.
- [ ] **M3**: a CSV goes through preview, a second person's approval and processing; the report matches the preview; self-approval is impossible.
- [ ] **M4**: a welcome workflow sends once per new contact, respects suppression and caps, and a looping configuration cannot be activated.
- [ ] **M5**: the approved provider sends through the same pipeline; a forced outage falls back; a forced ambiguous failure does not.
- [ ] **M6**: the operations pages show live queue, failed-job and health data; the API reference matches the routes.
- [ ] **Every milestone**: Pint clean, full suite green, independent code review findings resolved, your sign-off.

## K. External dependencies and deployment

Production email readiness is **not yet verified**: no email has been confirmed delivered from a verified sender on an authenticated domain. The Phase 2 tasks below must be completed and checked before anyone relies on ProSend for real sending, and they are independent of Phase 3.

### Outstanding Phase 2 operational tasks

- [ ] Add the Laravel scheduler to the deployment environment: `* * * * * cd /path/to/prosend && php artisan schedule:run`. Confirm with `php artisan schedule:list` and a domain check timestamp the next morning.
- [ ] Create a Brevo API key, separate from the SMTP key, enter it on the provider's edit page, and confirm **Check with Brevo** returns a result.
- [ ] Add your sending domain under Domain authentication, publish the records Brevo shows, and wait until the page shows Ready. A Gmail address cannot be authenticated, so this needs a domain you control.
- [ ] Confirm the real sender identity is active in Brevo and verified in ProSend.
- [ ] Run the queue worker under a process supervisor, restarting on deploy, with `DB_QUEUE_RETRY_AFTER=660`.
- [ ] Send a test email to an address you own and confirm it shows Delivered, which requires the Brevo webhook. Check the inbox placement by hand.

### Phase 3 external services

| Dependency | Needed for | New package | Status |
| --- | --- | --- | --- |
| Brevo SMTP key (existing) | All sending today | None | Configured |
| Brevo API key | Domain and sender checks; optional idempotent HTTP sending | None | Not yet entered |
| Brevo webhook to ProSend | Delivery, bounce and complaint events | None | Not yet configured |
| Resend account | Only if approved (L9) | `resend/resend-php`, or none using Laravel's HTTP client | Not started |
| Amazon SES account, SNS topic | Only if approved (L9) | `aws/aws-sdk-php` (not installed) | Not started |
| Mailjet or SMTP2GO account | Only if approved (L9) | None (HTTP client or SMTP) | Not started |
| Customer webhook endpoints | Outgoing webhooks | None | Supplied by customers |

Milestones M1, M2, M3, M4 and M6 need no new package.

### Deployment requirements

- A public HTTPS address in `APP_URL` for the API, unsubscribe links and incoming provider webhooks.
- Queue workers on separate queues so one cannot starve another:
  - `default` (email sending)
  - `webhooks` (outgoing deliveries)
  - `automation`
  - `imports`
- New scheduled tasks: automation sweep every minute, ambiguous-outcome reconciliation every 10 minutes, and nightly pruning of logs, idempotency keys and import files.
- A cache store with atomic locks for rate limits and job overlap protection. The database store works; Redis is recommended once API traffic grows (decision L15).
- Outbound network: the webhook worker needs HTTPS egress. An egress firewall rule blocking private ranges adds a second layer behind the application check.
- Backups of the database, and of `APP_KEY` held separately: encrypted provider keys and webhook secrets cannot be read without it.
- Monitoring: alerts on failed jobs, webhook endpoints disabled automatically, and providers marked failing. Log shipping keeps the API key filter in place.

## L. Decisions that require your approval

You approved every recommendation on 24 October 2026, and M1 is under way. L12 still needs the SG-INV-1.2 template from you before M3.

| # | Decision | Recommendation | Blocks | Your decision |
| --- | --- | --- | --- | --- |
| L1 | Adopt the proposed continuation in section B as requirements | Approve, adjusting any section by comment | M1 | Approved |
| L2 | API authentication: ProSend hashed API keys, or Laravel Sanctum (a new package) | ProSend keys: no package, and per-key senders, caps, IP lists and rotation grace built in | M1 | Approved |
| L3 | Scope: one installation with keys owned by it, or multi-tenant workspaces now | One installation now; workspaces only when commercialisation is decided, because they touch every table | M1 | Approved |
| L4 | Error format: ProSend's JSON envelope, or RFC 9457 problem details | ProSend envelope, as specified in section D | M1 | Approved |
| L5 | Marketing email through the API | Allowed only with the `emails:send_marketing` scope and the existing consent and unsubscribe rules | M1 | Approved |
| L6 | Test keys (`psk_test_…`) that validate and record requests but never send | Include them, so integrations can be built safely | M1 | Approved |
| L7 | Outgoing webhook signing | Standard Webhooks HMAC-SHA256, as in section E | M2 | Approved |
| L8 | Webhook retries and auto-disable | The schedule in section E (about 75 hours); disable after 72 hours of failures with at least 20 deliveries | M2 | Approved |
| L9 | Which additional provider first, if any | Resend first: documented idempotent sends and signed webhooks. Amazon SES only if needed, since it adds `aws/aws-sdk-php` | M5 | Approved |
| L10 | Fallback policy | Off by default; per sender identity; availability failures only; quota overflow a separate opt-in | M5 | Approved |
| L11 | Two-person import approval (new rule) | Adopt: a per-user import approver permission granted by administrators; approvals expire after 7 days; no override; pasted lists above 25 addresses also need approval | M3 | Approved |
| L12 | Import template SG-INV-1.2 | Please supply its columns, order, required fields, validation rules and consent meaning, or confirm it should be dropped. Still outstanding: M3 cannot include it until it is supplied | M3 | Approved |
| L13 | Who activates automations that send marketing email | Administrators only; default cap of 3 automated emails per contact per day | M4 | Approved |
| L14 | Campaign "Validated" state from Spec §11 | Add it in M6, storing the readiness report at validation time | M6 | Approved |
| L15 | Cache store for API rate limits and locks | Database store now; Redis before the API is opened to production traffic | M1 | Approved |
| L16 | Retention periods | Messages and events 13 months, bodies 90 days, request and delivery logs 30 days, audit logs 24 months | M1 | Approved |
| L17 | Multi-factor sign-in for administrators | Require it, using Filament 5's built-in authenticator-app support ([docs](https://github.com/filamentphp/filament/blob/5.x/docs/07-users/02-multi-factor-authentication.md)); adds two nullable user columns | M6 | Approved |
| L18 | Send through Brevo's HTTP API instead of SMTP, for its idempotency key | Test it in a sandbox during M5; switch only if the documented behaviour holds | M5 | Approved |
| L19 | Replace the Contacts "Import CSV" action with the approval flow | Replace it; otherwise the approval rule can be bypassed | M3 | Approved |
| L20 | Contact erasure on request (Spec continuation §19) | Include in M3, with a hashed suppression so an erased address is never re-imported | M3 | Approved |
| L21 | Keep a copy of this specification in the repository, such as `docs/specs/phase-3.md` | Yes, so future sessions can recover it; this adds a new top-level folder, which needs your approval | None | Approved |

## Sources

Official documentation opened while preparing this specification, 24 October 2026:

- Laravel 13: [Mail](https://laravel.com/docs/13.x/mail), [Sanctum](https://github.com/laravel/docs/blob/13.x/sanctum.md), [Rate limiting](https://github.com/laravel/docs/blob/13.x/routing.md), [HTTP client](https://github.com/laravel/docs/blob/13.x/http-client.md)
- Filament 5: [Multi-factor authentication](https://github.com/filamentphp/filament/blob/5.x/docs/07-users/02-multi-factor-authentication.md)
- Standard Webhooks: [Specification](https://github.com/standard-webhooks/standard-webhooks/blob/main/spec/standard-webhooks.md), [PHP reference library](https://github.com/standard-webhooks/standard-webhooks/blob/main/libraries/php/src/Webhook.php)
- Brevo: [Send transactional email](https://developers.brevo.com/reference/sendtransacemail), [Idempotency in batch sending](https://developers.brevo.com/docs/heterogenous-versions-batch-emails), [Transactional webhooks](https://developers.brevo.com/docs/transactional-webhooks), [Secured webhooks](https://developers.brevo.com/docs/secured-webhooks), [API limits](https://developers.brevo.com/docs/api-limits), [Domain configuration](https://developers.brevo.com/reference/get-domain-configuration), [Senders](https://developers.brevo.com/reference/get-senders)
- Resend: [Send email](https://resend.com/docs/api-reference/emails/send-email), [Idempotency keys](https://resend.com/docs/dashboard/emails/idempotency-keys), [Rate limit](https://resend.com/docs/api-reference/rate-limit), [Verify webhooks](https://resend.com/docs/webhooks/verify-webhooks-requests), [Retries and replays](https://resend.com/docs/webhooks/retries-and-replays), [Domains](https://resend.com/docs/api-reference/domains/get-domain)
- Mailjet: [Send API v3.1](https://dev.mailjet.com/email/guides/send-api-v31/), [Send API errors](https://dev.mailjet.com/docs/email-api/send-api-v31/send-api-errors), [Webhooks](https://dev.mailjet.com/docs/email-api/webhooks/webhooks-overview), [Rate limits](https://dev.mailjet.com/docs/email-api/email-api-overview/rate-limits), [Sender validation](https://dev.mailjet.com/docs/email-api/senders-domains/sender-validation)
- SMTP2GO: [Send email](https://developers.smtp2go.com/reference/send-standard-email), [Webhooks](https://developers.smtp2go.com/docs/webhooks-overview), [Rate limiting](https://developers.smtp2go.com/docs/rate-limiting), [Sender domains](https://developers.smtp2go.com/reference/view-sender-domains)
- Amazon SES: [SendEmail](https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_SendEmail.html), [Quota errors](https://docs.aws.amazon.com/ses/latest/dg/manage-sending-quotas-errors.html), [Production access](https://docs.aws.amazon.com/ses/latest/dg/request-production-access.html), [GetEmailIdentity](https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_GetEmailIdentity.html), [Event publishing](https://docs.aws.amazon.com/ses/latest/dg/monitor-using-event-publishing.html), [Verifying SNS signatures](https://docs.aws.amazon.com/sns/latest/dg/sns-verify-signature-of-message.html)

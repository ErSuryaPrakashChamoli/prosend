# ProSend vs Woodpecker.co: audit, gap report and roadmap

23 November 2026. Covers steps 1–5 of the "Woodpecker.co-style sales outreach platform" brief: audit of the existing project, feature comparison, missing functionality, roadmap, reusable modules and required database changes. Progress against the roadmap is tracked in [checklist.md](checklist.md); the final implementation report is [report.md](report.md).

## 1. Platform as found

| Item | Finding |
| --- | --- |
| PHP / Laravel / Filament | 8.5.4 / 13.38.0 / 5.10.3, one panel (`admin`) |
| Tests | Pest 5.3.3, **542 tests passing** on in-memory SQLite (`tests/TestCase.php` refuses any other database) |
| Database | MySQL `db_email` locally; 37 migrations |
| Queue, cache, sessions | `database` driver (Redis deliberately not used, decision D5) |
| Scheduler | `prosend:check-domains` 03:00, `prosend:evaluate-warmups` 00:05, `prosend:prune` 02:30 |
| Mail providers | Brevo (SMTP + API), Mailtrap live and sandbox, generic SMTP (now `mails.fundingfolks.in`) |
| API | `/api/v1` with ProSend's own hashed API keys (decision L2, not Sanctum): send email, list/read email and events, `me` |
| Inbound webhooks | Brevo delivery events (token in URL, idempotent fingerprints) |
| PHP extensions | No `imap`; IMAP needs `webklex/php-imap` (approved 23 Nov 2026) |
| PDF | No PDF library; dompdf **not** approved, so PDF export is a print-ready page |

Architecture: actions (`app/Actions`), services (`app/Services`), jobs, policies per model, Filament resources split into Schemas/Tables/Pages. Sending is always queued: `LaunchCampaign → PrepareCampaignMessages → CampaignDispatcher → SendEmailMessage → EmailSender → driver`. Each message row is claimed atomically before the provider is contacted and its Message-ID is stored first, so retries and duplicate jobs cannot double-send.

## 2. Feature comparison matrix

Statuses: **COMPLETE** (implemented and tested), **PARTIAL**, **MISSING**, **PLACEHOLDER** (UI without logic), **BROKEN**, **NEEDS IMPROVEMENT** (works, not production-grade). No PLACEHOLDER or BROKEN features were found: every screen in the panel is backed by tested logic.

### 2.1 Dashboard & analytics

| Feature | Status | Evidence / gap |
| --- | --- | --- |
| Campaign statistics, active campaigns, total prospects | COMPLETE | `CampaignPerformanceTable`, `AudienceOverview`, `OperationsOverview` |
| Emails sent / delivered, bounce, unsubscribe, complaint rates | COMPLETE | `DeliveryStatsOverview`, `EmailMetrics` (database aggregates; delivered only from webhooks) |
| Date-range and campaign filters | COMPLETE | Dashboard filters: date, type, provider, campaign, sending domain |
| Open and click rate | PARTIAL | Only from Brevo webhooks. No own open pixel or click tracking, so SMTP mailboxes report nothing |
| Reply rate, positive reply rate | MISSING | No inbound mail, so replies are unknown |
| Campaign conversion rate | MISSING | No conversion outcome is recorded |
| Team performance, user-level analytics | MISSING | |
| Email account health | PARTIAL | Provider health and connection checks exist; no per-mailbox sending/bounce health view |
| Deliverability monitoring | PARTIAL | Bounce/complaint trend charts, volume by domain; no domain health score |

### 2.2 Prospect / lead management

| Feature | Status | Evidence / gap |
| --- | --- | --- |
| Manual add, edit, delete | COMPLETE | `ContactResource` |
| CSV and Excel import, bulk import, error report | COMPLETE | `ContactImporter`, `SpreadsheetImportAction` (xlsx), failed-row CSV |
| Export | COMPLETE | `ContactExporter` (CSV; formula-injection protected) |
| Company / contact info | COMPLETE | company, job title, industry, website, phone |
| Email validation, duplicate detection | COMPLETE | `email:rfc`, unique normalised email, import updates existing |
| Custom fields | PARTIAL | Free-form `custom_attributes` JSON; no defined field list |
| Tags, segmentation, dynamic filters | COMPLETE | tags, `SegmentResource` + `SegmentQuery` rules, table filters |
| Saved filters | PARTIAL | Segments act as saved filters; table filters persist per session only |
| Custom lists | COMPLETE | `ContactListResource` |
| Bulk actions | COMPLETE | add to list, stage, owner, delete, do-not-contact |
| Suppression list, unsubscribe management | COMPLETE | `SuppressionResource`, one-click unsubscribe |
| Activity timeline | PARTIAL | Emails relation manager only; no single timeline of emails, replies, stage changes and notes |
| Time zone per prospect | MISSING | Needed for time-zone-based sending |

### 2.3 Email accounts (mailboxes)

| Feature | Status | Evidence / gap |
| --- | --- | --- |
| SMTP integration, multiple accounts | COMPLETE | `SmtpProviderResource` + `SenderIdentityResource`; encrypted credentials; SSRF-guarded hosts |
| Sending limits | COMPLETE | provider daily/monthly/per-minute limits, warm-up plans, recipient-domain limits |
| Connection monitoring, failure detection, error logs | COMPLETE | health status, last error, send-attempts relation manager |
| Disconnect (disable) | COMPLETE | `is_enabled` |
| IMAP | MISSING | No inbound mail at all |
| Gmail / Outlook OAuth | MISSING | |
| Daily sending schedules, time zone | MISSING | Warm-up plans have sending hours; campaigns do not |
| Mailbox health view | PARTIAL | See 2.1 |

### 2.4 Campaign management

| Feature | Status | Evidence / gap |
| --- | --- | --- |
| Create, edit (draft), duplicate, delete (draft) | COMPLETE | `CampaignResource` |
| Pause, resume, stop (cancel) | COMPLETE | `PauseCampaign`, `ResumeCampaign`, `CancelCampaign` (audited) |
| Archive | MISSING | |
| Scheduling, status management | COMPLETE | Draft → Pending approval → Scheduled/Queued → Sending → Completed / Partially failed / Failed / Cancelled |
| Assign prospects | COMPLETE | lists, segment, selected contacts, all contacts |
| Personalisation, custom variables, templates | COMPLETE | merge tags, custom attributes, required variables, versioned templates |
| HTML and plain-text editors, preview, test email | COMPLETE | rich editor, optional text part, `EmailPreviewer`, `SendTestEmail` |
| Validation before activation | COMPLETE | `CampaignReadiness` + approval (D1) |
| Multiple email steps (sequences) | MISSING | A campaign is one email |

### 2.5 Sequence automation

| Feature | Status |
| --- | --- |
| Multi-step sequences with delays | MISSING |
| Follow-up only if no reply / conditional follow-up | MISSING |
| Stop on reply | MISSING (needs inbound mail) |
| Stop on unsubscribe / bounce | PARTIAL: each send re-checks suppression and unsubscribe, but there are no later steps to stop |
| Business hours, weekend restrictions, time-zone scheduling | MISSING |
| Automatic retry of temporary failures | COMPLETE (1, 5, 15, 60 minutes) |
| Duplicate-send protection, locking | COMPLETE (atomic claim, `WithoutOverlapping`, unique recipients) |
| Sequence execution logs | MISSING |

### 2.6 Deliverability

| Feature | Status | Evidence / gap |
| --- | --- | --- |
| SPF, DKIM, DMARC checks; configuration guidance | COMPLETE | `DomainAuthenticator`, `SendingDomainResource`, daily re-check |
| Provider-side verification | COMPLETE (Brevo) | `CheckSenderWithProvider` |
| Bounce and complaint monitoring | COMPLETE | Brevo webhooks → suppression; trend chart |
| Bounce detection for SMTP mailboxes | MISSING | Needs a bounce mailbox (IMAP) |
| Sending reputation indicators, domain health score | MISSING | |
| Sending limit monitoring | PARTIAL | quota on campaign readiness; no dashboard view |
| Delivery failure reports | PARTIAL | failure tabs on email activity; no report page |

### 2.7 Warm-up

| Feature | Status | Evidence / gap |
| --- | --- | --- |
| Warm-up configuration, daily limits, gradual ramp, schedules | COMPLETE | `WarmupPlanResource`, `WarmupGate`, `WarmupProgression`, daily evaluation |
| Health metrics, activity reports | PARTIAL | plan history and recommendations; no report page |
| Provider-driven warm-up (automated interactions) | MISSING | Needs an authorised third-party service; extension point `DeliverabilityMonitor` exists. ProSend never simulates engagement |

### 2.8 Unified inbox

MISSING in full: no inbound sync, conversations, reply detection, statuses, assignment, notes, replies or attachments.

### 2.9 A/B testing

MISSING in full: no variants, allocation, comparison or winner selection.

### 2.10 Team management

| Feature | Status | Evidence / gap |
| --- | --- | --- |
| User management | COMPLETE | `UserResource` |
| Roles | PARTIAL | Fixed enum: Administrator, Sender, Viewer. The brief needs seven roles |
| Granular permissions, permission management | MISSING | Policies check three hard-coded role abilities |
| Teams, team leaders | MISSING | |
| Campaign ownership | PARTIAL | `created_by` only |
| Prospect ownership | COMPLETE | `contacts.owner_id` |
| Activity logs | COMPLETE | `AuditLogResource` |

### 2.11 Agency / multi-client

MISSING in full. Every table is installation-wide; `contacts.email`, `suppressions.email`, `sender_identities.email`, `sending_domains.domain`, list and segment names are globally unique. Phase 3 decision L3 had deferred multi-tenancy; this brief asks for it.

### 2.12 Reporting & export

| Feature | Status |
| --- | --- |
| Campaign, delivery, deliverability figures | PARTIAL (dashboard widgets, no report pages) |
| Prospect, reply, team and client reports | MISSING |
| Comparison reports | MISSING |
| CSV export | COMPLETE for contacts, email activity, campaign recipients |
| Excel export | MISSING (openspout is installed) |
| PDF export | MISSING (print-ready page, no library) |

### 2.13 Integrations & API

| Feature | Status | Evidence / gap |
| --- | --- | --- |
| REST API, authentication, rate limiting, idempotency, docs | COMPLETE for sending | `/api/v1`, `docs/api/openapi.yaml` |
| API for prospects, campaigns, inbox | MISSING | |
| Incoming webhooks | PARTIAL | Brevo delivery events only |
| Outgoing webhooks with retries and signing | MISSING (planned M2) |
| CRM, Google Sheets, Zapier | MISSING. Zapier-style integration is possible through the API and outgoing webhooks; named CRMs and Google Sheets need the customer's OAuth apps |
| Integration logs | PARTIAL | API request logs only |

### 2.14 Automation rules

MISSING in full (planned M4).

### 2.15 Global settings & administration

| Feature | Status | Evidence / gap |
| --- | --- | --- |
| SMTP and provider settings | COMPLETE | providers, sender identities |
| API configuration | COMPLETE | API keys |
| Audit logs, data retention | COMPLETE | `AuditLogResource`, `prosend:prune` (configured in `.env`) |
| Users, roles | PARTIAL | see 2.10 |
| Organisation settings, notification settings, campaign defaults, sending restrictions | PARTIAL | in `config/prosend.php` / `.env`, no settings page |
| Webhook configuration | MISSING (no outgoing webhooks) |
| System health, failed-job monitoring | PARTIAL | failed-job count and stalled-queue banner; no page |

## 3. Missing functionality, by weight

1. **Multi-client tenancy** (largest, cross-cutting): every later module must be client-scoped, so it comes first.
2. **Granular roles and permissions, teams.**
3. **Sequences**: steps, delays, conditions, stop rules, sending windows, time zones, execution log.
4. **Inbound mail**: IMAP/Gmail/Graph sync, reply and bounce detection, unified inbox.
5. **Own open and click tracking**, so SMTP mailboxes report engagement.
6. **A/B testing.**
7. **Automation rules, outgoing and incoming webhooks.**
8. **Reports with CSV/Excel/print-PDF export; reply, team and client analytics.**
9. **Gmail and Microsoft 365 OAuth mailboxes.**
10. **API for prospects, campaigns and the inbox.**
11. **Settings, system health and failed-job pages; campaign archive; defined custom fields.**

## 4. Decisions applied

| Topic | Decision |
| --- | --- |
| Tenancy model | One database; a `clients` table; `client_id` on every client-owned table, backfilled to a "Default client"; Filament's tenancy (client switcher, per-client URLs) plus ProSend's own global scope, so queries outside resources are scoped as well. Queue jobs work from records, never from a global "current client" |
| Agency access | Users with the existing Administrator role become **Super Admins**: all clients, client management, roles. Everyone else works through per-client memberships with a role |
| Roles | Stored in a `roles` table with a list of granular permissions; the seven roles in the brief are created as system roles and stay editable |
| Approval (D1) | Kept. "Approve campaigns" becomes a permission (Super Admin, Admin, Team Leader) |
| API auth (L2), queue (D5) | Unchanged: ProSend API keys, database queue. Redis remains recommended before high volume |
| Consent | Unchanged: outreach is marketing email and needs recorded consent. India's DPDP Act 2023 has no "legitimate interest" basis for marketing |
| Mailbox model | A mailbox is a provider (SMTP+IMAP, Gmail or Microsoft 365) with its sender identity; no parallel "email accounts" table |
| Gmail / Microsoft | Hand-written OAuth and HTTP API calls (no package). Needs the user's Google Cloud and Azure app credentials; Gmail's send/read scopes are "restricted" and need Google's verification before public use |

## 5. Roadmap

Each stage ends with its tests passing, the full suite green, an independent review, and a checklist update.

| Stage | Scope |
| --- | --- |
| W1 | Clients, tenancy, roles and permissions, teams, client switching, isolation tests |
| W2 | Sequences: steps, conditions, sending windows, time zones, runner, stop rules, execution log, open/click tracking, campaign archive |
| W3 | Inbound mail: IMAP sync, reply and bounce detection, unified inbox (statuses, assignment, notes, replies, attachments) |
| W4 | A/B variants, allocation, results and winner rules |
| W5 | Automation rules engine, tasks, outgoing webhooks (signed, retried, SSRF-guarded), incoming prospect webhook |
| W6 | Reports and exports (CSV, Excel, print-PDF); dashboard reply/positive/team/client analytics; mailbox health |
| W7 | Gmail and Microsoft 365 OAuth mailboxes (send and sync) |
| W8 | API: prospects, campaigns, enrolment, conversations, webhooks; OpenAPI update |
| W9 | Settings, system health, failed jobs, custom field definitions, deliverability score, warm-up provider interface |
| W10 | Full regression, final review, implementation report |

## 6. Reusable modules

| Module | Reused for |
| --- | --- |
| `SendEmailMessage`, `EmailSender`, drivers | Every sequence step, inbox reply and automation email |
| `RecipientEligibility`, `Suppression` | Stop rules and enrolment checks |
| `CampaignReadiness`, approval actions | Sequence validation before activation |
| `EmailContentRenderer`, `TemplateVariables` | Step and variant rendering |
| `SegmentQuery`, lists | Sequence audiences |
| `SmtpHostPolicy` | SSRF guard pattern for IMAP hosts and outgoing webhooks |
| `AuditLogger` | Client, role, inbox and automation changes |
| `EmailMetrics` | Reports and new dashboard rates |
| ProSend API key stack | New API endpoints, scoped per client |
| `SpreadsheetConverter` / openspout | Excel report export |

## 7. Required database changes

All additive. Existing rows are assigned to the Default client; no column or table is dropped.

| Stage | Tables / columns |
| --- | --- |
| W1 | `clients`, `roles`, `client_user` (role per client), `teams`, `team_user`; `client_id` on contacts, contact_lists, segments, suppressions, campaigns, email_messages, email_templates, smtp_providers, sender_identities, sending_domains, warmup_plans, api_keys, audit_logs; global unique indexes become per-client |
| W2 | `campaign_steps`, `campaign_prospects`; sending-window columns and `archived_at` on campaigns; `campaign_step_id`/`step_number` on email_messages (unique per campaign, contact and step); `timezone` on contacts |
| W3 | IMAP columns on smtp_providers; `conversations`, `conversation_messages`, `conversation_notes` |
| W4 | `campaign_variants`; `campaign_variant_id` on email_messages and campaign_prospects |
| W5 | `automation_rules`, `automation_runs`, `tasks`, `webhook_endpoints`, `webhook_deliveries`, `inbound_webhooks` |
| W7 | OAuth token columns on smtp_providers |
| W9 | `custom_fields`, `settings` |

Migrations are written for MySQL and SQLite and are **not run against `db_email`** by Claude; run `php artisan migrate` when ready.

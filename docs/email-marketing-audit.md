# Email Marketing and Prospect Outreach: Audit

29 October 2026. Audit of the ProSend repository against the "Email Marketing & Prospect Outreach System" brief (Phases 1 to 7 of the brief; the pasted brief stops mid-sentence in Phase 7 at "Authenticate webhook requests", so nothing after that point could be assessed).

ProSend already is an email marketing and sending platform: Phases 1 and 2 and Phase 3 milestone M1 (the sending API) are built and tested. Most of the brief is therefore **already implemented**. This report records where each requirement lives, what is missing, and what was changed in this pass (section 9).

| Status | Meaning |
| --- | --- |
| IMPLEMENTED | Exists and is covered by tests |
| PARTIALLY IMPLEMENTED | Exists, but part of the brief is missing |
| MISSING | Not implemented |
| NEEDS VERIFICATION | Depends on credentials, configuration, or a running service that cannot be checked from the code |

## 1. Platform

| Item | Finding |
| --- | --- |
| PHP | 8.5.4 |
| Laravel | 13.38.0 |
| Filament | 5.10.3, one panel (`admin`, [AdminPanelProvider.php](../app/Providers/Filament/AdminPanelProvider.php)) |
| Tests | Pest 5.3.3; 316 tests passing on in-memory SQLite before this pass |
| Database | MySQL `db_email` locally; SQLite in tests |
| Queue, cache, sessions | `database` driver for all three |
| Mail | Brevo SMTP relay, stored as an encrypted SMTP provider record by `prosend:setup` |
| Other packages | None beyond Filament, Laravel and dev tools. `openspout/openspout` (XLSX) and `league/csv` are installed as Filament dependencies |

Navigation groups: Overview, Email Operations, Audiences, Deliverability, Infrastructure, System. The brief's "Email Marketing" group exists in substance as **Email Operations** (campaigns, templates, compose) and **Audiences** (contacts, lists, segments, suppressions). The groups were not renamed, to avoid churn for current users.

### Hazard found during the audit

`bootstrap/cache/config.php` (created 29 October, 10:53) is still present. While it exists, `php artisan test` ignores `phpunit.xml` and runs `migrate:fresh` against MySQL `db_email`. This already emptied the local database once. Run `php artisan config:clear` before running tests, or run them as `composer test`, which clears it first. The tests in this pass were run with the cache bypassed (`APP_CONFIG_CACHE` pointed at a missing file) and an explicit in-memory SQLite connection.

## 2. Authentication, authorisation and audit

| Feature | Status | Where | Notes |
| --- | --- | --- | --- |
| Panel login | IMPLEMENTED | Filament login | No public registration |
| Roles | IMPLEMENTED | `users.role`, [UserRole.php](../app/Enums/UserRole.php) | Administrator, Sender, Viewer |
| Policies | IMPLEMENTED | [app/Policies](../app/Policies) | One per model; navigation hides what a role cannot use |
| Audit log | IMPLEMENTED | [AuditLogger.php](../app/Services/AuditLogger.php), System > Audit logs | Launches, pauses, cancellations, retries, suppressions, role and provider changes, API keys |
| Multi-factor sign-in | MISSING | Planned: Phase 3 M6 (decision L17) | |
| Application settings page | MISSING | Planned: Phase 3 M6 | Settings live in [config/prosend.php](../config/prosend.php) and `.env` |

## 3. Phase 2 of the brief: Email Marketing module

### 3.1 Dashboard: PARTIALLY IMPLEMENTED

Existing: [Dashboard.php](../app/Filament/Pages/Dashboard.php) with nine widgets, filterable by date, email type, provider, campaign and sending domain. All counts are database aggregates ([EmailMetrics.php](../app/Services/Analytics/EmailMetrics.php)); nothing loads messages into memory.

| Brief item | Status | Where |
| --- | --- | --- |
| Accepted/sent, confirmed deliveries | IMPLEMENTED | DeliveryStatsOverview. Delivery rate = delivered ÷ accepted; acceptance is never reported as delivery |
| Hard and soft bounces, complaints, unsubscribes | IMPLEMENTED | DeliveryStatsOverview, rates ÷ accepted |
| Scheduled and sending campaigns, failed queue jobs | IMPLEMENTED | OperationsOverview |
| Recent campaign activity | IMPLEMENTED | CampaignPerformanceTable |
| Total prospects, active contacts, suppressed contacts | MISSING | No audience figures on the dashboard |
| Total and draft campaigns | MISSING | |

Recommendation: add an audience and campaign summary widget. **Done in this pass** (section 9).

### 3.2 Prospect management: PARTIALLY IMPLEMENTED

The `contacts` table ([Contact.php](../app/Models/Contact.php)) is the only person record in the system; there are no separate leads, customers or prospects tables, so it should be extended rather than duplicated.

| Brief field | Status | Existing column |
| --- | --- | --- |
| Name, email, company | IMPLEMENTED | `first_name`, `last_name`, `email` (unique, lower-cased), `company` |
| Contact source | IMPLEMENTED | `source` |
| Consent status, timestamp, source | IMPLEMENTED | `consent_status` (granted, unknown, revoked), `consented_at`, `consent_source` |
| Job title, industry, website | MISSING | |
| Contact status (New … Converted) | MISSING | |
| Unsubscribed, Do Not Contact | IMPLEMENTED in substance | `unsubscribed_at` and the suppression list (manual suppression = do not contact). Both are enforced at send time |
| Assigned user | MISSING | |
| Last contacted, last reply | MISSING | |
| Notes | MISSING | |

Marketing email already requires recorded consent (`Contact::eligibleFor()`), so a publicly available address is never treated as eligible for marketing. Addresses are normalised (trimmed, lower-cased) and unique.

Recommendation: add the missing fields to `contacts`. Keep Unsubscribed and Do Not Contact on the existing unsubscribe and suppression records, which the sending engine already enforces, instead of a second status column that could disagree with them. Last reply can only be recorded by hand: ProSend has no inbound mailbox, so replies cannot be detected. **Done in this pass**.

### 3.3 Contact lists and segmentation: IMPLEMENTED

| Brief item | Where |
| --- | --- |
| Lists, add and remove contacts | ContactListResource, ContactsTable bulk actions |
| Import into a selected list | ContactImporter option "Add imported contacts to list" |
| Rule-based audiences | SegmentResource, [SegmentQuery.php](../app/Services/Segments/SegmentQuery.php) |
| Preview recipient counts | [CampaignReadiness.php](../app/Services/Campaigns/CampaignReadiness.php): eligible, skipped, duplicate counts and quota before launch |
| Exclude unsubscribed and suppressed | `Contact::eligibleFor()`, checked again by every send job |
| No duplicate recipients | Unique `contacts.email` and unique `(campaign_id, contact_id)` on `email_messages` |
| Large datasets | Recipients are created in chunks of 500 with `insertOrIgnore` |

Gap: filters for industry, job title and status, because those fields did not exist. **Done in this pass** (table filters and segment rules).

### 3.4 CSV and Excel import: PARTIALLY IMPLEMENTED

| Brief item | Status | Notes |
| --- | --- | --- |
| CSV import, field mapping, chunked queued processing | IMPLEMENTED | [ContactImporter.php](../app/Filament/Imports/ContactImporter.php), Filament import action |
| Email validation, row errors, summary, failed-row download | IMPLEMENTED | `email:rfc`; failed rows downloadable from the completion notification |
| Duplicate detection | IMPLEMENTED | Matched by normalised email; existing contacts are updated, empty cells never overwrite data |
| Consent and source captured | IMPLEMENTED | Per-row consent column or a default, consent source, and a lawful-basis confirmation |
| Imports cannot undo unsubscribes or suppressions | IMPLEMENTED | |
| Import preview and approval | MISSING | Planned: Phase 3 M3, two-person approval (decision L11) |
| Excel (XLSX) import | MISSING | Filament's import action reads CSV only. **Built after your approval (D4)**: `openspout/openspout` is now a direct dependency, and the contacts import accepts .xlsx (first worksheet) through the same mapping, validation and consent rules |
| CSV formula injection protection | MISSING | Filament's protection is opt-in and was not enabled: a contact named `=HYPERLINK(...)` was exported unchanged, and the failed-rows CSV echoed uploaded cells unchanged. **Fixed in this pass** |
| No import into an active campaign without review | PARTIALLY IMPLEMENTED | Imports go to lists, never to campaigns. But a campaign evaluates its lists when it starts sending, so contacts imported into a list targeted by a *scheduled* campaign are included without further review. Once sending starts the recipient set is fixed. **Addressed in this pass** with campaign approval: if the eligible audience grows beyond the approved size, the campaign needs approving again (section 9) |

## 4. Phase 3 of the brief: Email templates: IMPLEMENTED

| Brief item | Where |
| --- | --- |
| Name, subject, HTML body, plain-text body, category, status | [EmailTemplate.php](../app/Models/EmailTemplate.php); status is draft, active or archived |
| Preview, test email | ViewEmailTemplate, [SendTestEmail.php](../app/Actions/Messages/SendTestEmail.php) |
| Created by, updated by, version history | `created_by`, `updated_by`, `email_template_versions` with restore |
| No PHP or Blade evaluation | Templates are never compiled; placeholders are replaced by a regular expression ([EmailContentRenderer.php](../app/Services/Sending/EmailContentRenderer.php)) |
| Escaped values, sanitised HTML | Values are HTML-escaped, then the whole body passes Filament's HTML sanitiser, so a value placed in a link cannot become `javascript:` |
| Unsubscribe link and headers | Footer link plus RFC 8058 one-click `List-Unsubscribe` headers on all marketing email |
| Transactional kept separate from marketing | Template purpose must match the campaign or email purpose |

Gaps:

- **`{{name}}` is not a built-in variable.** Built-ins are `first_name`, `last_name`, `company_name`, `email`, `unsubscribe_url`; any other name is looked up in the contact's custom attributes (data only, always escaped). **Done in this pass**: `name`, plus `job_title`, `industry` and `website` for the new fields.
- **Business contact details are missing from the marketing footer.** The footer names the sender but has no postal address, which CAN-SPAM requires in commercial email. **Done in this pass**: a configurable `PROSEND_POSTAL_ADDRESS`, shown in the footer, with a campaign readiness warning when it is not set.

## 5. Phase 4 of the brief: Campaign management: PARTIALLY IMPLEMENTED

| Brief item | Status | Where |
| --- | --- | --- |
| Name, type, template, subject | IMPLEMENTED | `campaigns.name`, `purpose` (marketing or transactional), `email_template_id`, `subject` |
| Sender name, sender email, reply-to | IMPLEMENTED | Through the sender identity (`sender_identities.name`, `email`, `reply_to`) |
| Target lists | IMPLEMENTED | Lists, a segment, selected contacts, or all contacts |
| Schedule, started, completed, created by | IMPLEMENTED | |
| Recipient count, rendered preview, test email | IMPLEMENTED | Readiness report on launch; preview and test actions on the campaign |
| Pause, resume, cancel | IMPLEMENTED | [app/Actions/Campaigns](../app/Actions/Campaigns), each audit-logged |
| Recipient-level delivery and error details | IMPLEMENTED | MessagesRelationManager, CSV export |
| No duplicate execution | IMPLEMENTED | Conditional status updates, `WithoutOverlapping`, unique recipients, atomic message claim |
| Never sent synchronously in an HTTP request | IMPLEMENTED | Launch only queues `PrepareCampaignMessages` |
| Recipient set fixed once sending starts | IMPLEMENTED | Recipients are created once by the prepare job |
| Statuses | PARTIALLY IMPLEMENTED | Draft, Scheduled, Queued, Sending, Paused, Completed, Partially failed, Failed, Cancelled. No Pending Approval. **Added in this pass** |
| Approved by; separation of creation, approval and sending | MISSING | A Sender could create and launch a campaign alone. Phase 3 plans a "Validated" state (L14, M6), which is a check, not an approval. **Built in this pass** (section 9, item 8) |

## 6. Phase 5 of the brief: Sending engine: IMPLEMENTED, with gaps

Flow: Campaign resource → [LaunchCampaign](../app/Actions/Campaigns/LaunchCampaign.php) → [PrepareCampaignMessages](../app/Jobs/PrepareCampaignMessages.php) → [CampaignDispatcher](../app/Services/Campaigns/CampaignDispatcher.php) (one `email_messages` row per recipient) → [SendEmailMessage](../app/Jobs/SendEmailMessage.php) → [EmailSender](../app/Services/Sending/EmailSender.php) → provider driver → SMTP → Brevo webhooks.

| Brief item | Status | Notes |
| --- | --- | --- |
| Suppression checked before each send | IMPLEMENTED | `RecipientEligibility` in the send job |
| One recipient-specific message per job | IMPLEMENTED | |
| Attempts recorded | IMPLEMENTED | `email_message_attempts` with outcome, code and provider message id |
| Temporary vs permanent failures | IMPLEMENTED | 4xx and connection errors retry after 1, 5, 15 and 60 minutes (`prosend.sending.retry_delays`); 5xx rejections fail without retry; an ambiguous hand-over is never retried automatically |
| Secrets kept out of errors | IMPLEMENTED | `SendFailure` strips the SMTP username |
| No duplicate sends on retry | IMPLEMENTED | The message row is claimed atomically; the Message-ID is stored before sending |
| Campaign progress, pause and cancel honoured mid-send | IMPLEMENTED | |
| Configurable sending rate | IMPLEMENTED | Per-campaign rate (capped by `PROSEND_MAX_SEND_RATE`), provider per-minute and daily limits, warm-up plans |
| Per-recipient-domain rate limits | MISSING | **Done in this pass**: optional, off by default |
| Worker timeouts | IMPLEMENTED | Send job 60 s, prepare job 600 s, `DB_QUEUE_RETRY_AFTER=660` |
| Failed-job monitoring | PARTIALLY IMPLEMENTED | Failed-job count on the dashboard; a stalled-queue banner exists as uncommitted work in progress (`QueueHealth`); a Queue Monitor page is planned for M6 |
| Queue names and priorities | PARTIALLY IMPLEMENTED | One configurable queue (`PROSEND_QUEUE`, default `default`). Phase 3 plans separate `default`, `webhooks`, `automation` and `imports` queues |
| Redis and Horizon | Recommendation | Use Redis for the queue and cache before high volume (decision L15). Horizon is a new package and needs a Redis server; not added |

## 7. Phase 6 of the brief: Database design

Every table the brief proposes already exists under another name, except follow-up automation.

| Brief table | Existing equivalent | Status |
| --- | --- | --- |
| `email_templates` | `email_templates` + `email_template_versions` | IMPLEMENTED |
| `email_campaigns` | `campaigns` + `campaign_contact_list`, `campaign_contact` | IMPLEMENTED; approval columns added in this pass |
| `email_campaign_recipients` | `email_messages`: one row per recipient, unique `(campaign_id, contact_id)`, indexes on `(campaign_id, status)`, `to_email`, unique `provider_message_id`; attempts in `email_message_attempts` | IMPLEMENTED |
| `email_events` | `email_events` with a unique `fingerprint` for idempotency | IMPLEMENTED |
| `email_suppressions` | `suppressions`: unique lower-cased `email`, reasons hard bounce, complaint, invalid address, unsubscribed, manual; no foreign key to contacts, so deleting a contact never removes its suppression | IMPLEMENTED |
| Follow-up sequences, steps, enrolments, executions | None | MISSING. Phase 3 M4 (automation) has an approved design with the same entities. See decision D2 |

## 8. Phase 7 of the brief: Delivery tracking and webhooks

| Item | Status | Notes |
| --- | --- | --- |
| Acceptance distinguished from delivery | IMPLEMENTED | Status "Accepted by provider" vs "Delivered" (webhook only) |
| Brevo webhooks | IMPLEMENTED | [BrevoWebhookController.php](../app/Http/Controllers/BrevoWebhookController.php): delivered, soft and hard bounce, invalid, blocked, error, spam, unsubscribed, opened, clicked; batches of up to 1,000 |
| Webhook authentication | IMPLEMENTED | Per-provider secret token in the URL, compared in constant time, shown once. Possible hardening: Brevo can also send an authorization header |
| Duplicate events ignored | IMPLEMENTED | Unique event fingerprint |
| Bounces and complaints suppress the address | IMPLEMENTED | |
| Provider capability flags | IMPLEMENTED | [ProviderCapabilities.php](../app/Services/Providers/ProviderCapabilities.php); a generic SMTP provider reports no delivery events, so its messages stay "Accepted by provider" |
| Brevo webhook configured in Brevo | NEEDS VERIFICATION | Section K of the Phase 3 plan lists it as not yet done |
| Self-hosted SMTP server | Not applicable | You decided to keep Brevo for sending (D3), so Brevo webhooks stay the source of delivery events. A self-hosted server would need bounce-mailbox processing (RFC 3464) first |
| Reply detection | MISSING | Needs an inbound mailbox (IMAP or an inbound webhook) |
| Rest of Phase 7 and later phases | Not assessable | The brief stops at "Authenticate webhook requests" |

## 9. Changes made in this pass

Each change is additive, needs no new package, and has tests.

1. **CSV/XLSX formula injection.** Every export column now neutralises cells that start with `=`, `+`, `-`, `@`, a tab or a carriage return (Filament's `preventFormulaInjection()`), and the failed-rows CSV from contact imports does the same. Re-importing an exported file strips that protective apostrophe again, so phone numbers such as `+44 20 …` survive the round trip.
2. **Prospect fields on contacts.** Job title, industry, website, stage (New, Active, Contacted, Replied, Interested, Converted), owner (assigned user), last contacted, last reply and notes, in the form, view, table, filters, bulk actions, import and export. Unsubscribed and Do Not Contact remain the existing unsubscribe and suppression records; the contact page gains a **Do not contact** action that adds a manual suppression.
   - *Last contacted* is set automatically when a provider accepts a campaign or Compose email to the contact. API email is excluded, because system email such as receipts is not outreach. A New or Active prospect then moves to Contacted. The migration applies the same rule to email sent before it ran. A failure in this bookkeeping is reported but never interrupts sending.
   - Saving a contact's edit form only writes the stage if the user changed it, so a form opened before a send cannot undo the move to Contacted.
   - Known gap: an email whose hand-over was ambiguous ("Status unknown") and that a webhook later reports as delivered does not update *last contacted*.
   - *Last reply* is entered by hand; there is no reply detection.
3. **Segment rules** for stage, industry, job title and source.
4. **Merge tags** `{{name}}`, `{{job_title}}`, `{{industry}}` and `{{website}}`. Before this pass these names were read from custom attributes, so when the new field is empty a custom attribute of the same name is still used; existing content keeps working.
5. **Business postal address** (`PROSEND_POSTAL_ADDRESS`) in the marketing footer, with a readiness warning on marketing campaigns while it is empty.
6. **Per-recipient-domain limits** (`prosend.sending.recipient_domain_limits`), for example `'gmail.com' => 60` per minute. Empty by default, so nothing changes until you configure it. With Brevo, Brevo already throttles per destination; this matters more for a self-hosted server.
7. **Dashboard audience and campaign summary**: contacts, marketing-eligible contacts, suppressed addresses, unsubscribed contacts, and campaigns by draft, scheduled and sending.
8. **Campaign approval (decision D1).** Campaigns are now sent in two steps.
   - A Sender or administrator chooses **Submit for approval**. Readiness checks must pass, the campaign is locked against edits, and administrators are notified in the panel.
   - An administrator chooses **Approve and send** or **Approve and schedule**. This records `approved_by`, `approved_at` and the approved recipient count, then queues the campaign as before.
   - **Return to draft** (an administrator, with a reason) and **Withdraw** (the submitter) unlock it again.
   - `PROSEND_CAMPAIGN_REQUIRE_SECOND_APPROVER=true` forbids approving a campaign you created or submitted. It is off by default, because the original recommendation (always a different administrator) would lock out an installation with a single administrator.
   - When sending is due, a campaign whose eligible audience has grown by more than `PROSEND_CAMPAIGN_APPROVAL_GROWTH_PERCENT` (default 10) returns to Pending approval instead of sending, and approvers are notified.
   - Campaigns launched before this change are not affected.
   - New migration: `add_approval_columns_to_campaigns_table`.
   - An approval applies only to what the approver reviewed. The approval window pins a fingerprint of the submission, content and audience settings, plus the recipient count it showed. The click is refused if the campaign was changed or resubmitted meanwhile, or if the audience grew beyond the margin. The reviewed count becomes the approved count.
   - A campaign that has started sending can never return to approval or to draft, or be edited or deleted. This is enforced in the same database update as the status change. Campaigns are never prepared before their scheduled time, even by a leftover job.
   - With a second approver required, whoever last edited the campaign (`updated_by`) is excluded too.
   - Limit: the growth check compares counts only. A segment change or list swap that keeps the total within the margin is not caught.
   - Fixed on the way: duplicating a campaign copied `prepared_at`. If such a copy was paused before preparation finished, resuming it would only send to the recipients already prepared.
9. **"Contains" filters match `%` and `_` literally on every database.** The new filters and segment rules, and the existing company segment rule, use `Contact::whereContains()`. The company rule previously escaped wildcards with a backslash, which MySQL honours but SQLite does not.
10. **Excel import (decision D4).** Contacts > Import / export > **Import CSV or Excel** accepts .xlsx workbooks. The first worksheet is converted to CSV on the server (`App\Services\Imports\SpreadsheetConverter`) and then goes through the same column mapping, validation, consent and failed-row handling as a CSV file. `openspout/openspout` became a direct dependency; nothing new was installed.
   - An independent review found ten problems in the first version, and all are fixed and tested:
     - Formulas imported as formula text; they now import their results.
     - A tiny crafted workbook could exhaust memory or CPU during upload. Workbooks are now checked first: at most 200 MB uncompressed, 500 declared columns and 100,000 rows.
     - The conversion repeated 4–16 times per upload; it now runs once, cached next to the upload.
     - Delimiter guessing could split on semicolons; converted files are now always read as comma-separated.
     - A cell ending in a backslash could swallow the rows after it.
     - Notes beyond the header got the file rejected.
     - An empty first sheet crashed the import, and a hidden first sheet was imported.
     - Some number and time values came out wrong.
     - Handles and temporary files leaked.
   - Fixed on the way, a pre-existing bug: the import form passes the chosen default consent as an enum, and `ContactImporter` converted it with `(string)`. That threw for every row, so **every contact import from the panel failed** with an unnamed system error. For the same reason, choosing "Consent granted" never required a consent source. Both are fixed and covered by tests that drive the real import form; the earlier tests passed the option as a plain string.

Migrations added: `add_prospect_fields_to_contacts_table` (nullable columns plus `stage` with a default) and `add_approval_columns_to_campaigns_table` (nullable columns). Both are reversible, checked by rolling back and re-applying. Run `php artisan migrate`.

Three independent reviews checked this work, and every finding was fixed and tested.

- **Items 1–7 and 9:** the review found no high-severity issues. Its findings were fixed and covered by tests: the custom-attribute fallback, exported email addresses re-importing as duplicates, website normalisation in the form and with a port, the stale-form stage overwrite, and the missing backfill.
- **Item 8:** the review found one high-severity issue. A campaign resumed during preparation could be sent back for approval while it was already sending. It also found that an approval could apply to a resubmitted version. Both are fixed and tested, along with the smaller findings.
- **Item 10:** the review's ten findings are listed under that item.

The full suite passes on in-memory SQLite: 542 tests, including other sessions' concurrent work.

## 10. Decisions needed before further work

| # | Decision | Recommendation |
| --- | --- | --- |
| D1 | Campaign approval | **Built** (section 9, item 8), for all campaigns, with the second-approver rule as an opt-in setting. M6's planned "Validated" state (L14) is now largely covered: the readiness report is checked on submission and again on approval, and the approved recipient count is stored |
| D2 | Follow-up sequences | **Approved:** build them as Phase 3 M4 automation (send, wait, condition, stop on unsubscribe), not as separate tables. "Stop on reply" needs an inbound mailbox, which ProSend does not have |
| D3 | Does your own SMTP server replace Brevo? | **Decided: no, keep Brevo.** Brevo webhooks remain the delivery, bounce and complaint source |
| D4 | Excel import | **Approved and built:** `openspout/openspout` is a direct dependency; the contacts import accepts .xlsx now, and M3's upload will reuse the same converter |
| D5 | Production queue stack | **Decided: not yet.** Keep the database queue and cache; revisit Redis before high-volume sending |
| D6 | The rest of the brief | Paste the part after "Authenticate webhook requests" if it exists |

## 11. Testing requirements

- New tests in this pass cover the export sanitisation and import round trip, prospect fields in the form, filters, bulk actions, importer and exporter, the last-contacted update, segment rules, merge tags, the footer address and readiness warning, per-domain limits, and the dashboard widget.
- Every future item needs feature tests that never contact a real provider (`Tests\Fakes\FakeMailProviderDriver`), plus an independent review before sign-off, as in earlier milestones.
- D1's tests cover: only administrators approve; the second-approver rule for creator and submitter; drafts that were never submitted cannot launch; return to draft and withdrawal; notifications; audience growth beyond and within the margin.
- Excel import is covered by converter tests (dates, numbers, booleans, short and long rows, second sheet ignored, unreadable files) and end-to-end uploads through the contacts page.

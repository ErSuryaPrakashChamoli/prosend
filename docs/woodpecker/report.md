# ProSend outreach platform: implementation report

23 November 2026. Final report for the "Woodpecker.co-style sales outreach platform" brief. The starting audit, comparison matrix and roadmap are in [audit.md](audit.md); stage-by-stage progress is in [checklist.md](checklist.md).

**In short:** ProSend is now a multi-client outreach platform. Clients are isolated, roles carry granular permissions, and campaigns are sequences with conditional follow-ups. The platform adds sending windows, its own open and click tracking, a unified inbox that reads replies and bounces, A/B testing, automation rules, signed webhooks, reports with exports, Gmail and Microsoft 365 OAuth mailboxes, a wider REST API, and administration pages. The test suite grew from 542 to 764 tests, all passing. Every stage had an independent code review. Every finding was fixed with a regression test, except report days being in UTC, which is documented as a limitation (section 3).

What is **not** done, or not verified, is listed plainly in sections 3 and 11. The most important items:
- The Gmail/Microsoft integration is untested against real accounts.
- Automated warm-up needs a third-party service.
- Defined custom fields are not built.
- The new migrations have not been run on `db_email`.

## 1. Existing functionality audit

See [audit.md](audit.md), sections 1 and 2. ProSend already had a mature single-tenant email platform: contacts with consent, lists, segments, templates, rate-limited campaigns with approval, Brevo, Mailtrap and SMTP providers, domain authentication, warm-up plans, a sending API, and audit logs. It had no clients, sequences, inbox, A/B testing, automation, webhooks or reports.

## 2. Feature comparison matrix

"Tested" means feature tests cover the behaviour. External services are faked; nothing reaches a real provider, DNS or the network in tests.

| Feature | Previous status | Implementation | Test status | Final status |
| --- | --- | --- | --- | --- |
| **Dashboard & analytics** | | | | |
| Totals, sent, delivered, bounce, unsubscribe | COMPLETE | unchanged | tested | COMPLETE |
| Open and click rate | PARTIAL (Brevo only) | Own pixel and signed click tracking per campaign | tested | COMPLETE (approximate by nature) |
| Reply rate, positive reply rate, conversion | MISSING | `OutreachOverview` widget, `OutreachReport` | tested | COMPLETE |
| Team, user, client and mailbox performance | MISSING | Reports page groupings | tested | COMPLETE |
| Email account health | PARTIAL | Deliverability score per sender, System health | tested | COMPLETE |
| **Prospects** | | | | |
| CRUD, CSV/Excel import, export, duplicates, tags, segments, lists, suppressions, unsubscribes | COMPLETE | now per client; import bound to its client | tested | COMPLETE |
| Time zone per prospect | MISSING | `contacts.timezone`, form, import, export | tested | COMPLETE |
| Activity timeline | PARTIAL | Sequence history per prospect, inbox thread | tested | PARTIAL (no single combined timeline page) |
| Defined custom fields | PARTIAL (free-form) | not built | — | PARTIAL |
| **Email accounts** | | | | |
| SMTP, limits, health, errors, disable | COMPLETE | unchanged | tested | COMPLETE |
| IMAP | MISSING | Inbound mailboxes (webklex/php-imap), read-only EXAMINE | tested with a fake server | COMPLETE (not tried against a live server) |
| Gmail / Microsoft 365 OAuth | MISSING | PKCE connect flow, encrypted tokens, refresh, API send and read | tested against faked APIs | BUILT, NOT VERIFIED (needs your OAuth apps) |
| Sending schedules and time zones | MISSING | Per-campaign windows, client defaults, prospect time zones | tested | COMPLETE |
| **Campaigns** | | | | |
| Create, edit, duplicate, pause, resume, stop, delete, schedule, approve, preview, test | COMPLETE | Stop relabelled; duplicates copy follow-ups and versions | tested | COMPLETE |
| Archive | MISSING | Archive/restore, hidden by default | tested | COMPLETE |
| Multi-step sequences | MISSING | Follow-up steps, conditions, threading | tested | COMPLETE |
| **Sequence automation** | | | | |
| Follow-ups on no reply / opened / not opened | MISSING | `StepCondition`, `SequenceRunner` | tested | COMPLETE |
| Stop on reply, unsubscribe, bounce, complaint | PARTIAL | `SequenceProgress` hooks plus send-time re-checks | tested | COMPLETE |
| Business hours, weekends, time zones | MISSING | `SendingWindow` | tested | COMPLETE |
| Retry, locking, duplicate prevention | COMPLETE | Runner claims prospects in a transaction | tested | COMPLETE |
| Execution logs | MISSING | `campaign_prospect_events`, history per prospect | tested | COMPLETE |
| **Deliverability** | | | | |
| SPF, DKIM, DMARC, guidance, bounce and complaint monitoring | COMPLETE | unchanged | tested | COMPLETE |
| Bounces from SMTP mailboxes | MISSING | Bounce reports read from inbound mailboxes | tested | COMPLETE |
| Reputation indicator | MISSING | Deliverability score (risk, not a prediction) | tested | COMPLETE |
| **Warm-up** | | | | |
| Volume ramp, limits, schedules, health review | COMPLETE | unchanged | tested | COMPLETE |
| Automated warm-up interactions | MISSING | not built; needs an authorised third-party service | — | MISSING (external dependency) |
| **Unified inbox** | MISSING | Conversations, 7 statuses, unread, star, assignment, notes, replies in thread, attachments, search, visibility rules | tested | COMPLETE |
| **A/B testing** | MISSING | Versions B–E per email, weights, results, significance, stop a version | tested | COMPLETE |
| **Team management** | | | | |
| Users, activity logs | COMPLETE | Users are now agency-wide | tested | COMPLETE |
| Roles and granular permissions | PARTIAL (3 fixed roles) | 6 built-in roles plus Super Admin, custom roles, 28 permissions | tested | COMPLETE |
| Teams, team analytics | MISSING | Teams, team report | tested | COMPLETE |
| Campaign and prospect ownership | PARTIAL | Owners limited to client members; inbox visibility by owner | tested | COMPLETE |
| **Agency / multi-client** | MISSING | Clients, switching, isolation in panel/API/jobs/webhooks/imports/exports, invitations, deactivation, read-only roles, client activity logs | tested (isolation suite) | COMPLETE |
| **Reports & export** | | | | |
| Campaign, prospect, delivery, reply, team, client, deliverability, daily, comparison | PARTIAL | Reports page | tested | COMPLETE |
| CSV, Excel | PARTIAL | `ReportExporter` (openspout) | tested | COMPLETE |
| PDF | MISSING | Printable page, "Save as PDF" in the browser (no PDF library approved) | tested | COMPLETE (browser print) |
| **Integrations & API** | | | | |
| REST API, authentication, rate limits, docs | COMPLETE (sending) | Prospects, campaigns, conversations; OpenAPI updated | tested | COMPLETE |
| Incoming webhooks / Zapier-style intake | PARTIAL | `PUT /api/v1/prospects` (API key in header) | tested | COMPLETE |
| Outgoing webhooks, retries, logs | MISSING | Standard Webhooks signing, retries, auto-disable, delivery log, redelivery | tested | COMPLETE |
| CRM / Google Sheets | MISSING | Through the API and webhooks; no vendor-specific connectors | — | PARTIAL (no native connectors) |
| **Automation rules** | MISSING | 11 triggers, 6 conditions, 9 actions, run log, loop guards | tested | COMPLETE |
| **Administration** | | | | |
| Client settings, campaign defaults, footer address | PARTIAL | Client settings page | tested | COMPLETE |
| System health, failed jobs | PARTIAL | System health and Failed jobs pages | tested | COMPLETE |
| Data retention | COMPLETE | New tables pruned too | tested | COMPLETE |

## 3. Missing functionality (still open)

1. **Live verification of Gmail and Microsoft 365.** The code follows each API's documented formats and is tested against faked responses only. One thing is unconfirmed: whether Microsoft Graph keeps ProSend's own Message-ID on MIME sends. Reply threading depends on it.
2. **Automated warm-up.** ProSend's warm-up planner ramps volume safely. Exchanging warm-up emails with a network of real mailboxes needs a third-party service (such as Mailreach or Warmup Inbox). No such integration exists, and ProSend never simulates one.
3. **Defined custom fields** (a typed field list per client). Free-form custom attributes work today in forms, imports, segments and merge tags.
4. **A single prospect timeline** combining emails, replies, sequence steps and notes. Each piece is visible today, on the prospect, the campaign and the conversation.
5. **Native CRM and Google Sheets connectors.** Tools such as Zapier or Make can use the API and webhooks. HubSpot, Salesforce and Google Sheets apps would each need OAuth apps and vendor APIs.
6. **Reply authentication.** Replies are matched by threading headers and sender. A forged email from a prospect's address could still end that prospect's sequence (but never suppress the address). Checking `Authentication-Results` (DMARC) is a possible later improvement.
7. **Report days are UTC.** Reports and the dashboard count days and date ranges in UTC, not in the client's time zone. For an Indian client, an email accepted between midnight and 05:30 IST counts on the previous day. Fixing it means changing the dashboard's existing date handling too.
8. **Very large Gmail backlogs.** Gmail lists newest first, so each sync lists everything since the last email read, up to 10,000 emails. A backlog bigger than that, for example a first sync of a very busy inbox, loses the oldest emails beyond the 10,000.

## 4. Implemented modules

| Stage | Main pieces |
| --- | --- |
| W1 Clients, roles, teams | `Client`, `Role`, `ClientMembership`, `Team`; `BelongsToClient`, `ClientScope`, `CurrentClient`; Filament tenancy and client switcher; Clients, Roles, Users, Members, Teams pages; invitations |
| W2 Sequences | `CampaignStep`, `CampaignProspect`, `SequenceRunner` (`prosend:run-sequences`, every minute), `SequenceProgress`, `SendingWindow`, `EmailTracker`, `TrackingController`, Prospects tab, archive |
| W3 Inbox | `InboundMailbox`, `Conversation`, `ConversationMessage`, `ConversationNote`; `ImapMailboxReader`, `MimeParser`, `InboundEmailProcessor`, `InboxSynchronizer` (`prosend:sync-inboxes`, every 5 minutes), `ConversationReplier`, `ConversationManager`; Inbox and Inbound mailboxes pages |
| W4 A/B testing | `CampaignVariant`, `VariantPicker`, `AbTestResults` |
| W5 Automation and webhooks | `OutreachEvents`, `AutomationEngine`, `RunAutomationRules`, `Task`, `WebhookEndpoint`, `WebhookDelivery`, `DeliverWebhook`, `WebhookSigner`; Automation rules, Tasks, Webhooks pages |
| W6 Reports | `OutreachReport`, `ReportExporter`, Reports page, printable report, `OutreachOverview` widget |
| W7 OAuth mailboxes | `OAuthClient`, `ApiMailTransport`, `GmailApiDriver`, `MicrosoftGraphDriver`, `ApiMailboxReader`, `OAuthMailboxController` |
| W8 API | Prospect, campaign and conversation endpoints, five new scopes, `UpsertProspect` |
| W9 Administration | Client settings, `SystemHealth` with scheduler heartbeat, Failed jobs, `DeliverabilityScore` |

## 5. Database changes

Nine new migrations, all additive. Existing rows join a "Default client", former administrators become Super Admins, and senders and viewers keep equivalent roles. Each migration was run on a scratch SQLite database holding existing data, then rolled back and run again. The app's MySQL user cannot create databases, so **they have not been run on MySQL**; three MySQL-specific hazards found in review were fixed (index replacement order, foreign keys before indexes on rollback, and `NOT NULL` before adding foreign keys).

| Migration | Adds |
| --- | --- |
| `create_clients_and_roles_tables` | clients, roles (+6 built-in), client_user, teams, team_user; Default client and memberships |
| `add_client_id_to_client_owned_tables` | client_id on 12 tables (+ audit_logs); per-client unique indexes |
| `add_sequences_to_campaigns` | campaign_steps, campaign_prospects, campaign_prospect_events; window and tracking columns; contacts.timezone; email_messages.step_number. Rollback is refused once follow-ups exist |
| `create_inbox_tables` | inbound_mailboxes, conversations, conversation_messages, conversation_notes; inbox permissions |
| `create_campaign_variants_table` | campaign_variants; email_messages.campaign_variant_id |
| `create_automation_and_webhook_tables` | automation_rules, automation_runs, tasks, webhook_endpoints, webhook_deliveries; permissions |
| `grant_report_permissions` | report permissions for built-in roles |
| `add_oauth_columns_to_mailboxes` | encrypted OAuth tokens on smtp_providers; inbound_mailboxes.smtp_provider_id |
| `add_settings_to_clients` | postal address and campaign defaults; settings permission |

## 6. API documentation

[docs/api/openapi.yaml](../api/openapi.yaml) (OpenAPI 3.1) documents every endpoint, the new scopes, pagination, and how to verify webhook signatures.

## 7. External integration report

| Integration | State | What it needs |
| --- | --- | --- |
| Brevo, Mailtrap, generic SMTP | Working as before | — |
| IMAP inbound mailboxes | Built; parsing and processing tested with real email formats | A mailbox's IMAP host and credentials (an app password for Gmail and Microsoft 365) |
| Gmail / Google Workspace | Built; **not verified** | A Google Cloud OAuth app with `gmail.send` and `gmail.readonly`, plus the redirect URI `{APP_URL}/admin/oauth/google/callback`. These are restricted scopes: Google must verify the app (including a security assessment) before accounts outside your organisation can use it |
| Microsoft 365 / Outlook | Built; **not verified** | An Azure app registration with `Mail.Send`, `Mail.Read`, `User.Read` and `offline_access`, plus the redirect URI `{APP_URL}/admin/oauth/microsoft/callback` |
| Outgoing webhooks (CRMs, Zapier, Make) | Built and tested | The receiver verifies Standard Webhooks signatures |
| Warm-up network | Not built | A chosen vendor and its API |

## 8. Security audit

Each stage had an independent review, run as a separate agent. Its findings were reproduced, fixed and covered by regression tests. The most significant:

- **Tenant isolation:**
  - Queue jobs, the API, webhooks, the unsubscribe link, imports and exports all run as the right client.
  - An "all contacts" campaign is pinned to its client.
  - Records cannot be linked to, or moved into, another client.
  - User pickers list only the client's members.
  - Exports carry the client in the serialised query.
- **Privilege:**
  - Nobody can grant a role with more permissions than their own, or change their own membership.
  - Automation email actions need the approve-campaigns permission, both when a rule is saved and on every run.
  - A deactivated client's members and API keys are blocked, and its follow-ups, rules and webhooks wait. Emails already queued still go out (the deactivate dialog says so).
  - An API key can only be given access its creator has in the panel. Test keys can never change prospects or campaigns, because those changes would be real.
- **Inbound mail:**
  - Bounce reports must come from a mail system and may only suppress the address the email went to.
  - Mail from the team itself is ignored.
  - Notification text is escaped.
  - Threads without a prospect show no other people's email.
  - A malformed email cannot block a mailbox.
- **Network:**
  - IMAP hosts and webhook URLs are checked against private, link-local and metadata addresses. Webhooks have their own switch for private hosts.
  - Webhook connections are pinned to the checked address and follow no redirects.
  - Changing a mailbox's host requires its password again.
- **Secrets:**
  - SMTP and IMAP passwords, OAuth tokens and webhook secrets are encrypted and hidden.
  - Signing secrets are shown once.
  - OAuth uses PKCE with a session-bound, expiring state.
  - Token refreshes are not written to the audit log; connecting a mailbox is.
- **Abuse:**
  - Automation emails cannot trigger rules again.
  - They are capped per prospect and per rule each day.
  - Click links are signed, so they cannot become open redirects.
  - Tracking replays are rate-limited.

**Known limits:**
- DNS rebinding against IMAP hosts. The connection cannot be pinned the way SMTP and webhooks are.
- Unauthenticated replies (section 3).
- Composer reports 13 advisories in packages that were already installed (laravel/framework < 13.40, several Symfony components, PHPUnit). Updating them needs your approval.

## 9. Test execution report

- **764 tests, 2,661 assertions, all passing** on in-memory SQLite. Run with `php artisan test --compact --parallel --processes=3`: a full-width parallel run ran out of memory on this machine.
- About 220 new tests cover the brief's list:
  - Prospect CRUD, import and duplicates; email accounts.
  - Campaign creation and execution, scheduling, follow-ups, reply detection.
  - Unsubscribes, bounces, A/B testing, permissions, client isolation.
  - API authentication, webhooks, reports, queue retries, automation.
- They also cover the brief's edge cases:
  - Duplicate and concurrent runs, invalid and expired OAuth tokens, provider API failures.
  - Missing credentials, time zones, an unsubscribe mid-sequence, a reply while a follow-up is queued.
  - Cross-client access attempts.
- Two existing tests were made deterministic: an API-key test that failed 1 run in 62, and an idempotency test sensitive to the clock. Four existing tests were adapted to client-level audit entries and per-client senders. No test was removed.

## 10. Failed tests

None.

## 11. Remaining external dependencies

1. Google Cloud and Azure OAuth apps, plus Google's verification for restricted Gmail scopes.
2. A warm-up vendor, if automated warm-up is wanted.
3. SPF/DKIM for fynnedge.com (no SPF record today), and each client's own sending domains.
4. Approval to update the packages with security advisories.

## 12. Deployment instructions

1. Back up `db_email`. Then run `php artisan migrate`; it adds nine migrations.
2. Check the result:
   - Everything existing now belongs to "Default client". Rename it under Agency > Clients.
   - Former administrators are Super Admins.
   - Former senders are Campaign Managers.
   - Former viewers are Guest Viewers.
3. Make sure the cron entry `* * * * * php artisan schedule:run` exists. Follow-ups (every minute), inbox checks (every 5 minutes) and nightly jobs depend on it; System health shows whether it runs.
4. Run a queue worker for each queue in use. By default everything uses `default`. Setting `PROSEND_AUTOMATION_QUEUE=automation` (recommended at volume) needs a worker for it too, e.g. `php artisan queue:work --queue=default,automation`. Restart workers after deploying.
5. Set the new options as needed:
   - **Optional:** `GOOGLE_CLIENT_ID/SECRET`, `MICROSOFT_CLIENT_ID/SECRET/TENANT`.
   - **Optional caps:** `PROSEND_AUTOMATION_MAX_EMAILS_PER_PROSPECT` (3), `PROSEND_AUTOMATION_MAX_EMAILS_PER_RULE` (200).
   - **Webhooks:** `PROSEND_WEBHOOKS_ALLOW_PRIVATE_HOSTS` (false).
   - **Retention:** `PROSEND_RETAIN_AUTOMATION_RUNS_DAYS` (90), `PROSEND_RETAIN_WEBHOOK_DELIVERIES_DAYS` (30).
6. For each client:
   - Set its postal address under Client settings.
   - Add inbound mailboxes (or connect Gmail / Microsoft 365), so replies stop sequences and bounce reports suppress addresses.
7. Never run `config:cache` or `optimize` on a machine where tests run. The test guard refuses to run, but the cache still affects the dev server.

## 13. Production readiness assessment

**Ready to use, with conditions.**
- **The core is sound:** isolation, sequences, inbox, A/B, automation, webhooks, reports and the API are tested and reviewed, and sending keeps its existing guarantees (atomic claims, no duplicate sends, consent and suppression checks).
- **Before production:**
  - Run the migrations on a copy of the MySQL database first; they were only exercised on SQLite.
  - Verify Gmail and Microsoft 365 with real test accounts before offering them to clients.
  - Configure SPF, DKIM and DMARC for every sending domain.
  - Run the scheduler and workers under supervision.
  - Move to Redis for queue and cache before high volume (decision D5, still open).
- **Not yet production-grade:** the items in section 3.

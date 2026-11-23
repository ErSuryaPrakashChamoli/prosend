# Woodpecker brief: implementation checklist

Persistent checkpoint for the 23 November 2026 brief. A stage is ticked only when its tests pass, the full suite is green and it has been reviewed. Resume from the first unticked stage. Plan and statuses: [audit.md](audit.md).

Baseline: 542 tests passing (23 Nov 2026).

## Stages

- [x] **W1 Clients, tenancy, roles, teams** — clients (Filament tenancy + ProSend `ClientScope`), 6 system roles + custom roles with 18 permissions, per-client memberships, Super Admins, teams, invitations, isolation for panel/API/webhooks/jobs/imports/exports. Reviewed; 8 findings fixed with regression tests.
- [x] **W2 Sequences, sending windows, tracking, archive** — follow-up steps with conditions, per-prospect state and execution log, runner every minute, stop on reply/unsubscribe/bounce/complaint/manual/reply stage, send-time re-checks, sending windows with prospect time zones, threading, own open/click tracking, archive, prospects tab. Reviewed; 6 bugs + 4 smaller issues fixed with regression tests.
- [x] **W3 Inbound mail and unified inbox** — IMAP mailboxes (read-only EXAMINE, bounded batches, per-message cursor), reply and bounce detection, conversations with statuses, assignment, notes, replies, attachments, visibility rules. Reviewed; 11 findings fixed with regression tests (forged bounces, stuck mailbox, unbounded fetch, UIDVALIDITY, notification HTML, thread leak, internal senders, delivery reports, long names, auto-reply unread, reply time).
- [x] **W4 A/B testing** — versions B–E of first emails and follow-ups, weighted deterministic assignment, results per step (winner only at 100+ sends per version, 95% confidence, Bonferroni for several versions, stopped versions excluded), stop a version, approvers see every version. Reviewed; fixes tested.
- [x] **W5 Automation, tasks, webhooks** — outreach event bus (11 events), rules with conditions and 9 actions, run log, loop guards (depth and no rules from automation emails), email actions only for approvers with daily caps, tasks, signed outgoing webhooks (Standard Webhooks, SSRF-guarded with their own private-host switch, retries, auto-disable, delivery log, redeliver), pruning. Reviewed; fixes tested. Incoming prospect webhooks are the W8 API.
- [x] **W6 Reports, exports, analytics** — Reports page (campaign, people, team, mailbox, day, client), campaign comparison, CSV/Excel/print-PDF, dashboard outreach stats. Reviewed; fixes tested. Known limit: days are UTC (shared with the dashboard).
- [ ] **W7 Gmail and Microsoft 365 OAuth** — connect flow (PKCE, state, fixed callback), encrypted tokens with refresh and revocation handling, sending and reading through the Gmail API / Microsoft Graph. Built, reviewed, fixes tested against faked APIs. Stays unticked until verified with real accounts (needs the user's Google Cloud and Azure apps).
- [x] **W8 API expansion** — prospects (list, show, create-or-update by email, unsubscribe), campaigns (list, show, add prospects), conversations (list, show), five new scopes (write includes read), OpenAPI updated. Reviewed; key scopes now limited to the creator's permissions and test keys cannot write.
- [x] **W9 Settings, system health, deliverability score** — client settings (postal address, default sending days and hours, tracking defaults; Admins only), System health (scheduler heartbeat, queue backlog, failed jobs, mailbox errors), Failed jobs page (retry, delete), deliverability score per sender. Reviewed; fixes tested. Defined custom fields NOT built (free-form custom attributes remain).
- [x] **W10 Regression, review, report** — migration chain checked on SQLite with existing data (upgrade, rollback of all 9, re-run); [report.md](report.md) and README written; W6–W9 review fixed; 764 tests passing.

## Log

| Date | Stage | Result |
| --- | --- | --- |
| 2026-11-23 | Audit | audit.md written; IMAP package approved, PDF library not approved |
| 2026-11-23 | W1 | 598 tests passing; review: import, user pickers, MySQL rollback, role escalation, inactive client ids, repeater crash, activity export, minor items: all fixed |
| 2026-11-23 | W2 | 638 tests passing; migrations checked (upgrade with data, rollback, re-run) on SQLite; MySQL not available to the app user |
| 2026-11-23 | W2 review | completion when last waiting prospect stops, preview opens, reply stages, quota line position, step reorder, completion race, runner isolation, inactive clients, tracking replay, rollback guard: all fixed |
| 2026-11-23 | W3 | `webklex/php-imap` 6.2 installed; 670 tests passing |
| 2026-11-23 | W3 review | 11 findings fixed; W4 and W5 built; 712 tests passing |
| 2026-11-23 | W4/W5 review | automation email loop, approval bypass, A/B approver visibility, stopped versions, cache churn, queue, webhook private hosts and 9 smaller items fixed; W6 and W7 built; 742 tests passing |
| 2026-11-23 | W8/W9 | built; 756 tests expected; independent review of W6–W9 started |
| 2026-11-23 | W6–W9 review | token refresh failure now retried, Bcc kept on API sends, Gmail backlog paged oldest first with overlap, IMAP mailbox not taken over, no audit per token refresh, single score query, report index, positive rate per replying prospect, key scopes limited, test keys read-only for data, print needs reports.view, temp file, OpenAPI enum: fixed. UTC report days documented. 764 tests passing |

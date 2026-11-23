# Mailtrap and SMTP providers

How to connect ProSend to Mailtrap and other SMTP providers, choose which one each email uses, and switch safely. Mailtrap facts were checked against Mailtrap's documentation on 29 October 2026 (links at the end).

## What is supported

| Provider | Provider type in ProSend | What ProSend does beyond plain SMTP |
| --- | --- | --- |
| Mailtrap Email Sandbox | Mailtrap Email Sandbox (testing only) | Marks email as *Captured in sandbox*, never as accepted or delivered. Retries Mailtrap's sandbox rate limit. |
| Mailtrap Email Sending | Mailtrap Email Sending | Retries Mailtrap's rate and connection limits. Warns when marketing email would use the transactional stream. Domain authentication checks Mailtrap's DKIM records (`rwmt1`, `rwmt2`). Delivery webhooks are **not** processed yet. |
| Brevo | Brevo | Delivery and engagement webhooks; sender and domain checks with an API key. |
| Amazon SES, SendGrid, Microsoft 365, Google Workspace, any other SMTP service | Generic SMTP | Plain authenticated SMTP only: no webhooks, no sender or domain checks. Microsoft 365 and Google Workspace only work where the account allows SMTP authentication. |

Connecting over SMTP proves that ProSend can hand email over. It does not give ProSend the provider's delivery events, analytics, or verification status; only Brevo has those integrations today.

## Configure Mailtrap Email Sandbox

Use the sandbox for development and QA. Every email is kept in a Mailtrap inbox for inspection and nobody receives it.

1. In Mailtrap, open **Sandboxes**, choose a sandbox, then **Integration > SMTP**. Each sandbox has its own login and password.
2. In ProSend, go to **Infrastructure > SMTP providers > New SMTP provider** and choose **Mailtrap Email Sandbox (testing only)**. The host, port, and encryption are filled in.
3. Enter the sandbox's login and password, and save.
4. Add a sender identity on this provider under **Deliverability > Sender identities**. Any From address works in the sandbox.

| Setting | Value |
| --- | --- |
| Host | `sandbox.smtp.mailtrap.io` (the only host ProSend accepts for this type) |
| Port | 2525 (Mailtrap also lists 25, 465, and 587) |
| Encryption | STARTTLS. If the connection test fails, use the port and encryption shown on the sandbox's Integration tab. |
| Login and password | The sandbox's own, from its Integration tab |

Sandbox providers are refused when `APP_ENV=production`, unless `PROSEND_ALLOW_SANDBOX_PROVIDERS=true`. This stops real email from being captured by mistake.

## Configure Mailtrap Email Sending

Use this for real email to real recipients.

1. In Mailtrap, add and verify your sending domain (DNS records, then Mailtrap's compliance check). Mailtrap only sends once the domain is verified.
2. Get the API token: **Sending Setup > API/SMTP**, choose the domain and stream, then **SMTP**. You can also create a token under **Settings > API Tokens**. Each domain has its own token.
3. In ProSend, create a provider of type **Mailtrap Email Sending**. The login is always `api` and the password is the API token.
4. Set the limits to match your plan (see Sending limits below).

| Setting | Transactional stream | Bulk Stream |
| --- | --- | --- |
| Host | `live.smtp.mailtrap.io` | `bulk.smtp.mailtrap.io` |
| Port | 587 (also 25 and 2525; 465 with implicit TLS) | same |
| Encryption | STARTTLS | same |
| Login | `api` | `api` |
| Password | API token | same token |

Mailtrap requires marketing and promotional email to use the **Bulk Stream**. Create two providers, one per host, and put your marketing From address on the Bulk Stream provider. A marketing campaign whose sender uses the transactional host shows a warning before launch.

Sandbox and live credentials are different and never interchangeable. ProSend refuses a sandbox host on the live type and a live host on the sandbox type, and refuses any `mailtrap.io` host on the Brevo or Generic SMTP types.

## Test connection and test email

On a provider's page:

- **Test connection** connects and signs in, then disconnects. Nothing is sent. Success means the host, port, encryption, and credentials work.
- **Send test email** sends a short email to you through the full sending pipeline, from a sender identity on that provider. Through a live provider, *Accepted by provider* means the provider took it. Through the sandbox, *Captured in sandbox* means it is in the Mailtrap sandbox.

Neither proves inbox delivery. Only a provider delivery event can confirm that, and of the current providers only Brevo sends those to ProSend.

Every connection test is recorded in the audit log, without credentials.

## Choosing which provider sends

Each sender identity belongs to one provider, so the From address decides the provider:

- **Compose and campaigns:** the From address you pick.
- **Sending API:** the `from` address in the request, which must be one of the API key's allowed sender identities.
- **Test emails:** the sender identity chosen in the dialog.

Example setup, not a built-in rule:

| Scenario | Sender identity | Provider |
| --- | --- | --- |
| QA and staging tests | `qa@acme.test` | Mailtrap Email Sandbox |
| Transactional email | `noreply@acme.com` | Mailtrap Email Sending, `live.smtp.mailtrap.io` |
| Marketing campaigns | `news@mail.acme.com` | Brevo, or Mailtrap on `bulk.smtp.mailtrap.io` |

There is no separate "default provider" setting, because no email is sent without a sender identity. In Compose, the first usable sender identity is preselected.

**There is no automatic fallback to another provider.** If a provider fails, its email waits and retries on the same provider. Moving a message to another provider after an unclear failure could deliver it twice. Safe, opt-in fallback is planned for Phase 3, milestone M5.

Wherever a sender is picked, sandbox identities are labelled "(sandbox: captured, not delivered)".

## Switching providers safely

To move a From address to another provider, edit the sender identity and choose the new provider.

- The change is recorded in the audit log, as `sender.provider_changed`, with both provider names.
- The sender identity loses its verification, because verification belongs to an address at a provider. Verify it again with the new provider.
- **Between two live providers**, the change applies at once, including to email already queued.
- **Between a sandbox and a live provider**, the change is refused while the identity has email waiting to send (queued, retrying, or sending) or a launched campaign that hasn't finished. Wait for them to finish, or cancel them, first. This stops test email from being delivered for real, and real email from being captured.
- Every email is fixed to sandbox or live sending the first time the queue processes it. If its sender identity later moves the other way, a retry of that email is refused rather than sent, so a retried test email is never delivered for real.
- A provider can never change between sandbox and live sending. Create a new provider instead.
- A provider whose host doesn't match its type (for example a Mailtrap host on a Generic SMTP provider) is refused when sending, so a sandbox can't pass as live sending.
- Changing a provider's host, or turning its encryption off, requires entering its password or token again, so a saved secret is never sent to a different server or over an unencrypted connection.

To stop a provider temporarily, use **Disable**. Its queued email waits and sends after it is enabled again.

## Queue workers and provider changes

The queue worker reads the provider from the database each time it sends an email: the sender identity's provider, with its current settings and credentials. No worker restart is needed after editing a provider. Email that is already queued uses the new settings, within the sandbox and live rules above.

Each send attempt records the provider it used, and the provider page lists them under **Send attempts**, failures included. **More > Sending history** lists the email the provider accepted or captured.

## Diagnosing errors

| Message | Meaning and fix |
| --- | --- |
| The provider rejected the SMTP credentials (530/534/535) | Wrong login, password, or token. For Mailtrap Email Sending the login must be `api` and the password a token for that domain. The provider is marked *Failing* and the email is retried. |
| Mailtrap rate limit reached (550 5.7.0 "too many emails per second") | The sandbox's per-10-second plan limit was exceeded. The email is retried. Lower **Emails per minute** on the provider. |
| Mailtrap connection limit reached (535 5.7.8 "Too many connections per account") | Mailtrap allows 10 connections per account and per IP. The email is retried. Run fewer queue workers. |
| Connection could not be established | Firewall or wrong port. Cloud hosts often block port 25; use 587 or 2525. |
| Unable to connect with STARTTLS | The server does not offer STARTTLS on that port. Use port 465 with implicit TLS. |
| The SMTP host ... could not be resolved | DNS failure or a typo. The email is retried. |
| ... is a private or loopback address | The host points inside your network. For your own relay or Mailpit, set `PROSEND_SMTP_ALLOW_PRIVATE_HOSTS=true`. The email is retried, so it goes out once the setting is fixed. |
| ... is a link-local, cloud metadata, multicast or reserved address | Always refused, because such an address is never a mail server. |
| The provider ... is a sandbox, and sandbox providers are turned off | Sandbox sending in production. Use a live provider, or set `PROSEND_ALLOW_SANDBOX_PROVIDERS=true` only if you really mean to capture this environment's email. |

A rejected recipient address affects only that email; it does not mark the provider as failing. A provider's health shows the result of the last connection test or send attempt, not a live check.

## Sending limits

Set the limits on each provider to match your plan. ProSend never sends beyond them; email waits for the next window instead.

| Limit | Enforced as |
| --- | --- |
| Emails per day / per month | Counted from email the provider accepted or captured since midnight, or since the 1st of the month. |
| Emails per minute | Per provider, across all queue workers. Bursts within one minute are possible, so stay below hard per-second limits. |

Mailtrap limits, from its documentation: the free Email Sending plan allows 150 emails a day and 4,000 a month; paid plans have no daily cap. New accounts start at 150 emails an hour, which Mailtrap queues rather than rejects. Sandbox limits are per sandbox, per 10 seconds, depending on the plan. Warm-up plans, campaign pacing, and per-recipient-domain limits apply on top of provider limits.

## Delivery statuses

| Status | Meaning |
| --- | --- |
| Accepted by provider | A live provider took the email. Not proof of delivery. Mailtrap email stays here, because its delivery webhooks are not processed yet. |
| Captured in sandbox | A sandbox kept it for inspection. Nobody received it. The time is stored as `captured_at`, never as `accepted_at`. Left out of reports and campaign delivery figures, and not counted as contacting the person. It does count against the sandbox provider's own limits. |
| Delivered, Soft bounce, Hard bounce, Spam complaint | Reported by the provider's delivery webhooks (Brevo only). |
| Retrying, Failed, Status unknown, Skipped, Cancelled | As described in the README. |

The Sending API reports a sandbox email as `captured`.

## How credentials are protected

- SMTP passwords, API tokens, and Brevo API keys are encrypted at rest with `APP_KEY`, hidden from serialization, and never shown in the panel after saving. Leaving the field empty on edit keeps the saved value.
- Only administrators can see, create, edit, test, enable, or disable providers.
- Error messages are stored and shown with the login and password removed.
- Audit log entries record which settings changed, never the secrets themselves.
- Rotating `APP_KEY` makes saved credentials unreadable; re-enter them afterwards.

## Upgrading

- Run `php artisan migrate`. It adds `email_messages.is_sandbox` and `captured_at`, and gives existing Generic SMTP or Brevo providers on a Mailtrap host the matching Mailtrap type, recorded in the audit log as `provider.reclassified`.
- **A provider that points at a private or loopback address stops sending outside `APP_ENV=local`** until `PROSEND_SMTP_ALLOW_PRIVATE_HOSTS=true` is set. Its email is retried on the usual schedule, then marked Failed (retryable). Set the variable before deploying if you use your own relay.
- Restart queue workers (`php artisan queue:restart`) so they load the new code.

## Before production use

- [ ] The Mailtrap sending domain is verified and compliant, and DKIM (`rwmt1`/`rwmt2`) and DMARC are in DNS.
- [ ] Separate providers exist for the transactional stream and the Bulk Stream, if you send marketing email through Mailtrap.
- [ ] The daily, monthly, and per-minute limits match your plan.
- [ ] Each sender identity is verified (**Mark as verified** once Mailtrap shows the domain as verified).
- [ ] `APP_ENV=production` and `PROSEND_ALLOW_SANDBOX_PROVIDERS` unset, so no sandbox can send.
- [ ] `PROSEND_SMTP_ALLOW_PRIVATE_HOSTS` is set only if a provider really is on your own network.
- [ ] **Test connection** and **Send test email** succeed for every live provider.
- [ ] A queue worker runs under a process supervisor.

## Limitations

- Mailtrap's delivery webhooks (delivery, bounce, spam, and others, signed with `Mailtrap-Signature`) are not processed, so Mailtrap email is never marked delivered or bounced. Bounces through Mailtrap therefore do not add addresses to the suppression list automatically.
- Mailtrap's sender and domain verification status is not read from its API; administrators confirm verification manually.
- Amazon SES, SendGrid, Microsoft 365, and Google Workspace work only as generic SMTP, without their webhooks or APIs.
- There is no automatic fallback between providers.
- **Emails per minute** works in one-minute windows, so sandbox plans limited per 10 seconds can still be hit by bursts. Those rejections are retried.

## Sources

- [Mailtrap: SMTP integration](https://docs.mailtrap.io/email-api-smtp/setup/smtp-integration)
- [Mailtrap: Bulk Stream](https://docs.mailtrap.io/email-api-smtp/setup/bulk-stream)
- [Mailtrap: Sending limits](https://docs.mailtrap.io/email-api-smtp/setup/sending-limits)
- [Mailtrap: Sending domain setup](https://docs.mailtrap.io/email-api-smtp/setup/sending-domain)
- [Mailtrap: Webhooks](https://docs.mailtrap.io/email-api-smtp/advanced/webhooks)
- [Mailtrap: Email Sandbox](https://docs.mailtrap.io/email-sandbox) and [Sandbox features and limits](https://docs.mailtrap.io/email-sandbox/help/features-and-limits)

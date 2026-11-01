# Compliance Alerts

Compliance Alerts evaluates the report sources enrolled in its source registry. It prepares memoranda by resolved recipient destination and does not change tracker deadlines or compliance calculations.

## Eligibility and receipt state

Eligibility uses the authoritative deadline and receipt fields from the report source, evaluated on the `Asia/Manila` calendar date. A recorded PENRO receipt closes an overdue-submission alert even when the receipt is late. Due-soon reminders use the configured calendar-day window; Due Today and Overdue remain separate alert buckets.

Submission Tracking is the operational source for report receipt state. Compliance Alerts does not need a second manual Records confirmation or history workflow to decide whether a report has been received. Source-specific missing-MOV obligations can remain separately visible where that tracker requires supporting evidence.

## Recipient resolution

Recipient resolution requires an active exact mapping for the report's identity:

- Protected Area reports use the exact Protected Area mapping.
- Office-scoped Development and ENGP reports use the canonical target-office mapping.

There is no Protected Area-to-office escalation or configured fallback recipient. Inactive mappings do not resolve. A mapping may supply the recipient's TO designation and optional ATTENTION line; the mapping also supplies the email and CC list.

The authenticated production delivery endpoint processes mapped destination groups when its delivery gates permit. Unmapped groups are recorded as skipped; their absence does not cancel other mapped groups. The current web UI does not expose a Send Now action. The production endpoint is not a preview or a diagnostic operation.

## Delivery controls and evidence

Automatic external delivery requires the server delivery gate, the saved Alerts flag, the saved Automatic Email Delivery flag, and valid mail configuration. The defaults are disabled. The settings page reports configuration presence; it does not test SMTP authentication, transport connectivity, message acceptance, or recipient inbox delivery.

The bell notification channel writes database notifications. A successful database notification is not proof of email delivery or inbox receipt.

Manual and automatic production destinations acquire database-backed idempotency claims before mail transport is invoked. Repeated identical manual snapshots skip successful destinations; a partial retry reacquires failed claims. Competing automatic workers cannot both send the same business-date/destination delivery group.

Useful environment settings include:

```env
COMPLIANCE_ALERTS_ENABLED=false
COMPLIANCE_ALERTS_SEND_TIME=08:00
COMPLIANCE_ALERTS_TIMEZONE=Asia/Manila
```

Database settings override safe operational defaults where applicable. The Compliance Alerts timezone is fixed to `Asia/Manila`. The Laravel scheduler reads the configured send time when it starts; restart a long-running scheduler worker after changing that time.

## Schedule and host availability

Laravel registers `compliance:send-overdue-alerts` on weekdays at the configured time in `Asia/Manila`. The configured next-run display follows that weekday schedule. It is a schedule projection, not a guarantee that a host scheduler is running or that an email will be delivered.

The local Windows audit on 2026-11-01 found no registered scheduled task. This is a dated laptop observation; the application does not probe Windows Task Scheduler. Production infrastructure must run `php artisan schedule:run` once per minute or provide an equivalent host scheduler.

The command itself has a separate invocation contract: it does not add a weekend guard. An explicit command invocation can synchronize database bell notifications and process delivery/history, so it must be treated as an operational action.

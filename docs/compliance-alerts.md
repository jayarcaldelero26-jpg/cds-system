# Compliance Alerts

Compliance Alerts scans the enrolled report trackers and prepares one memorandum per resolved destination office. Each memorandum groups the office's overdue reports by Protected Area. It does not change tracker deadlines or compliance calculations.

## Eligibility and Records audit workflow

An active overdue alert means the authoritative source deadline is before today **and** that source's authoritative PENRO receipt/submission field is empty. A populated authoritative receipt immediately removes the report from the Overview, Preview Memorandum, Send Now, and automatic memorandum eligibility—even if the report was late and even if Records has not yet confirmed it.


Submitted reports without a current Records confirmation appear in **History → Pending Records Verification**. Confirmation creates an immutable event with a source snapshot. Revocation creates a second immutable event, preserves the original evidence, and returns the report to Pending Records Verification. It never reopens an overdue-submission memorandum while the authoritative receipt remains populated.

This aligns the memorandum with the boss Apps Script meaning: **report not yet submitted and overdue → notify; report submitted → stop the overdue-submission memorandum**. Laravel Records confirmation remains an additional audit control, not an email-eligibility condition.

## Local operation

Preview current mapped data in CDS at **Compliance Alerts**. Production delivery
is owned by the scheduled `compliance:send-overdue-alerts` command.

## Recipient resolution

Recipients are resolved in this order:

1. Active exact Protected Area mapping
2. Active exact target-office mapping
3. Configured fallback recipient

Groups without a valid mapping are skipped and logged. They remain visible in the dashboard; manual Send Now is blocked until their mapping is supplied. A mapping can also provide a recipient-specific TO title and optional ATTENTION line; these take precedence over the general memorandum settings.

## Safe delivery controls

Automatic external delivery requires all of the following:

- `COMPLIANCE_ALERTS_ENABLED=true`
- Compliance Alert settings: Alerts enabled
- Compliance Alert settings: Automatic send enabled

The default is disabled. Settings do not store SMTP credentials; normal Laravel `MAIL_*` configuration remains environment-only.

Manual and automatic production destinations acquire a database-backed idempotency claim before mail transport is invoked. Repeated identical manual snapshots skip already successful destinations; a partial retry reacquires only failed claims. Automatic workers competing for the same business-date/destination delivery group cannot both send.

Useful environment settings include:

```env
COMPLIANCE_ALERTS_ENABLED=false
COMPLIANCE_ALERTS_SEND_TIME=08:00
COMPLIANCE_ALERTS_TIMEZONE=Asia/Manila
```

Database settings override the safe operational defaults where applicable. Compliance Alerts timezone is fixed to `Asia/Manila` so eligibility, business dates, idempotency, next-run display, and the scheduler agree. The Laravel scheduler reads the configured send time when it starts; restart a long-running scheduler worker after changing it.

When a fallback recipient is configured and its CC list differs from the approved production CC configuration, Settings displays **FALLBACK CC REQUIRES REVIEW**. The warning does not block exact READY mappings and does not overwrite the administrator value.

## Production scheduler

Laravel-side scheduling is registered Monday through Friday at the configured time (default `08:00`) in `Asia/Manila`. The command also safely skips automatic weekend delivery if invoked outside the scheduler. Production infrastructure must run:

```text
php artisan schedule:run
```

once per minute, or provide an equivalent hosting cron/scheduler configuration. This repository does not assume any specific hosting setup.

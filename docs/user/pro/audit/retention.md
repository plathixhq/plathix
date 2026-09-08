# Audit Log Retention

> **This feature requires Plathix PRO.**

## What It Does

Retention controls how long audit log entries are kept before they are automatically deleted. Entries older than the retention period are removed by a daily background job.

## Default

Entries are kept for **90 days** by default.

## How To Change It

1. Go to **Plathix → Settings → Audit**.
2. Set the **Retention period** field (in days).
3. Click **Save changes**.

Setting retention to `0` disables automatic cleanup — entries are kept indefinitely.

## How Cleanup Works

A daily Action Scheduler job (`plathix_audit_cleanup`) runs once per day and deletes all entries where `created_at` is older than `now - retention_days`. Deletion runs in batches to avoid long-running queries on large tables.

If WP-Cron is disabled, the cleanup job does not run until the cron is processed externally.

## Notes

- The `plathix_audit_retention_days` option stores the configured value. Changing it takes effect at the next cleanup run — existing entries older than the new threshold are removed on the next daily job, not immediately.
- The audit table (`wp_plathix_audit_log`) is not removed when Plathix PRO is deactivated. Reactivating PRO will resume recording to the same table.

## Related

- [Log entries](log-entries.md)
- [Health notices](../../dashboard/health-notices.md)

# Filter and Search the Audit Log

> **This feature requires Plathix PRO.**

## How To Filter

On the **Plathix → Audit Log** page, use the filter bar above the log table:

- **Action** — select a specific action type from the dropdown (e.g. `folder_created`, `file_replaced`). The dropdown lists only actions that appear in the current log.
- **Date range** — filter entries to a start date, end date, or both.
- **User** — filter by the WordPress user who performed the action.

Click **Filter** to apply. The table updates to show only matching entries. Click **Reset** to clear all filters and show the full log.

## How It Works

Filters are passed as query parameters to `GET /plathix/v1/audit`. The API queries the `wp_plathix_audit_log` table with `WHERE` clauses on `action`, `created_at`, and `user_id`. The result is paginated.

## Notes

- Filtering by action uses an exact match on the `action` slug — partial matches are not supported.
- The Action dropdown is populated from the distinct actions actually stored in the log for the current site (multisite: current blog only).
- On large sites with many entries, adding a date filter significantly improves query speed due to the `action_created_at` index.

## Related

- [Log entries](log-entries.md)
- [Export](export.md)

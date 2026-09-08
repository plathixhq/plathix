# Health Notices

## What It Does

Displays warnings on the dashboard when Plathix detects a potential configuration issue that may affect how the plugin works.

## Common Notices

| Notice | What It Means |
|---|---|
| WP-Cron disabled | `DISABLE_WP_CRON = true` is set in `wp-config.php`. Background jobs (import, ZIP generation, cleanup) will not run until a real cron is configured. |
| Orphaned files found | A large number of files are not assigned to any folder. Consider organizing them or importing from a previous plugin. |
| Migration plugin detected | A compatible media organization plugin (FileBird, Real Media Library, etc.) is active. An import shortcut is offered. |

## What To Do

Each notice includes a suggested action or a link to the relevant settings. Notices disappear when the underlying issue is resolved (or can be dismissed manually).

## Notes

- Dismissed notices are stored per user. Dismissing a notice on your account does not affect what other admins see.
- Some notices return if the underlying condition reappears (for example, if WP-Cron is re-disabled).

## Related

- [Quick actions](quick-actions.md)
- [Dashboard overview](index.md)
- [Settings](../settings/index.md)

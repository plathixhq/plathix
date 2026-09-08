# ZIP Download Troubleshooting

> **This feature requires Plathix PRO.**

## Common Errors

### "Folder is too large to ZIP"

The folder contains more than 500 files or the total uncompressed size exceeds 2 GB. Split the folder into smaller subfolders and download each separately.

### "Job already queued"

A ZIP job for this folder is already running (HTTP 429). Wait for it to finish and try again.

### "Folder no longer exists"

The folder was deleted between the time you opened the Media Library and the time you clicked Download. Refresh and select a different folder.

### Job stays in "pending" forever

Action Scheduler is not processing jobs. Common causes:

- WP-Cron is disabled (`DISABLE_WP_CRON = true` in `wp-config.php`) and no external cron trigger is configured.
- The server does not allow outgoing HTTP requests to itself (needed for WP-Cron loopback).
- Action Scheduler's runner is blocked by a PHP timeout or memory limit.

Check **Plathix → System Info → Action Scheduler** for job status, or go to **Tools → Scheduled Actions** to inspect the queue directly.

### "Insufficient disk space"

The server does not have enough free space in the temp directory (approximately 1.5× the folder size is required). Free up disk space and try again.

### Download link expired

Temporary download links are single-use and time-limited. If the link has expired, go back to the folder and initiate a new download.

## Related

- [How to download](how-to-download.md)
- [Progress and status](progress.md)
- [System Info](../../tools/system-info.md)
- [Health notices](../../dashboard/health-notices.md)

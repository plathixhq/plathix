# Auto-Cleanup

## What It Does

Automatically and permanently removes folders (and their files) that have been in the Trash longer than the configured retention period.

## How It Works

A scheduled background job runs periodically and checks which trashed folders and files have exceeded the retention threshold. When the threshold is passed, they are permanently deleted without any manual action.

- **Folders** are tracked by the `_plathix_folder_trash_time` metadata set when the folder enters the Trash.
- **Files** (attachments) are tracked by the `_plathix_trash_time` metadata. This overrides WordPress's built-in 30-day auto-delete so that Plathix's own retention setting takes effect instead.

## Configure the Retention Period

Go to **Plathix → Settings → Trash** and set the **Retention period (days)** field. The value must be between 1 and 180. The default is **30 days**.

You can also enable **Delete files with folder** — when checked, permanently deleting a folder also permanently deletes all media files inside it from disk, not just the folder assignment.

## Rules and Limits

- Auto-cleanup requires WP-Cron (or a real system cron) to fire regularly. If WP-Cron is disabled on the site, scheduled cleanup will not run.
- The cleanup job processes both trashed folders and trashed attachments independently.
- Once auto-cleanup runs and removes an item, it cannot be recovered.

## Notes

- Plathix's retention setting replaces WordPress's default 30-day auto-delete for attachments trashed by Plathix (it writes its own timestamp to prevent the native job from firing first).

## Related

- [Move to trash](move-to-trash.md)
- [Restore from trash](restore.md)
- [Permanently delete](permanent-delete.md)
- [Settings](../../settings/index.md)

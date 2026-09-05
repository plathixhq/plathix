# Trash Settings

## What It Does

Configures how long folders stay in the Trash before being permanently deleted by the auto-cleanup job.

## Options

### Retention period (days)

How many days a trashed folder and its files are kept in the Trash before auto-cleanup removes them permanently.

- **Range**: 1–180 days
- **Default**: 30 days

### Delete files with folder

When enabled, permanently deleting a folder from the Trash also permanently removes all media files inside it from the WordPress media library (and from disk).

- **Default**: Disabled (files move to Uncategorized when their folder is permanently deleted)

## Notes

- Increasing the retention period gives you more time to recover accidentally deleted folders.
- The cleanup job runs on a schedule and requires WP-Cron (or a real system cron) to fire.

## Related

- [Folder Trash overview](../folders/trash/index.md)
- [Auto-cleanup](../folders/trash/auto-cleanup.md)
- [Settings overview](index.md)

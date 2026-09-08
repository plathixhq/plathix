# Move a Folder to Trash

## What It Does

Sends a folder to the Trash instead of permanently deleting it. The folder and its contents are temporarily removed from the active tree and can be restored within the retention period.

## How It Works

1. Right-click the folder in the sidebar and choose **Delete**, or select it and click the delete icon in the toolbar.
2. A confirmation dialog appears.
3. Confirm the deletion. The folder moves to the **Trash** system folder in the sidebar.

Files inside the trashed folder move to the WordPress media trash (they become hidden from the active media library) and are associated with the folder in the Trash view.

## Rules and Limits

- Only user-created folders can be trashed. System folders (Uncategorized, Trash itself) cannot.
- Subfolders are trashed recursively along with the parent.
- Requires the `upload_files` capability.
- The folder stays in Trash for the configured retention period (default: 30 days). After that, [auto-cleanup](auto-cleanup.md) permanently removes it.

## What Does Not Happen Automatically

- Files are not deleted from disk when a folder is trashed.
- The folder is not visible in the active tree while in Trash, but it can be [restored](restore.md).

## Related

- [Restore from trash](restore.md)
- [Permanently delete](permanent-delete.md)
- [Auto-cleanup](auto-cleanup.md)

# Restore a Folder from Trash

## What It Does

Moves a trashed folder (and its files) back to the active folder tree, restoring it to its original location.

## How It Works

1. Click **Trash** in the sidebar to open the Trash view.
2. Find the folder you want to restore.
3. Right-click it and choose **Restore**, or click the restore icon next to the folder.
4. The folder reappears in the active tree at its original parent location. If the original parent was also trashed, the folder is restored to the root.
5. Files inside the folder are untrashed from WordPress media trash and become visible in the media library again.

## Rules and Limits

- Restoration is available as long as the folder has not been [permanently deleted](permanent-delete.md) or removed by [auto-cleanup](auto-cleanup.md).
- If the retention period has elapsed and auto-cleanup has already run, the folder is gone and cannot be restored.
- Subfolders are restored recursively along with the parent.
- Requires the `upload_files` capability.

## Notes

- After restoring, the folder appears in its original position in the tree. If siblings were reordered while it was in the Trash, it may appear in a slightly different visual position.

## Related

- [Move to trash](move-to-trash.md)
- [Permanently delete](permanent-delete.md)
- [Auto-cleanup](auto-cleanup.md)

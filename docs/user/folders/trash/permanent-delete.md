# Permanently Delete a Folder

## What It Does

Immediately and irreversibly removes a folder that is currently in the Trash. Does not wait for the auto-cleanup retention period.

## How It Works

1. Click **Trash** in the sidebar to open the Trash view.
2. Find the folder you want to remove permanently.
3. Right-click it and choose **Delete permanently**, or click the permanent-delete icon.
4. A confirmation dialog appears warning that this cannot be undone.
5. Confirm to permanently remove the folder and its files.

## What Gets Deleted

- The folder taxonomy term is removed from the database.
- Files inside the folder that are in the WordPress media trash are permanently deleted from the media library (wp_delete_attachment).
- If the **Delete files with folder** option is enabled in Settings → Trash, all files inside the folder are permanently removed from disk as well.

## Rules and Limits

- Permanent deletion cannot be undone. There is no second trash for trashed-trashed items.
- Requires the `upload_files` capability (or as configured in PRO Access settings).
- Subfolders are permanently deleted recursively.

## Related

- [Restore from trash](restore.md)
- [Auto-cleanup](auto-cleanup.md)
- [Settings](../../settings/index.md)

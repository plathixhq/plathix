# Folder Trash

The Trash is a built-in system folder where deleted folders go before they are permanently removed. It gives you a safety window to recover a folder and its files before they are gone for good.

## How It Works

When you delete a folder, it moves to the Trash instead of being destroyed immediately. The folder and its files stay in the Trash for a configurable number of days (default: 30). After that, the auto-cleanup removes them permanently.

## What You Can Do

- [Move a folder to trash](move-to-trash.md) — delete a folder safely, sending it to the Trash
- [Restore a folder](restore.md) — recover a trashed folder back to its original location
- [Permanently delete](permanent-delete.md) — remove a trashed folder immediately without waiting for auto-cleanup
- [Auto-cleanup](auto-cleanup.md) — how Plathix automatically purges old trashed folders

## Notes

- Files inside a trashed folder are moved to WordPress trash as well (they become inaccessible in the media library until restored).
- Trashing a folder with the "delete files with folder" option enabled also trashes all files inside it permanently on cleanup.
- If the Trash module is deactivated, WordPress's native trash still works — you just lose the folder-level trash view in the Plathix sidebar.

## Related

- [Delete a folder](../delete.md)
- [Settings](../../settings/index.md)

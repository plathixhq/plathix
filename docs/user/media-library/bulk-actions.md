# Bulk Actions

## What It Does

Lets you perform an action on multiple selected files at once instead of acting on them one by one.

## Available Bulk Actions

| Action | Description |
|---|---|
| Move to folder | Move all selected files to a target folder |
| Delete | Send all selected files to the WordPress media trash |

## How It Works

1. [Select the files](selection.md) you want to act on.
2. The bulk action toolbar appears above the grid (or use the **Bulk actions** dropdown in list view).
3. Choose the action:
   - **Move to folder**: a folder picker appears; select the destination folder and confirm.
   - **Delete**: a confirmation dialog appears; confirm to trash all selected files.
4. The grid updates to reflect the changes.

## Notes

- Bulk move reassigns the folder taxonomy for each selected file. It does not move the physical files on disk.
- Bulk delete sends files to the WordPress media trash, not to the Plathix Trash folder. To restore them, use **Media → Library** with the trash filter in standard WordPress.
- Selection is cleared after a bulk action completes.

## Related

- [Select files](selection.md)
- [Delete media](media-delete.md)
- [Move a folder](../folders/move.md)

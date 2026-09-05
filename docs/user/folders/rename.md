# Rename a Folder

## What It Does

Changes the display name of an existing folder. The folder's internal ID and all file assignments remain unchanged.

## How It Works

1. Right-click the folder in the sidebar tree and choose **Rename**, or double-click the folder name.
2. The folder name becomes an editable input field, pre-filled with the current name.
3. Edit the name and press **Enter** (or click the confirm icon) to save.
4. Click outside the input to cancel without saving.

## Rules and Limits

- The new name must be non-empty after trimming whitespace.
- System folders (Uncategorized, Trash) cannot be renamed.
- Requires the `upload_files` capability.

## Errors / Failure Cases

- **Name is empty** — the form does not submit.
- **Structure locked** — a concurrent operation is in progress. Wait a moment and try again.

## Related

- [Create a folder](create.md)
- [Context menu](context-menu.md)

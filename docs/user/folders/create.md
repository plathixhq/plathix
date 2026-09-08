# Create a Folder

## What It Does

Creates a new virtual folder in the sidebar tree. The folder can be placed at the root level or nested inside an existing folder.

## How It Works

1. Open **Media → Library** or the media uploader modal in any editor.
2. In the sidebar, click the **+ Folder** button in the toolbar, or right-click an existing folder and choose **New subfolder**.
3. Type the folder name and press **Enter** (or click the confirm icon).
4. The new folder appears in the tree immediately.

To create a subfolder of a specific folder, right-click that folder and choose **New subfolder**. The new folder form appears with that folder already set as the parent.

## Rules and Limits

- Folder names must be non-empty after trimming whitespace.
- The depth limit depends on the site configuration. By default there is no enforced depth limit. When a limit is set, the **+ Folder** option is hidden for folders that are already at the maximum depth.
- Users must have the `upload_files` capability (Editors and Administrators in a standard WordPress setup). Fine-grained per-role restrictions are available in [Plathix PRO](../pro/access/index.md).
- Clicking outside the name input form cancels the operation without creating a folder.

## Errors / Failure Cases

- **Name is empty** — the form does not submit; type a name first.
- **Structure locked** — the server temporarily locked the folder tree (concurrent operations). Plathix retries automatically after a short delay.

## Related

- [Rename a folder](rename.md)
- [Delete a folder](delete.md)
- [Move a folder](move.md)
- [Toolbar](toolbar.md)

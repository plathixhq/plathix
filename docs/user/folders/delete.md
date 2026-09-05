# Delete a Folder

## What It Does

Removes a folder from the tree. You choose what happens to the files that were assigned to the deleted folder.

## How It Works

1. Right-click the folder in the sidebar and choose **Delete**, or select the folder and click the delete icon in the toolbar.
2. A confirmation dialog appears showing the folder name and how many files it contains.
3. Choose what to do with the files:
   - **Move to parent folder** — files move up to the deleted folder's parent (or to Uncategorized if there is no parent).
   - **Keep in Uncategorized** — files are unassigned and appear in Uncategorized.
4. Confirm to delete the folder.

## Rules and Limits

- Deleting a folder also removes all its subfolders recursively.
- Files are never physically deleted when you delete a folder — only the folder assignment is removed.
- System folders (Uncategorized, Trash root) cannot be deleted.
- Requires the `upload_files` capability.

## What Does Not Happen Automatically

- Physical files on disk are not removed.
- Attachment URLs and IDs are not changed.
- Other content referencing the files (posts, pages, widgets) is not affected.

## Related

- [Move a folder](move.md)
- [Trash](trash/index.md)
- [Uncategorized](uncategorized.md)

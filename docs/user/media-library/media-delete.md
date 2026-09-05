# Delete Media

## What It Does

Removes a file from the media library. By default WordPress sends it to the media trash; permanently deleted files are removed from disk.

## How It Works

**Single file:**

1. In grid view, hover over a file and click the **Delete** icon, or open the file's attachment detail and click Delete.
2. In list view, select the file's checkbox and use the **Bulk actions → Delete** dropdown, or click the **Delete Permanently** link in the row.

**Multiple files:**

See [Bulk actions](bulk-actions.md).

## What Happens

- The file is moved to the WordPress media trash (post status `trash`).
- It is no longer visible in the media library by default.
- Its folder assignment is preserved so if the file is restored from trash it returns to its folder.
- Physical deletion from disk happens only when you empty the WordPress trash or use **Delete Permanently**.

## Rules and Limits

- Deleting a file does not remove references to it from posts, pages, or widgets. Those will show broken images until updated manually.
- Requires the `delete_posts` capability (Editors and Administrators by default).

## Related

- [Bulk actions](bulk-actions.md)
- [Replace media](replace-media.md)

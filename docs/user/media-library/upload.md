# Upload Files

## What It Does

Uploads media files to WordPress and automatically assigns them to the folder currently selected in the Plathix sidebar.

## How It Works

1. In **Media → Library**, select the target folder in the sidebar.
2. Upload files using any standard WordPress method:
   - Drag files from your desktop onto the media grid
   - Click **Add New** in the media library toolbar and choose files
   - Use the **+ Add Media** button inside the post/page editor
3. Uploaded files appear in the selected folder immediately.

If no folder is selected (or "All Files" is selected), uploaded files go to **Uncategorized**.

## Notes

- Folder assignment happens automatically via the `add_attachment` hook — you do not need to move files after uploading.
- The folder selection at the time the upload starts is what determines the destination. If you navigate to a different folder while an upload is in progress, the file may land in the originally selected folder or Uncategorized depending on timing.
- Uploading a local folder from disk (with automatic subfolder structure recreation) is a [Plathix PRO feature](../pro/folder-upload/index.md).

## Rules and Limits

- Standard WordPress file type restrictions apply.
- SVG uploads require explicit enablement — see [SVG Upload](../svg-upload/index.md).
- Requires the `upload_files` capability.

## Related

- [Uncategorized](../folders/uncategorized.md)
- [Grid view](grid-view.md)
- [Folder upload (PRO)](../pro/folder-upload/index.md)
- [SVG Upload](../svg-upload/index.md)

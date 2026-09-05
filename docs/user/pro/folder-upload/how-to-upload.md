# How to Upload a Local Folder

> **This feature requires Plathix PRO.**

## Steps

### Using the toolbar button

1. Open the **Media Library**.
2. Click **Upload folder** in the Plathix sidebar toolbar (folder icon with an up arrow).
3. A file picker opens. Select the local folder you want to upload.
4. Plathix reads the folder's structure, creates matching Plathix folders, then uploads all files.

### Using drag and drop

1. Open the **Media Library**.
2. Drag a folder from your file manager and drop it onto the Plathix sidebar.
3. Plathix detects the folder drop and starts the same process.

## Progress Overlay

During upload, a progress overlay appears with status messages:

| Message | What Is Happening |
|---|---|
| Creating folder structure… | Plathix is creating the taxonomy terms that represent your local subfolders. |
| Uploading files… | Files are being sent to WordPress. |
| Upload complete! | All files are uploaded and assigned to their folders. |
| Upload error | Something went wrong. Check your browser console or server error log for details. |

## Notes

- Files inside the folder are uploaded using the standard WordPress media upload mechanism. They respect the WordPress file type restrictions and per-file size limit (`upload_max_filesize`).
- The upload runs sequentially in the browser, not in bulk. Large folders with many files will take longer.
- If the upload is interrupted (page reload, network error), already-uploaded files and already-created folders remain. Re-uploading will create duplicate folders.

## Related

- [How folder structure is recreated](folder-structure.md)
- [Folder Upload overview](index.md)

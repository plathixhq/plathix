# How Folder Structure Is Recreated

> **This feature requires Plathix PRO.**

## What Plathix Creates

When you upload a local folder, Plathix reads the relative paths of all files inside it and creates a matching tree of Plathix taxonomy terms (folders) before any files are uploaded. The result is a Plathix folder tree that mirrors the local directory layout.

### Example

Local folder on disk:

```
Vacation/
  Landscapes/
    beach.jpg
    mountains.jpg
  People/
    portrait.jpg
```

After upload, Plathix creates:

- Folder: **Vacation**
  - Subfolder: **Landscapes** — contains `beach.jpg`, `mountains.jpg`
  - Subfolder: **People** — contains `portrait.jpg`

## Where Folders Are Created

The new folder tree is created under the currently selected Plathix folder. If no folder is selected (Uncategorized or All Media is active), the tree is created at the root level.

## Rules and Limits

- Folder names are taken from the local directory names. They follow the same naming rules as manually created Plathix folders.
- If a folder with the same name already exists at the same level, Plathix creates a new folder rather than merging into the existing one. Files are not deduplicated.
- Empty local directories (no files in them or their subdirectories) are still created as Plathix folders.
- The folder structure is created synchronously before file uploads begin. If the folder creation succeeds but a file upload fails, the folders remain.

## Notes

- Plathix folders are WordPress taxonomy terms, not physical directories. The created folders behave exactly like folders created manually in the sidebar.

## Related

- [How to upload a folder](how-to-upload.md)
- [Folders overview](../../folders/index.md)

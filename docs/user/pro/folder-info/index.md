# Folder Info (Folder Sizes)

> **This feature requires Plathix PRO.**

## What It Does

Folder Info displays the disk size of each folder directly in the Plathix sidebar. Sizes are loaded on demand and show how much space the files in a folder (and optionally its subfolders) occupy on disk.

## How To Use

1. Open the **Media Library**.
2. Click **Folder sizes** in the Plathix sidebar toolbar (the info-circle icon).
3. Plathix loads the size of each visible folder via the REST API and shows it as a small label next to the folder name.

## What You See

Each folder shows two size values:

- **Own size** — total bytes of files directly in this folder (not its subfolders).
- **Including subfolders** — total bytes of files in this folder and all its descendants combined.

Sizes are shown in human-readable format (KB, MB, GB).

## How It Works

Clicking **Folder sizes** triggers a REST request to `GET /plathix/v1/folders/{id}/size` for each visible folder. The server sums the file sizes by querying the WordPress posts and postmeta tables. Results are cached to avoid repeated database queries on the same page load.

Folder size data is not stored permanently — it is calculated fresh on each request and cached for the duration of the page session.

## Notes

- Folder sizes reflect the size of the original uploaded files. WordPress-generated image thumbnails are not counted.
- If a folder is very large or the server has many files, loading sizes may take a moment.
- Folder sizes are only visible on the Media Library screen (`upload.php`).

## Related

- [Folder Upload overview](../folder-upload/index.md)
- [Folders overview](../../folders/index.md)
- [How Folder Info works](how-it-works.md)

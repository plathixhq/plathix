# How to Download a Folder as ZIP

> **This feature requires Plathix PRO.**

## Steps

1. Open the **Media Library** and select a folder in the Plathix sidebar.
2. Click the **Download as ZIP** button in the folder toolbar or context menu.
3. Plathix queues a ZIP generation job. A progress indicator appears.
4. When the job finishes, the download starts automatically or a **Download** button appears.

## What Happens in the Background

1. A ZIP generation job (`plathix_job_zip_generate`) is dispatched to Action Scheduler.
2. The runner collects all file paths in the folder, copies them to a staging directory, and packs them into a single `.zip` file in the uploads temp area.
3. A temporary download URL is generated. It is single-use and scoped to the requesting user.
4. The browser navigates to the download URL or the link appears in the UI.

## Rules and Limits

- Maximum 500 files per archive.
- Maximum 2 GB total uncompressed size.
- Only one ZIP job per folder can be queued at a time per user. If a job is already queued you receive a `job_already_queued` response; wait for it to finish and try again.
- The download link is temporary and cannot be shared. Another user must initiate their own download.
- Subfolders are not included. Only files directly in the selected folder are archived.

## Related

- [Progress and status](progress.md)
- [Troubleshooting](troubleshooting.md)

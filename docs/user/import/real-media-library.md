# Import from Real Media Library

## What It Does

Imports the folder tree and file assignments from the Real Media Library plugin into Plathix.

## Prerequisites

- Real Media Library must be installed and active.
- Real Media Library's data must be present in the database.

## How It Works

1. Go to **Plathix → Tools → Import**.
2. Find the **Real Media Library** section and click **Import now**.
3. The import runs as a background job. Progress is shown on the Tools page.
4. When complete, your Real Media Library folders and file assignments appear in Plathix.

## What Gets Imported

- All folders and their hierarchy from Real Media Library.
- File-to-folder assignments for all media library files.

## After Import

- Real Media Library can remain active during the transition period.
- When you are ready to switch fully, deactivate Real Media Library.

## Notes

- Import does not modify or delete Real Media Library's data.
- Nested folder hierarchies are preserved during import.
- Import requires WP-Cron or a system cron to process the background job.

## Related

- [Import overview](index.md)
- [FileBird](filebird.md)

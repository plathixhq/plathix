# Import from FileBird

## What It Does

Imports the folder tree and file assignments from the FileBird plugin into Plathix.

## Prerequisites

- FileBird must be installed and active.
- FileBird's data must be present in the database (do not deactivate and delete FileBird before importing).

## How It Works

1. Go to **Plathix → Tools → Import**.
2. Find the **FileBird** section and click **Import now**.
3. The import starts as a background job. Progress is shown on the Tools page.
4. When complete, your FileBird folders and file assignments appear in Plathix.

## What Gets Imported

- All FileBird folders and their hierarchy.
- File-to-folder assignments for all media library files.

## After Import

- FileBird can remain active alongside Plathix during transition.
- When you are ready, deactivate FileBird. Plathix manages the folders from that point.

## Notes

- If a folder name in FileBird already exists in Plathix, a folder with the same name is created (Plathix uses IDs internally, so duplicate names are allowed).
- Import does not delete or modify any FileBird data.

## Related

- [Import overview](index.md)
- [Real Media Library](real-media-library.md)

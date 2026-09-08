# Import & Migration

Plathix can import your existing folder structure from other media organization plugins. The import copies the folder tree and file assignments into Plathix without modifying the source plugin's data.

## Supported Sources

- [FileBird](filebird.md)
- [Real Media Library](real-media-library.md)
- HappyFiles
- WP Media Folder
- Wicked Folders

## How Import Works

1. Install and activate Plathix while the source plugin is still active.
2. Go to **Plathix → Tools → Import**.
3. Choose the source plugin and click **Import**.
4. The import runs as a background job — you can leave the page and come back to check progress.

## What Gets Imported

- The full folder hierarchy from the source plugin.
- File-to-folder assignments (each file is reassigned to its Plathix folder equivalent).

## What Does Not Get Imported

- Settings from the source plugin.
- Custom metadata specific to the source plugin.
- Folder colors or ordering (these start fresh in Plathix).

## Notes

- Import is non-destructive: source plugin data is not modified or deleted.
- Import requires WP-Cron (or a real system cron) to run the background job.
- Running the same import twice creates duplicate folder names (no deduplication). If you need to re-import, consider using [Reset to scratch](../presets/reset.md) first.

## Related

- [Tools](../tools/index.md)
- [Presets](../presets/index.md)

# Import Adapters

## What It Does

Provides one-click import of folder structure and file assignments from other media organization plugins into Plathix.

## Supported Plugins

- FileBird
- Real Media Library
- HappyFiles
- WP Media Folder
- Wicked Folders

Only adapters for currently active plugins are shown on the Tools page.

## How To Use

1. Go to **Plathix → Tools**.
2. Find the import card for your plugin (e.g. **Import from FileBird**).
3. Click **Import now**.
4. The import runs as a background job. Progress is shown in the card.
5. When complete, your folder structure and file assignments appear in Plathix.

## Notes

- Import requires WP-Cron or a real system cron to process the background job.
- Running the import twice creates duplicate folder names — if you need to re-run, consider using Reset to scratch first.
- Source plugin data is never modified or deleted.

## Related

- [Import overview](../import/index.md)
- [FileBird import](../import/filebird.md)
- [Real Media Library import](../import/real-media-library.md)

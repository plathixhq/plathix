# Infinite Scroll

## What It Does

Automatically loads more media files as you scroll down in the media grid, so you do not have to click "Load more" or use pagination manually.

## How It Works

- As you scroll near the bottom of the media grid (within 300 px of the bottom edge), Plathix requests the next page of files from WordPress.
- New files appear at the bottom of the grid without a full page reload.
- Works in both the full Media Library screen (`upload.php`) and the media uploader modal inside editors.

## Rules and Limits

- Infinite scroll applies to the media file grid, not to the folder tree in the sidebar.
- If the current folder has no more files to load, scrolling to the bottom does nothing.
- Infinite scroll can be disabled by site configuration. When disabled, standard WordPress pagination is used instead.

## Notes

- If you select a folder and the folder has fewer files than one page, no additional loading occurs.
- The scroll detection fires near (not exactly at) the bottom, so the last few pixels of the grid may not trigger a load.

## Related

- [Grid view](../media-library/grid-view.md)
- [Navigate the folder tree](navigation.md)

# Media Filters

## What It Does

Filters the media grid to show only files matching specific criteria: a selected folder, a file type (MIME type), or a date range.

## How It Works

**Filter by folder:**

Click a folder in the Plathix sidebar. The grid updates immediately to show only files in that folder. Click **All Files** to clear.

**Filter by file type (MIME type):**

Use the **Filter media** dropdown above the media grid (standard WordPress control). Plathix respects this filter alongside the folder filter — you can combine them (for example, "show only images in the selected folder").

**Filter by date:**

Use the date dropdown above the media grid (standard WordPress control). Works together with folder filtering.

## Combining Filters

Folder filter (from Plathix) and type/date filters (from WordPress) can be combined. The media grid shows only files that match all active filters simultaneously.

## Notes

- These filters apply to the visual media grid. They do not affect how WordPress inserts media into content.
- The folder filter is passed to WordPress as a `plathix_folder` query parameter, so it works the same in grid and list views.

## Related

- [Search folders](search.md)
- [Grid view](../media-library/grid-view.md)
- [List view](../media-library/list-view.md)

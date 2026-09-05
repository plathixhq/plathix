# Media Statistics

## What It Does

Shows a summary of all media files in your WordPress library: total count, breakdown by file type (MIME type), and upload activity over time.

## What You See

- **Total files** — the total number of attachments in the media library, excluding files hidden by page builders (such as Elementor screenshots). Matches what you see in **Media → Library**.
- **By type** — a breakdown of file counts and sizes per MIME type category (Images, Documents, Video, Audio, Other).
- **Upload activity** — a chart showing how many files were uploaded per day or per week. Hover over a point on the chart to see the date and count.

## How Statistics Are Computed

Statistics are collected from the WordPress database and cached for approximately one hour. The cache invalidates automatically when:

- A file is uploaded, deleted, or replaced.
- You open the dashboard (a soft refresh runs in the background).

## Notes

- The "Total files" counter intentionally excludes generator-hidden attachments (for example, page builder screenshot caches). This ensures the dashboard matches the visible media library count.
- Storage size is calculated from attachment file sizes on disk; it may differ from hosting control panel figures.

## Related

- [Folder statistics](folder-stats.md)
- [Dashboard overview](index.md)

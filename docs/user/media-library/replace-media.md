# Replace Media

## What It Does

Swaps the physical file of an existing attachment with a new file, while keeping the same attachment ID, post URL, and all WordPress relationships (posts, pages, widgets, shortcodes) unchanged.

## When To Use It

- Updating a file to a newer version without breaking existing links.
- Fixing a corrupted or low-quality upload.
- Replacing a placeholder image with the final version.

## How It Works

1. Open **Media → Library** and find the file you want to replace.
2. Click the file to open its attachment detail page (or use the list view row link).
3. Find the **Replace media** section and click **Replace file**.
4. Choose the new file from your computer.
5. Confirm the replacement.

Plathix validates the new file, regenerates image metadata and thumbnails, and updates the attachment record. The old physical file is removed from disk after the replacement.

## Result

- The attachment ID stays the same.
- The public URL typically stays the same (same filename) or updates if the filename changes.
- Image thumbnails are regenerated from the new file.
- All WordPress relationships (posts, gallery blocks, widgets) continue to point to the same attachment ID and work without any edits.

## Rules and Limits

- Only one replace per attachment can happen at a time. A lock prevents concurrent replacements of the same file.
- The new file must pass WordPress's allowed file type checks.
- SVG files are sanitized on replace as well.
- If thumbnail generation fails, the replace still succeeds but a **partial success** warning is shown. The main file was replaced; regenerate thumbnails manually via a plugin like "Regenerate Thumbnails."
- Requires `upload_files` capability.

## Errors / Failure Cases

- **File locked** — another replace operation is already in progress for this file. Wait a moment and try again.
- **Invalid file type** — the chosen file type is not allowed by WordPress.
- **Partial success** — the file was replaced but metadata or thumbnail regeneration encountered errors. The warning lists what succeeded and what did not.

## Related

- [Upload files](upload.md)
- [Delete media](media-delete.md)

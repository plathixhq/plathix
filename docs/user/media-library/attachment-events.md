# Attachment Events

## What It Does

Plathix listens to media library interactions (selection changes, file deletion, upload completion) and keeps the sidebar state in sync with the WordPress media frame.

## Why This Matters

WordPress manages media in a Backbone.js "media frame" that fires its own events. Plathix hooks into these events so that:

- The selection counter in the sidebar toolbar updates when you select or deselect files.
- Bulk action buttons appear and disappear at the right time.
- The sidebar folder tree refreshes when you upload, delete, or move a file — so file counts in the tree stay accurate.

## Events Plathix Listens To

| Event | What Plathix Does |
|---|---|
| Click / change / keyup in the media grid | Recounts the current selection and updates the toolbar |
| `wp.media` frame selection add/remove/reset | Recounts the current selection |
| File deleted (attachment delete action) | Refreshes the folder tree to update file counts |
| Upload completed (add_attachment) | Assigns the uploaded file to the selected folder |

## Notes

- These are internal mechanics — you do not interact with attachment events directly.
- If the sidebar appears out of sync after a media action (rare), refreshing the page or clicking the **Refresh** button in the sidebar toolbar resets it.

## Related

- [Selection](selection.md)
- [Bulk actions](bulk-actions.md)
- [Upload files](upload.md)

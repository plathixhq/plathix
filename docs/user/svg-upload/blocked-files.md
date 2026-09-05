# Blocked Files Log

## What It Does

Records SVG files that were rejected during upload because the sanitizer determined they contained unsafe content.

## Where To Find It

The blocked files log appears in the WordPress admin notices area after an SVG upload is rejected. The notice shows the file name and the reason it was blocked.

Site administrators can also filter blocked SVG notices using the `plathix/svg_blocked_notice` filter to route them to a custom log or notification system.

## Common Reasons for Blocking

- The SVG contained `<script>` tags or JavaScript event handlers.
- The SVG referenced external resources (URLs to other servers).
- The file was not a valid SVG (wrong MIME type or malformed XML).
- Safe mode rejected an SVG feature that would be allowed in standard mode.

## What To Do

If a legitimate SVG is blocked:

1. Open the SVG file in a text editor and check for any embedded scripts, event handlers (`onclick`, `onload`, etc.), or external `href` / `xlink:href` references.
2. Remove the offending elements.
3. Try uploading again.

If your workflow genuinely requires advanced SVG features, consider whether disabling safe mode is appropriate for your site.

## Related

- [Safe mode](safe-mode.md)
- [Enable SVG uploads](enable.md)
- [Allowed roles](allowed-roles.md)

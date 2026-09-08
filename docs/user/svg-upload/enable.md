# Enable SVG Uploads

## What It Does

Allows SVG files to be uploaded to the WordPress media library. Disabled by default.

## How To Enable

1. Go to **Plathix → Settings → SVG**.
2. Check the **Enable SVG uploads** checkbox.
3. Click **Save Changes**.

SVG files can now be uploaded by users in the allowed roles.

## How To Disable

Uncheck **Enable SVG uploads** and save. Existing SVG files in the media library are not deleted when you disable SVG support — they simply cannot be uploaded from that point on.

## Notes

- Enabling SVG upload applies the sanitizer to every SVG file at upload time. The sanitized version is what gets stored.
- The original (unsanitized) file is never written to disk.

## Related

- [Allowed roles](allowed-roles.md)
- [Safe mode](safe-mode.md)
- [Settings → SVG](../settings/svg.md)

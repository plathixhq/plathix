# SVG Upload

Plathix adds SVG upload support to WordPress. SVG uploads are **disabled by default** and must be explicitly enabled in settings. When enabled, all SVG files are sanitized before being stored.

## What You Can Do

- [Enable SVG uploads](enable.md) — turn on SVG support
- [Allowed roles](allowed-roles.md) — control which roles can upload SVG files
- [Safe mode](safe-mode.md) — apply stricter sanitization rules
- [Blocked files log](blocked-files.md) — review SVGs that were rejected

## Why SVG Needs Special Handling

SVG files are XML-based and can contain executable JavaScript and external resource references. Without sanitization, an uploaded SVG could be used as a cross-site scripting (XSS) vector. Plathix uses a dedicated SVG sanitization library (`enshrined/svg-sanitize`) to strip dangerous elements and attributes before saving the file.

## Related

- [Settings → SVG](../settings/svg.md)
- [Upload files](../media-library/upload.md)

# Safe Mode

## What It Does

Applies stricter sanitization rules to uploaded SVG files, removing additional SVG features that are not needed for decorative images but can be misused.

## When To Use It

Enable safe mode when:
- Multiple roles are allowed to upload SVGs.
- The site is publicly accessible and you want the most defensive stance.
- You are not sure whether uploaded SVGs will contain advanced features like animations or filters.

## What Safe Mode Removes

Safe mode removes SVG features that are rarely needed for standard site images but can be vectors for content injection or unexpected behavior (such as embedded scripts, external resource links, and certain event handlers). Standard visual SVG content (shapes, paths, text, fills, strokes) remains intact.

## Default

- **Single-site**: disabled by default.
- **Multisite**: enabled by default (more users, higher risk surface).

## How To Configure

1. Go to **Plathix → Settings → SVG**.
2. Check or uncheck **Safe mode**.
3. Click **Save Changes**.

## Notes

- Safe mode applies only to SVG files uploaded after the setting change. Previously uploaded SVGs are not re-sanitized automatically.
- If a valid SVG file is rejected in safe mode, the file will appear in the [blocked files log](blocked-files.md).

## Related

- [Enable SVG uploads](enable.md)
- [Allowed roles](allowed-roles.md)
- [Blocked files log](blocked-files.md)

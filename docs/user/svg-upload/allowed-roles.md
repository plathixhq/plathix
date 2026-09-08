# Allowed Roles for SVG Upload

## What It Does

Restricts SVG uploads to specific WordPress user roles. Users in roles not on the allowed list cannot upload SVG files, even if SVG support is enabled globally.

## Default

By default, only **Administrator** and **Editor** roles are allowed to upload SVG files.

## How To Configure

1. Go to **Plathix → Settings → SVG**.
2. In the **Allowed roles** list, check or uncheck roles.
3. Click **Save Changes**.

## Notes

- Users in unchecked roles receive an error when they attempt to upload an SVG: the file is rejected at the upload stage.
- Super Administrators on multisite networks are always treated as Administrators.
- Adding a less-trusted role (like Contributor or Author) increases risk — make sure you trust those users to upload SVG content.

## Related

- [Enable SVG uploads](enable.md)
- [Safe mode](safe-mode.md)
- [Blocked files log](blocked-files.md)

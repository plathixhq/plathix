# Upload a Custom Preset

## What It Does

Adds your own preset to the Plathix catalog so you can apply your folder structure to this site or share it with others.

## How It Works

1. Go to **Plathix → Presets**.
2. Click **Upload preset**.
3. Select your `.zip` preset file.
4. Plathix validates the archive and adds it to the catalog.

## Preset Package Format

A preset `.zip` file must contain:

- **`preset.plx.md`** — the folder structure definition in Plathix's preset format (required).
- **`preview.webp`**, `preview.png`, `preview.jpg`, or `preview.jpeg` — a preview image shown in the catalog (optional).

The description file may start with `FormatVersion: 2`. The field is optional —
a file without it is read as the current format version. Metadata fields can
appear in any order, and fields Plathix does not recognise are ignored, so a
preset written for a newer release still works as long as its format version is
one this plugin understands.

## Limits

| Item | Limit |
|---|---|
| Maximum archive size | 1 MB |
| Maximum preview image size | 300 KB |
| Allowed preview formats | webp, png, jpg, jpeg |

## Errors / Failure Cases

- **Archive too large** — the `.zip` exceeds 1 MB. Reduce the archive size (typically by using a smaller preview image).
- **Missing preset.plx.md** — the archive does not contain a `preset.plx.md` file.
- **Invalid preset.plx.md** — the structure definition is malformed; check the format.
- **Invalid preview format** — only webp, png, jpg, jpeg are allowed.

## Related

- [Apply a preset](apply.md)
- [Browse the catalog](browse-catalog.md)
- [Delete a preset](delete.md)

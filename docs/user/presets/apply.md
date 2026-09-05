# Apply a Preset

## What It Does

Creates the folder structure defined in a preset inside your media library. Existing folders remain unless you choose to reset first.

## How It Works

1. Go to **Plathix → Presets**.
2. Browse the available presets in the catalog.
3. Click **Apply** on the preset you want.
4. Optionally check **Start from scratch** to delete all existing user-created folders before applying.
5. Confirm. Plathix creates all folders from the preset structure.

The sidebar tree updates automatically after the preset is applied.

## What Happens to Existing Folders

- Without **Start from scratch**: existing folders stay. If a preset folder name conflicts with an existing one, a numeric suffix is added (e.g. `Photos` → `Photos (2)`).
- With **Start from scratch**: all user-created folders are deleted first. Files are moved to Uncategorized before the deletion. Then the preset structure is created fresh.

## Rules and Limits

- Only presets with a `valid` status can be applied.
- Applying a preset does not move existing files into the new folders — it only creates the folder structure.
- Requires `upload_files` capability.

## Related

- [Browse the catalog](browse-catalog.md)
- [Reset to scratch](reset.md)
- [Upload a custom preset](upload-custom.md)

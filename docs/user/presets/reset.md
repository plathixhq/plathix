# Reset to Scratch

## What It Does

Deletes all user-created folders in the media library, leaving only the system folders (Uncategorized, Trash). Files are moved to Uncategorized before their folders are removed.

## When To Use It

- Before applying a new preset that should replace your entire folder structure.
- When you want to start organizing from scratch.
- During setup when an existing structure no longer makes sense.

## How It Works

1. Go to **Plathix → Presets**.
2. Click **Start from scratch** (or check the **Start from scratch** option when applying a preset).
3. A confirmation dialog warns that all user-created folders will be deleted.
4. Confirm. Plathix removes all user-created folders. Files move to Uncategorized.

## What Gets Deleted

- All user-created folder taxonomy terms.
- All folder color assignments.
- All folder position/ordering data.

## What Does Not Get Deleted

- Media files — they are moved to Uncategorized, not deleted.
- System folders (Uncategorized, Trash) — these are protected and always remain.
- Favorites — folder favorites are cleared because the folders no longer exist.

## Rules and Limits

- This action cannot be undone. There is no recovery for deleted folder structure.
- Requires `upload_files` capability.
- If the reset is triggered as part of applying a preset, the preset structure is created immediately after the reset.

## Related

- [Apply a preset](apply.md)
- [Uncategorized](../folders/uncategorized.md)

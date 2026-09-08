# Folder Field in Quick Edit

> **This feature requires Plathix PRO.**

## What It Does

When enabled, a **Folder** dropdown appears inside the WordPress Quick Edit row on list screens for any enabled post type. This lets you change a post's folder assignment without opening the full editor.

## How To Enable

1. Go to **Plathix → Settings → General**.
2. Find **Folder field in Quick Edit** and toggle it on.
3. Click **Save changes**.

## How To Use

1. Go to a post-type list screen (e.g. **Posts → All Posts**).
2. Hover over a post row and click **Quick Edit**.
3. Select a folder from the **Folder** dropdown.
4. Click **Update**.

## How It Works

The Quick Edit field is added via `quick_edit_custom_box`. Saving runs the same `save_post` hook that the meta box uses, so the assignment is stored identically.

## Rules and Limits

- Quick Edit is disabled by default. It must be enabled in Settings.
- The feature applies to all post types enabled in Content Types, including the Media Library list view.
- The folder dropdown in Quick Edit shows the same tree as the meta box.

## Related

- [Attachment Meta overview](index.md)
- [Metabox](metabox.md)

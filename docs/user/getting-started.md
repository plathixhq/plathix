# Getting Started with Plathix

## What It Does

Plathix adds a virtual folder tree to your WordPress media library. Folders are built on native WordPress taxonomies — no custom database tables, no proprietary data formats. You can organize media into folders, move files between them, apply preset folder structures, replace existing files, and more.

## Installation

1. Go to **Plugins → Add New** in your WordPress admin.
2. Search for **Plathix** and click **Install Now**.
3. Click **Activate**.

Plathix adds a **Plathix** menu item to the left-hand admin navigation.

## First Run (Free)

After activation, open **Plathix → Home**. If you have no folders yet, a setup wizard appears. You can:

- **Apply a preset** — pick a ready-made folder structure from the built-in catalog and apply it with one click.
- **Upload a custom preset** — upload your own `.zip` preset package.
- **Start from scratch** — skip the wizard and build your own folder structure manually.

The wizard appears only once, until you have at least one folder. You can dismiss it and return to **Plathix → Presets** at any time to apply or manage presets.

## How Folders Work

Folders in Plathix are **virtual** — they are WordPress taxonomy terms, not physical directories on disk. Assigning a file to a folder stores a taxonomy relationship in the database. The physical file location under `wp-content/uploads/` does not change.

This means:

- Folders survive media library rebuilds and URL changes.
- No lock-in: the underlying data is standard WP taxonomy; removing the plugin leaves the taxonomy terms in place (not removed unless you run the uninstall cleanup).
- External URLs and attachment IDs stay the same when you move a file between folders.

## The Sidebar

The folder sidebar appears in:

- **Media → Library** (grid and list views)
- Any page that opens the standard WordPress media uploader modal (e.g. post editor, page editor, theme customizer)

Use the sidebar to browse folders, upload directly into a folder, move files, and filter what you see.

## Free vs PRO

Plathix Free covers media organization: folders, presets, import, replace, SVG upload, favorites, and a developer-facing REST/CLI surface.

**Plathix PRO** is a separate plugin that activates on top of Free and adds:

- Gallery shortcode and Gutenberg block
- ZIP download of folder contents
- Local folder upload with subfolder recreation
- Folder size display
- Audit log (who did what and when)
- Fine-grained per-role and per-user access policies
- Folders for posts, pages, and custom post types
- Folder assignment in Quick Edit and the post editor sidebar
- First-run setup wizard (multi-step)
- REST authentication via API keys
- Advanced WP-CLI commands

See the [PRO overview](pro/index.md) for details.

## Related

- [Folders](folders/index.md)
- [Presets](presets/index.md)
- [Media Library](media-library/index.md)
- [Settings](settings/index.md)

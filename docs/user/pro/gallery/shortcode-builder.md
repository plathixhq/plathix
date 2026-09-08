# Shortcode Builder

> **This feature requires Plathix PRO.**

## What It Does

The shortcode builder is a visual GUI that lets you configure `[plathix_gallery]` attributes without writing shortcode markup by hand. It runs inside the WordPress Media Library admin panel and saves shortcodes to a Plathix-managed list.

## How To Open It

1. Go to **Media → Plathix** (or open the Media Library).
2. Click **Create shortcode** in the sidebar toolbar.
3. The builder modal opens.

## How To Use It

The builder is organized into tabs. Each tab controls a group of attributes:

- **Preset** — choose a layout (grid / masonry / justified / documents) and configure columns, height, and spacing.
- **Source** — pick folders to include and toggle `include_children`.
- **Image** — select WordPress image size, enable `no_crop`, and set `link_to`.
- **Caption** — enable captions and set their style.
- **Lightbox** — enable lightbox and its sub-options (caption, zoom, thumbnails, full screen).
- **Documents** — configure icons, download buttons, and file size display (only relevant for the documents preset).
- **Advanced** — set `max`, `order_by`, and `order`.

When you are satisfied, click **Save shortcode**. The builder saves the shortcode record via the REST API and copies the ready-to-paste shortcode string to your clipboard.

## Editing an Existing Shortcode

1. Go to **Media → Shortcodes** to see the list of saved shortcodes.
2. Click the shortcode name or the **Edit** action.
3. The builder reopens in edit mode with the saved attributes pre-filled.
4. Make your changes and click **Save shortcode**.

## Rules and Limits

- The builder is only available on the Media Library admin screen (`upload.php`) and the Shortcodes list page.
- Saving requires the REST API to be functional and the user to have `upload_files` capability.
- The builder can be disabled site-wide via the `plathixpro/gallery/shortcode_builder_enabled` filter.

## Related

- [Shortcode reference](shortcode.md)
- [Gutenberg block](gutenberg-block.md)

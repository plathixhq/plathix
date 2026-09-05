# Gallery Gutenberg Block

> **This feature requires Plathix PRO.**

## What It Does

The `plathix/gallery` block is a native Gutenberg block that renders the same gallery as the `[plathix_gallery]` shortcode. It is server-rendered on save — the block editor stores your attribute choices, and PHP renders the final HTML each time the page loads.

## How To Insert It

1. Open the Gutenberg block editor on any post or page.
2. Click **+** to add a block.
3. Search for **Plathix Gallery**.
4. Click the block to insert it.

## Configuring the Block

The block's sidebar inspector panel exposes the same attributes as the shortcode:

- **Folders** — select one or more Plathix folders.
- **Layout preset** — grid, masonry, justified, or documents.
- **Columns**, **Image size**, **Max items**, **Order**.
- **Lightbox** — toggle and sub-options.
- **Caption**, **Link to**, **No crop**.
- **Documents extras** — icon, download button, file size.

All attribute defaults match the shortcode defaults (see [Shortcode reference](shortcode.md)).

## How Rendering Works

The block uses `render_callback` — PHP renders it at request time. This means:

- Folder contents are always up to date when the page loads.
- The block preview in the editor shows a live server-side preview.
- Lightbox assets (`plathix-lightbox` script and style) are enqueued on the page automatically when `lightbox` is enabled.

## Notes

- The block and the shortcode produce identical HTML output; they share the same `GalleryQueryBuilder → GalleryItemsProvider → GalleryRenderer` pipeline.
- You can freely mix shortcodes and blocks on the same page.

## Related

- [Shortcode reference](shortcode.md)
- [Shortcode builder](shortcode-builder.md)
- [Lightbox](lightbox.md)

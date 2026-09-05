# Gallery Layouts

> **This feature requires Plathix PRO.**

## What It Does

The `preset` attribute controls the overall visual layout of the gallery. Each preset applies a CSS class to the gallery container, and the bundled stylesheet handles the rest.

## Available Presets

### `grid` (default)

Fixed-width columns. Each cell is the same size. Ideal for photos where consistent framing matters.

```
[plathix_gallery preset="grid" columns="3"]
```

### `masonry`

Pinterest-style layout. Items fill columns top to bottom in variable-height rows. Ideal for photos with mixed aspect ratios.

```
[plathix_gallery preset="masonry" columns="4"]
```

### `justified`

All items in a row share the same height, and rows expand to fill the full container width. Set `height` to control the target row height in pixels.

```
[plathix_gallery preset="justified" height="200"]
```

### `documents`

A list-style layout optimized for files rather than images. Each row shows a file icon, file name, optional file size, and an optional download button. Images are shown with their thumbnail; other file types use a MIME-type icon.

```
[plathix_gallery preset="documents" show_icon="true" show_download="true"]
```

## Common Options

All presets share these layout controls:

| Attribute | What it does |
|---|---|
| `columns` | Number of columns (or `auto` for CSS-driven). Applies to all presets including documents. |
| `spacing` | Gap between items in pixels. |
| `no_crop` | Disable cover-crop on thumbnails (not applicable to documents). |
| `image_size` | WordPress image size used for thumbnails. |

## Notes

- The CSS class added to the gallery wrapper is `plathix-gallery--{preset}` (e.g. `plathix-gallery--masonry`).
- `height` has no effect on presets other than `justified`.
- `no_crop` has no effect on the `documents` preset.

## Related

- [Shortcode reference](shortcode.md)
- [Sorting and filtering](sorting-filtering.md)

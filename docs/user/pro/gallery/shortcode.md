# Gallery Shortcode

> **This feature requires Plathix PRO.**

## What It Does

`[plathix_gallery]` renders a responsive image/file gallery on any page or post. All attributes are optional — the shortcode works with no attributes and shows all media in the order they were uploaded.

## Attributes

### Source

| Attribute | Default | Description |
|---|---|---|
| `folders` | *(empty)* | Comma-separated folder IDs. Only files in those folders are shown. |
| `attachment_ids` | *(empty)* | Comma-separated attachment IDs. When set, `folders` is ignored. |
| `include_children` | `false` | Also include files from subfolders of the listed folders. |

If `folders` lists an ID that does not exist or has been deleted, the gallery renders empty (not all media). This is intentional to prevent accidental data exposure after folder deletion.

### Layout

| Attribute | Default | Description |
|---|---|---|
| `preset` | `grid` | Layout preset: `grid`, `masonry`, `justified`, or `documents`. |
| `columns` | `3` | Number of columns, `1`–`6`. The `justified` preset ignores this and lays out rows automatically. |
| `height` | *(empty)* | Row height in pixels for `justified` preset. |
| `spacing` | `4px` | Gap between items. Leaving it empty gives `4px`, not zero. |
| `image_size` | `medium` | WordPress image size used for thumbnails. |
| `no_crop` | `false` | Disable cropping; images fill their cell without cover-crop. |

### Content

| Attribute | Default | Description |
|---|---|---|
| `max` | `-1` | Maximum number of items. `-1` means no limit. |
| `order_by` | `date` | Sort field: `date`, `title`, `modified`, `rand`, `menu_order`, `ID`, or `post__in` (keeps the order of `attachment_ids`). |
| `order` | `DESC` | Sort direction: `ASC` or `DESC`. |
| `caption` | `false` | Show the attachment caption below the image. |
| `link_to` | `none` | Wrap image in a link: `none`, `media` (file URL), or `attachment` (attachment page). |

### Documents preset extras

| Attribute | Default | Description |
|---|---|---|
| `show_icon` | `true` | Show a file-type icon next to the file name. |
| `icon_size` | `medium` | Icon size: `small`, `medium`, `large`, or `xlarge`. |
| `show_size` | `false` | Show the human-readable file size. |
| `show_download` | `true` | Show a download button. The link is always a direct file URL. |

### Lightbox

| Attribute | Default | Description |
|---|---|---|
| `lightbox` | `false` | Enable the PhotoSwipe lightbox. |
| `lightbox_caption` | `false` | Show the image caption inside the lightbox. |
| `lightbox_zoom` | `false` | Show the zoom button in the lightbox toolbar. |
| `lightbox_thumbs` | `false` | Show a thumbnail strip at the bottom of the lightbox. |
| `lightbox_full` | `false` | Show a "full screen" button in the lightbox toolbar. |

## Example

```
[plathix_gallery folders="12,15" columns="4" preset="masonry" lightbox="true"]
```

Shows files from folders 12 and 15 in a 4-column masonry layout with lightbox enabled.

## Rules and Limits

Some attributes are overridden by the chosen `preset`. The preset always wins — if you set
one of these explicitly and the preset disagrees, your value is ignored:

| Preset | What it overrides |
|---|---|
| `justified` | `columns` is forced to `auto`; `no_crop` is forced to `true` |
| `masonry` | `no_crop` is forced to `true` |
| `documents` | `caption` and all `lightbox*` attributes are forced to `false`; `link_to` defaults to `media` unless you set it explicitly |

### Short forms

These older names still work and are kept for compatibility. Each maps to the canonical
attribute; if you write both, the canonical one wins.

| Short form | Canonical | Note |
|---|---|---|
| `folder` | `folders` | |
| `folder_id` | `folders` | Ignored when `folders` or `folder` is present |
| `size` | `image_size` | |
| `limit` | `max` | |
| `link` | `link_to` | `link="file"` means `link_to="media"`; other values pass through |

Other limits:

- `columns` accepts `1`–`6`. Values outside that range are clamped, not rejected — `columns="12"` renders as `6`.
- `height` only applies to the `justified` preset.
- `caption` outputs the WordPress attachment caption (`post_excerpt`).
- Invalid values fall back to the default instead of failing: an unknown `preset` renders as `grid`, an unknown `order_by` as `date`, an unknown `link_to` as `none`, an unknown `icon_size` as `medium`.

## Related

- [Shortcode builder](shortcode-builder.md)
- [Layouts](layouts.md)
- [Lightbox](lightbox.md)
- [Sorting and filtering](sorting-filtering.md)

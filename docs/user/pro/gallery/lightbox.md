# Lightbox

> **This feature requires Plathix PRO.**

## What It Does

When `lightbox="true"` is set, clicking an image in the gallery opens it full-screen using PhotoSwipe. The viewer supports keyboard navigation, touch swipe, and optional zoom, thumbnail strip, and download.

## How To Enable

```
[plathix_gallery folders="5" lightbox="true"]
```

The lightbox JavaScript and CSS (`plathix-lightbox`) are enqueued automatically on any page or post that contains a gallery shortcode or block with `lightbox` enabled. Assets are not loaded on pages without a gallery.

## Lightbox Options

All options default to `false`:

| Attribute | What it does |
|---|---|
| `lightbox_caption` | Show the image caption inside the lightbox overlay. |
| `lightbox_zoom` | Add a zoom in/out button to the lightbox toolbar. |
| `lightbox_thumbs` | Show a horizontal thumbnail strip at the bottom of the lightbox. |
| `lightbox_full` | Add a full-screen toggle button to the lightbox toolbar. |

Example with all options:

```
[plathix_gallery folders="5" lightbox="true" lightbox_caption="true" lightbox_zoom="true" lightbox_thumbs="true" lightbox_full="true"]
```

## How It Works

Each image item receives a `data-plathix-lightbox="1"` attribute plus `data-pswp-width` and `data-pswp-height` attributes that tell PhotoSwipe the full image dimensions. The lightbox JavaScript reads these and opens the correct source URL.

The z-index of the lightbox overlay defaults to `160001` and can be changed with the `plathix/z_index_lightbox` filter.

## Rules and Limits

- The lightbox only activates on image items. Video items use inline playback regardless of this setting (see [Video embeds](video-embed.md)).
- When `lightbox="true"`, the `link_to` attribute is ignored for image items — clicking always opens the lightbox.
- The lightbox asset is detected in content at `wp_enqueue_scripts` time using `has_shortcode()` / `has_block()`. Galleries injected dynamically via JavaScript after page load must manually call `LightboxAssets::request()` (developer use only).

## Related

- [Shortcode reference](shortcode.md)
- [Layouts](layouts.md)
- [Video embeds](video-embed.md)

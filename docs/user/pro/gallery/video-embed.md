# Video Embed in Gallery

> **This feature requires Plathix PRO.**

## What It Does

When a folder or attachment list contains video files, the gallery renders them as native HTML5 `<video>` elements inline, alongside images. No third-party embed code is required.

## How It Works

The gallery detects videos by MIME type: any attachment whose MIME type starts with `video/` is treated as a video item. Supported types include `video/mp4`, `video/ogg`, `video/webm`, and any other format the visitor's browser supports.

Each video item renders as:

```html
<video controls preload="metadata" poster="..."><source src="..." type="video/mp4" /></video>
```

If the video has a WordPress-generated thumbnail, it is used as the `poster` image. Otherwise no poster is shown.

## Rules and Limits

- Videos play inline — there is no full-screen lightbox mode for video items. The `lightbox` attribute has no effect on video items.
- The `link_to` attribute is also ignored for video items (the `<video>` element is not wrapped in a link).
- The `no_crop` and `image_size` attributes have no effect on video rendering.
- Actual playback depends on browser codec support. For widest compatibility, upload MP4 files encoded with H.264.
- Video files are served directly from the WordPress upload directory — no transcoding is done by Plathix.

## Notes

- In the `documents` preset, video files are shown like other file types (icon + name + optional download), not as inline players.
- The `show_download` attribute applies to video items in the `documents` preset just as for any other file type.

## Related

- [Shortcode reference](shortcode.md)
- [Lightbox](lightbox.md)
- [Layouts](layouts.md)

# Gallery Sorting and Filtering

> **This feature requires Plathix PRO.**

## What It Does

The gallery provides attributes to control which files are shown, how many, and in what order.

## Source Filtering

### By folder

```
[plathix_gallery folders="12,15"]
```

Shows files from folders 12 and 15 only. Separate multiple IDs with commas.

```
[plathix_gallery folders="12" include_children="true"]
```

Also includes files from all subfolders of folder 12.

### By attachment ID

```
[plathix_gallery attachment_ids="101,205,312"]
```

Shows exactly those three attachments, in the order listed. When `attachment_ids` is set, `folders` is ignored.

### Unresolved folders

If a folder ID in `folders` does not exist (deleted or mistyped), the gallery renders empty — it does not fall back to showing all media. This prevents accidental data exposure.

## Limiting Results

```
[plathix_gallery folders="12" max="20"]
```

Show at most 20 items. Default is `-1` (no limit). The limit is applied after the SQL query, so combining `max` with `order_by` lets you show the newest N files.

## Sorting

| Attribute | Values | Default |
|---|---|---|
| `order_by` | `date`, `title`, `modified`, `rand`, `menu_order` | `date` |
| `order` | `ASC`, `DESC` | `DESC` |

Example — alphabetical order:

```
[plathix_gallery folders="12" order_by="title" order="ASC"]
```

Example — random order:

```
[plathix_gallery folders="12" order_by="rand"]
```

`rand` produces a different order on each page load. It is not cached between requests.

## Notes

- `menu_order` reflects the order set in the WordPress media modal (drag-to-reorder inside the media library).
- Sorting applies to all presets including `documents`.

## Related

- [Shortcode reference](shortcode.md)
- [Layouts](layouts.md)
- [Folders overview](../../folders/index.md)

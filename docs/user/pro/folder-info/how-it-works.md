# How Folder Info Works

> **This feature requires Plathix PRO.**

## REST Endpoint

Folder sizes are served by `GET /plathix/v1/folders/{id}/size`. The response contains:

```json
{
  "bytes": 1048576,
  "bytes_children": 5242880
}
```

| Field | Meaning |
|---|---|
| `bytes` | Total size of files directly in this folder (not subfolders), in bytes. |
| `bytes_children` | Total size of files in this folder's subfolders only (not counting the folder itself). |

The UI sums the two values to display the "including subfolders" figure.

## Calculation

The server calculates sizes by summing the `_wp_attached_file` postmeta values for all attachments assigned to the folder's taxonomy term. The sum queries direct file sizes — WordPress-generated thumbnail files are not included.

Two methods are used:

- `FolderSizeCalculator::sum_bytes($folder_id, $taxonomy)` — direct files only.
- `FolderSizeCalculator::sum_bytes_recursive_children($folder_id, $taxonomy)` — all descendant folders.

## Caching

Results are cached in the WordPress object cache for the duration of the page session. The same folder requested twice in one page load returns the cached value without a second database query.

The cache is not persisted between page loads (no transient storage). Refreshing the page recalculates all sizes.

## Authorization

The endpoint requires the requesting user to have at least **Upload** access level in Plathix and a valid Plathix PRO license. Unauthorized requests receive a `403` response.

## Related

- [Folder Info overview](index.md)

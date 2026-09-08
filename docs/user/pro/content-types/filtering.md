# Filtering Posts by Folder

> **This feature requires Plathix PRO.**

## How To Filter

1. Go to the list screen for an enabled post type (e.g. **Posts → All Posts**).
2. Click a folder in the Plathix sidebar.
3. The list table refreshes to show only posts assigned to that folder.

Clicking the folder again or clicking **All** at the top of the sidebar clears the filter and shows all posts.

## How It Works

Selecting a folder adds a `plathix_folder` query parameter to the list URL. The Free core registers a `parse_query` subscriber that translates this parameter into a `tax_query` for the Plathix taxonomy of the current post type. WordPress handles the rest — the standard list table, pagination, and search all continue to work.

## Combining with Other Filters

Folder filtering is compatible with:

- The WordPress search box.
- The status filter (All / Published / Draft / etc.).
- Date-based filters.
- Any other query filters registered by your theme or plugins.

## Notes

- Clicking a folder filters to files **directly in that folder** — subfolders are not included by default. There is no recursive filter option for post-type lists.
- If you select a folder and then use the WordPress search box, the search applies within that folder.

## Related

- [Sidebar on post lists](sidebar-on-posts.md)
- [Content Types overview](index.md)

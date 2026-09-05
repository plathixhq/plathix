# Search Folders

## What It Does

Filters the folder tree in the sidebar to show only folders whose names match a search query. Helps you quickly find a specific folder in a large tree.

## How It Works

1. Click the search icon in the sidebar or start typing in the folder search input.
2. The tree filters in real time as you type — only folders with names containing the query are shown (case-insensitive).
3. Parent folders of matching folders are also shown so you can see where the match lives in the hierarchy.
4. Clear the search input to return to the full tree.

## Rules and Limits

- Search filters the folder tree only, not the media files in the grid.
- To search media file names, use the [Media Library search](search.md).
- Matching is client-side (no server request) — the full tree must already be loaded for search to work.
- If the tree has thousands of folders and is loaded in pages, only already-loaded folders are searched.

## Notes

- Searching does not change the currently selected folder. Files in the grid continue to show the previously selected folder's contents.
- To filter media by folder, click the folder in the tree after finding it.

## Related

- [Navigate the folder tree](navigation.md)
- [Search & Filters](../search-filters/index.md)

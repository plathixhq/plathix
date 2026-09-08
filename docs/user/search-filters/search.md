# Search Folders

## What It Does

Filters the sidebar folder tree in real time as you type, showing only folders whose names contain the search query.

## How It Works

1. Click the search icon or the search input at the top of the sidebar.
2. Start typing a folder name.
3. The tree updates to show only matching folders and their parent folders (so you can see where each match is located).
4. Clear the input or press **Escape** to return to the full tree.

## Performance Note

On sites with more than 500 folders, search runs only on the typed query (not on every keystroke) to avoid performance issues. On smaller sites, filtering is instant.

## Rules and Limits

- Search filters the folder tree only — it does not search media file names.
- Search is case-insensitive.
- Only already-loaded folders are searched. If the tree loads in batches for very large sites, only the loaded batch is searched.
- Searching does not change the currently selected folder or the media grid contents.

## Related

- [Navigate the folder tree](../folders/navigation.md)
- [Filters](filters.md)

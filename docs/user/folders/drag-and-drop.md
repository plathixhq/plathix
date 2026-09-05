# Drag and Drop

## What It Does

Lets you move folders within the tree and move media files into folders using mouse drag and drop.

## Two Drag-and-Drop Modes

### 1. Move media files into a folder

Drag one or more files from the media grid onto a folder in the sidebar.

1. In the media grid, click a file to select it (hold Shift or Ctrl/Cmd to select multiple).
2. Drag the selected file(s) onto a folder name in the sidebar.
3. The folder highlights when it is a valid drop target.
4. Release to move the files into that folder.

The files' attachment IDs, URLs, and all WordPress relationships remain unchanged — only the folder assignment changes.

### 2. Reorder folders in the tree

Drag a folder by its name to a new position in the sidebar tree.

1. Click and hold a folder name — a drag handle appears.
2. Drag the folder:
   - **onto another folder** to nest it as a child
   - **between two siblings** (look for the horizontal drop indicator line) to reorder it
3. Release to drop.

## Rules and Limits

- Files dragged from outside WordPress (from your desktop or file manager) are uploaded and placed in the currently selected folder — not moved between existing folders. Local folder uploads with automatic subfolder recreation are a [PRO feature](../pro/folder-upload/index.md).
- System folders (Uncategorized, Trash) cannot be dragged.
- A folder cannot be dropped into itself or its own descendants.

## Related

- [Move a folder](move.md)
- [Select files](../media-library/selection.md)
- [Upload files](../media-library/upload.md)
- [Folder upload (PRO)](../pro/folder-upload/index.md)

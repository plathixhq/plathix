# Move a Folder

## What It Does

Changes a folder's position in the tree — either to a different parent (nested under another folder) or to a different position among its siblings.

## How It Works

**Drag and drop** is the primary way to move folders. See [Drag and drop](drag-and-drop.md) for details.

You can also use the context menu or toolbar on sites that expose explicit move controls, but drag and drop is the fastest method.

## How Reordering Works

- Dragging a folder onto another folder makes it a child of that folder.
- Dragging a folder between two siblings positions it before or after them (a drop indicator line shows the target position).
- Position values are integer weights stored in the database; Plathix calculates a midpoint between the neighbors when inserting.

## Rules and Limits

- A folder cannot be moved into itself or into one of its own descendants.
- If the site has a depth limit configured, you cannot move a folder deeper than that limit.
- On concurrent edits, Plathix detects a lock conflict and retries the move automatically after a short delay.
- Requires the `upload_files` capability.

## Related

- [Drag and drop](drag-and-drop.md)
- [Create a folder](create.md)

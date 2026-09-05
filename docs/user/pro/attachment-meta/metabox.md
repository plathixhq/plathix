# Folder Meta Box in the Post Editor

> **This feature requires Plathix PRO.**

## What It Does

A **Folder** panel appears in the right sidebar of the WordPress post editor for any post type that is enabled in [Content Types](../content-types/index.md). It lets you select which Plathix folder the post belongs to without leaving the editor.

## How To Use

1. Open a post (or page or CPT item) in the WordPress editor.
2. Find the **Folder** panel in the right sidebar.
3. Select a folder from the dropdown.
4. Save or update the post.

The folder assignment is saved when the post is saved. Changing the folder and updating the post moves the assignment to the new folder.

## Rules and Limits

- The meta box only appears for post types that are enabled in Content Types. Attachment post types never get the meta box (they use the Media Library sidebar).
- The meta box is not shown to users who have **None** access level in Plathix.
- The folder dropdown shows only the folder taxonomy for the post's post type. Posts and media files share folder names but belong to separate taxonomies.

## Related

- [Attachment Meta overview](index.md)
- [Quick Edit](quick-edit.md)
- [Content Types](../content-types/index.md)

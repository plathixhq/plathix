# Plathix Sidebar on Post Lists

> **This feature requires Plathix PRO.**

## What It Does

When a post type is enabled in Content Types, the Plathix sidebar appears on that post type's list screen (e.g. **Posts → All Posts**, **Pages**, or any CPT list). You can click a folder to filter the list to posts assigned to that folder.

## How It Looks

The sidebar is identical to the Media Library sidebar — the same folder tree, toolbar, and search. The difference is that the list table shows posts/pages instead of media files.

## Assigning a Post to a Folder

- From the list screen: drag a post row onto a folder in the sidebar.
- From the Quick Edit panel: use the **Folder** field (if the Attachment Meta module is active — see [Attachment Meta](../attachment-meta/index.md)).
- From the post editor: use the **Folder** panel in the sidebar (if Attachment Meta is active).

## Notes

- The sidebar appears on `edit.php` screens for the enabled post type. It does not appear on the single post editor (`post.php`) without the Attachment Meta module.
- The folder tree for posts and the folder tree for media are separate — assigning a post to folder "Blog" and a media file to folder "Blog" are independent assignments on different taxonomies.
- All folder operations (create, rename, delete, move) available in the Media Library are also available on post-type list screens.

## Related

- [Content Types overview](index.md)
- [Filtering posts by folder](filtering.md)
- [Attachment Meta](../attachment-meta/index.md)

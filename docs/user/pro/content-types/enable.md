# Enable Post Types

> **This feature requires Plathix PRO.**

## How To Enable a Post Type

1. Go to **Plathix → Settings → General**.
2. Find the **Enabled Sections** panel.
3. The panel shows checkboxes for:
   - **Media Library** (attachment) — always enabled, cannot be unchecked.
   - **Posts** — WordPress built-in `post` type.
   - **Pages** — WordPress built-in `page` type.
   - Any additional post types registered by themes or plugins that have `show_ui = true`.
4. Check the post types you want Plathix to manage folders for.
5. Click **Save changes**.

## What Happens After Enabling

Once a post type is enabled:

- A **Folder** column appears in its admin list table.
- The Plathix sidebar appears on its list screen (e.g. `edit.php?post_type=product`).
- A dedicated taxonomy (`plathix_{post_type}` or a registered name) is created for the post type's folder terms.
- The Plathix REST API and folder operations work for that post type.

## Notes

- Only post types with `show_ui = true` appear in the list. Hidden post types cannot be enabled.
- Enabling a post type does not affect existing posts — their folder assignment starts empty (Uncategorized) until you assign them.
- Disabling a post type removes the sidebar and column from the UI but does not delete existing folder assignments.

## Related

- [Content Types overview](index.md)
- [Sidebar on post lists](sidebar-on-posts.md)

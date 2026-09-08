# Access & Roles

## Free Version

In the Free version, Plathix uses a single capability check:

- Users with the **`upload_files`** capability can create, rename, move, and delete folders, and can upload and move media files.
- In a standard WordPress installation, **Editors** and **Administrators** have `upload_files`. Authors have it too (limited to their own uploads). Subscribers and Contributors do not.

There is no per-role configuration in the Free version — the capability check is binary.

## PRO Version

Plathix PRO adds the **[Access Control](../pro/access/index.md)** module with a per-role permission matrix. This lets you configure independently for each role:

- Which roles can **view** folders
- Which roles can **upload** into folders
- Which roles can **edit** folders (rename, set colors)
- Which roles can **delete** folders

Individual users can also have their access level overridden separately from their role.

## Notes

- The Settings → Access & Roles tab in the Free version shows the current effective capability (`upload_files`) and links to the PRO Access module for advanced configuration.
- Changing the basic capability requires code — it cannot be configured through the UI in Free.

## Related

- [Access Control (PRO)](../pro/access/index.md)
- [Settings overview](index.md)

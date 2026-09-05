# Access Control

> **This feature requires Plathix PRO.**

## What It Does

Access Control lets you set per-role and per-user Plathix permissions. Without PRO, every user with `upload_files` capability gets the same Upload access and every user with `manage_options` gets Full access. PRO lets you override this for any role or individual user.

## Access Levels

| Level | What the user can do |
|---|---|
| **Full** | Create, rename, move, delete, and manage folders; upload and manage files. |
| **Upload** | Upload files and assign them to existing folders. Cannot create or delete folders. |
| **None** | Cannot access the Plathix sidebar or any Plathix features in the Media Library. |

## Default Behavior Without PRO

| WordPress capability | Default Plathix level |
|---|---|
| `manage_options` (Administrator) | Full |
| `upload_files` (Editor, Author, etc.) | Upload |
| Neither | None |

## What PRO Adds

PRO registers a filter subscriber (`RolePolicy`) at priority 5 on `plathix/access_level`. It applies two overrides on top of the Free default:

1. **Per-user override** — `plathix_user_access` user meta (set in the user profile).
2. **Per-role map** — `plathix_role_access` option (set in Plathix Settings → Access).

Per-user overrides always take priority over the role map.

## What You Can Do

- [Role access matrix](role-matrix.md) — set a Plathix access level for each WordPress role
- [Per-user override](per-user-override.md) — override the access level for a specific user

## Notes

- Access Control requires a valid Plathix PRO license. Without a valid license the PRO policy is not applied and users fall back to the Free capability defaults.
- Roles not listed in the role map also fall back to the Free capability default.

## Related

- [Settings — Access](../../settings/access-roles.md)
- [PRO overview](../index.md)

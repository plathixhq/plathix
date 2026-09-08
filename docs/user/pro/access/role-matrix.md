# Role Access Matrix

> **This feature requires Plathix PRO.**

## What It Does

The role access matrix lets you assign a Plathix access level (Full / Upload / None) to each WordPress role. This replaces the Free default that ties access to `manage_options` and `upload_files` capabilities.

## How To Configure

1. Go to **Plathix → Settings → Access**.
2. The role matrix lists all registered WordPress roles.
3. For each role, select **Full**, **Upload**, or **None**.
4. Click **Save changes**.

## Default Role Map

When no custom map has been saved, Plathix PRO ships with these defaults:

| Role | Default level |
|---|---|
| Administrator | Full |
| Editor | Upload |
| Author | Upload |
| Contributor | None |
| Subscriber | None |

Custom roles added by themes or plugins are not in the default map. They fall back to the Free capability check (`manage_options` → Full, `upload_files` → Upload, otherwise None) until you explicitly assign them in the matrix.

## How It Works

The map is stored as the `plathix_role_access` WordPress option. On every Plathix request the `RolePolicy` filter reads the map and returns the matching level for the user's first matching role. If the user has multiple roles, the first role that appears in the user's roles array wins.

## Rules and Limits

- Per-user overrides (see [Per-user override](per-user-override.md)) take priority over the role map.
- Setting a role to **None** blocks that role from accessing Plathix even if the WordPress capability would normally grant access.
- Setting a role to **Full** grants folder management to that role even if `manage_options` is not set.

## Related

- [Access overview](index.md)
- [Per-user override](per-user-override.md)

# Per-User Access Override

> **This feature requires Plathix PRO.**

## What It Does

You can set a Plathix access level on an individual user that overrides both the role map and the Free capability default. Useful when one person on a team needs different permissions than their role normally provides.

## How To Set It

1. Go to **Users → All Users** and click a user's name to open their profile.
2. Find the **Plathix Access** section.
3. Choose **Full**, **Upload**, or **None**.
4. Click **Update User**.

Selecting the blank/default option removes the override and reverts the user to their role-based access level.

## How It Works

The override is stored as `plathix_user_access` user meta. When Plathix resolves access for a request, the per-user meta is checked first. If it contains a valid level, that level is used immediately — the role map is not consulted.

## Rules and Limits

- Requires a valid Plathix PRO license. Without it, per-user meta is stored but not applied.
- The override applies to the Plathix sidebar and all Plathix REST endpoints — it is not a WordPress capability change.
- Setting a user to **None** blocks them from Plathix even if their role grants access.
- Setting a user to **Full** does not grant `manage_options` or any other WordPress capability.

## Related

- [Access overview](index.md)
- [Role access matrix](role-matrix.md)

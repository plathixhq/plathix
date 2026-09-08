# API Key Scopes (Access Levels)

> **This feature requires Plathix PRO.**

## Available Levels

| Level | What It Can Do |
|---|---|
| `view` | Read-only access. List folders, query media, retrieve folder sizes. Cannot create, move, or delete. |
| `upload` | All `view` permissions plus: upload files, move files between folders. Cannot create or delete folders. |
| `full` | All `upload` permissions plus: create, rename, move, and delete folders. |

## How Levels Work

Service token access is enforced at the same layer as user access: the `plathix/access_level` filter. Token authentication runs at priority 10, higher than the role-policy filter (priority 5), so the token level is always the final effective level for service token requests — the role map does not apply.

## Post Type Scope

Each token is also scoped to a specific WordPress post type. The default is `attachment` (Media Library). If you set the scope to a different post type (e.g. `post`), the token only authenticates for operations on that post type's folder taxonomy.

Requests to a different post type's folder endpoints from a token not scoped to it are rejected.

## Notes

- `view` is the default level and the most restrictive — use it for read-only integrations.
- Tokens do not inherit WordPress user capabilities beyond what the access level explicitly grants.
- Setting a token to `full` gives it the same power as a user with Full access in Plathix — use with care.

## Related

- [Create an API key](create.md)
- [Access Control](../access/index.md)
- [API Keys overview](index.md)

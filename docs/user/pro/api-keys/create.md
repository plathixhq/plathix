# Create an API Key

> **This feature requires Plathix PRO.**

## How To Create

1. Go to **Plathix → Tools**.
2. Find the **API Keys** card.
3. Fill in the form:
   - **Label** — a human-readable name for this token (e.g. "CI Pipeline", "Headless Frontend").
   - **Access level** — what the token can do (see [Scopes](scopes.md)).
   - **Post type** — which post type the token is scoped to (default: `attachment` = Media Library).
   - **Expires in (days)** — leave blank or set to `0` for a non-expiring token; set a positive number to create a token with an expiry date.
4. Click **Generate**.

## After Creation

The token value is shown **once** immediately after generation. Copy it now — it cannot be retrieved again. Only a hash is stored in the database.

## What Is Stored

Each token record contains:

| Field | Description |
|---|---|
| `token_id` | Unique identifier for this token record. |
| `label` | Human-readable name you assigned. |
| `access_level` | `view`, `upload`, or `full`. |
| `post_type` | The post type scope (e.g. `attachment`). |
| `expires_at` | ISO date if expiring; empty if permanent. |

## Using the Token

Include the token in the `X-Plathix-Key` HTTP header on every REST request:

```
GET /wp-json/plathix/v1/folders
X-Plathix-Key: plx_sk_••••••••••••••••••••••••••
```

## Related

- [Scopes](scopes.md)
- [Revoke](revoke.md)
- [API Keys overview](index.md)

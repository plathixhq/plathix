# API Keys

> **This feature requires Plathix PRO.**

## What It Does

API Keys (service tokens) let external applications and scripts authenticate with the Plathix REST API using a secret token instead of WordPress session cookies. This is useful for headless integrations, CI/CD pipelines, or any automated process that needs to interact with media folders.

## How It Works

You create a service token in the Plathix Tools page. The token is sent in every REST request via the `X-Plathix-Key` HTTP header. Plathix authenticates the token and grants the configured access level to the request.

## What You Can Do

- [Create an API key](create.md) — generate a new service token
- [Revoke an API key](revoke.md) — permanently delete a token
- [Scopes](scopes.md) — what each access level can do

## Where to Manage Them

**Plathix → Tools → API Keys** (the API Keys card appears on the Tools page when PRO is active).

## Notes

- Tokens are shown only once, immediately after creation. Store them securely — they cannot be retrieved again.
- Service tokens authenticate as the WordPress Administrator account. The access level of the token caps what operations are permitted.

## Related

- [Tools overview](../../tools/index.md)
- [PRO overview](../index.md)

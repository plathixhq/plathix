# Revoke an API Key

> **This feature requires Plathix PRO.**

## When To Revoke

Revoke a token when:

- It is no longer needed.
- You suspect it has been leaked or misused.
- You want to rotate credentials for security.

## How To Revoke

1. Go to **Plathix → Tools → API Keys**.
2. Find the token in the list.
3. Click **Revoke** next to the token.
4. Confirm the action.

The token is deleted immediately. Any subsequent request that includes the revoked token receives a `401 Unauthorized` response.

## Notes

- Revocation is permanent and cannot be undone.
- Revoking a token does not affect any data it created or modified — folders and files it managed remain unchanged.
- If you need to replace a token, create a new one first, update your integrations to use the new token, then revoke the old one.

## Related

- [Create an API key](create.md)
- [API Keys overview](index.md)

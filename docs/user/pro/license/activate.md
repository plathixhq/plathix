# Activate Your License

> **This feature requires Plathix PRO.**

## Prerequisites

- Plathix (free plugin) installed and active.
- Plathix PRO plugin installed and active.
- A valid license key from [plathix.com](https://plathix.com).

## How To Activate

1. Go to **Plathix → License**.
2. Enter your license key in the **License Key** field.
3. Click **Activate**.

Plathix contacts the LemonSqueezy licensing API to validate the key. If the key is valid, the license status changes to **Active** and all PRO features become available immediately.

## What Is Saved

On successful activation, Plathix saves:

- `plathix_license_status` = `active`
- `plathix_license_expires` — the subscription expiry date.
- `plathix_license_instance` — the activation instance ID (used for deactivation).

The key itself is stored in `plathix_license_key`.

## Errors

| Error | Cause |
|---|---|
| **Invalid license key** | The key does not exist, has been revoked, or reached its activation limit. |
| **Network error** | The licensing server was not reachable. Check your server's outbound HTTP access and try again. |

## Notes

- Each license key has an activation limit (number of sites it can be activated on). Deactivate from a site before moving to a new one to keep activations available.

## Related

- [Deactivate](deactivate.md)
- [License overview](index.md)

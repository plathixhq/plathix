# License

> **This feature requires Plathix PRO.**

## What It Does

The License module manages your Plathix PRO license key. It activates and validates your license with the LemonSqueezy licensing service and controls access to all PRO features. Without a valid license, PRO modules fall back to Free behavior.

## What You Can Do

- [Activate your license](activate.md) — enter your license key and enable PRO features
- [Deactivate your license](deactivate.md) — unlink the key from this site
- [Grace period](grace-period.md) — what happens when the license server is temporarily unreachable
- [Auto-updates](auto-updates.md) — how Plathix PRO updates itself when a valid license is active

## Where to Manage It

**Plathix → License** in the WordPress admin menu.

## License Status

| Status | Meaning |
|---|---|
| **Active** | License is valid. All PRO features are enabled. |
| **Expired** | Subscription has ended. PRO features are disabled. |
| **Invalid** | License key is not valid or has been revoked. |
| **Network error** | Could not reach the license server. PRO features remain active for a grace period. |

## Related

- [PRO overview](../index.md)
- [plathix.com](https://plathix.com) — purchase a license

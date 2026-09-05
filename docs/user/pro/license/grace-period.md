# License Grace Period

> **This feature requires Plathix PRO.**

## What It Is

If Plathix cannot reach the LemonSqueezy licensing server during a revalidation check, it does not immediately disable PRO features. Instead, it enters a **grace period** of 3 days.

During the grace period, all PRO features continue to work as if the license is active. This prevents service interruption due to temporary network issues or licensing server downtime.

## How It Works

1. Plathix periodically revalidates the license in the background (via WordPress cron).
2. If the licensing server returns a network error (not an explicit "invalid" response), Plathix records the start of the grace period in `plathix_license_grace_since`.
3. Each subsequent failed revalidation check compares the current time to the grace start.
4. If the grace period expires (3 days of consecutive network failures), PRO features are disabled.
5. If revalidation succeeds at any point during the grace period, the grace counter resets and the `grace_since` marker is removed.

## What Does Not Trigger a Grace Period

The grace period only applies to network errors. If LemonSqueezy explicitly responds that the license is **invalid**, **expired**, or **revoked**, PRO features are disabled immediately — no grace period is given.

## Notes

- The grace period protects against licensing server downtime, not against expired subscriptions.
- If your subscription has lapsed and LemonSqueezy reports it as expired, PRO is disabled immediately.

## Related

- [License overview](index.md)
- [Activate](activate.md)

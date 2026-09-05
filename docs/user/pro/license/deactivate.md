# Deactivate Your License

> **This feature requires Plathix PRO.**

## When To Deactivate

Deactivate your license before:

- Moving the site to a new domain.
- Uninstalling Plathix PRO.
- Freeing up an activation slot to use the key on a different site.

## How To Deactivate

1. Go to **Plathix → License**.
2. Click **Deactivate**.

Plathix contacts the LemonSqueezy API to release the activation instance. The license status is removed locally and PRO features are disabled.

## What Happens Locally

After deactivation:

- `plathix_license_status` is deleted (PRO features disabled).
- `plathix_license_instance` is deleted.
- `plathix_license_expires` is deleted.

The `plathix_license_key` option is also cleared so the field appears empty.

## Notes

- Deactivation is best-effort — if the licensing server is unreachable, the local status is still cleared and the activation slot is released the next time LemonSqueezy syncs.
- Deactivating does not delete any folders, files, or other Plathix data.
- After deactivation, you can reactivate on the same or a different site.

## Related

- [Activate](activate.md)
- [License overview](index.md)

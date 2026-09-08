# Auto-Updates

> **This feature requires Plathix PRO.**

## What It Does

When your Plathix PRO license is active, Plathix PRO receives plugin updates through the standard WordPress update mechanism. New versions appear in **Dashboard → Updates** and can be applied with one click, just like any other plugin.

## How It Works

The `Updater` module hooks into `pre_set_site_transient_update_plugins` and checks for new versions against a Plathix update manifest. If a newer version is available and the license is valid, it injects the update data into WordPress's update transient.

- If the license is **valid**, the update check runs and new versions are offered.
- If the license is **invalid or expired**, no updates are offered.

## Auto-Update Eligibility

Plathix PRO respects WordPress's built-in auto-update setting. If you have enabled automatic updates for plugins in **Dashboard → Updates**, Plathix PRO will auto-update when a new version is available, provided the license is active.

## Notes

- Updates are fetched from the Plathix update manifest. The server must be able to make outbound HTTPS requests.
- If the license expires, the plugin remains installed and functional at the current version — only new updates stop being offered.
- Downgrading to an older version requires manual installation.

## Related

- [License overview](index.md)
- [Grace period](grace-period.md)

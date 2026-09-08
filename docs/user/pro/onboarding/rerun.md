# Rerun the Onboarding Wizard

> **This feature requires Plathix PRO.**

## How To Rerun the Wizard

The wizard reappears on the Plathix dashboard after its state is reset. There is no UI button for this — reset must be done via WP-CLI or custom code.

### Via WP-CLI

```bash
wp option delete plathix_preset_onboarding
```

### Via code (e.g. in a custom plugin or `wp-config.php`)

```php
\Plathix\Modules\Preset\PresetOnboarding::reset();
```

After the reset, open **Plathix → Dashboard** and the wizard appears again.

## What Reset Does

Deleting the `plathix_preset_onboarding` option removes the "completed" or "skipped" marker. The next time an Administrator opens the Plathix dashboard, the wizard is shown again from step 1.

Resetting does not undo anything the wizard previously saved — your content types, role access settings, and applied preset folder structure remain in place.

## Notes

- Resetting the wizard state does not reset your folder structure. To clear folders, use [Reset structure](../../presets/reset.md).
- Only Administrators see the wizard. Resetting on a shared site shows it to all Administrators the next time they open the dashboard.

## Related

- [Wizard steps](wizard-steps.md)
- [Onboarding overview](index.md)
- [Reset folder structure](../../presets/reset.md)

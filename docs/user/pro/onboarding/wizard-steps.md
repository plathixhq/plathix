# Wizard Steps

> **This feature requires Plathix PRO.**

## Step 1 — Content Types

Choose which post types Plathix should manage folders for.

- **Media Library** is always enabled and cannot be unchecked.
- **Posts** and **Pages** are enabled by default on a fresh PRO installation.
- Any custom post types registered with `show_ui = true` appear as additional options.

Saving this step updates the `plathix_post_types` option.

## Step 2 — Access

Configure who can use Plathix and how.

- **Role access matrix** — set a Plathix access level (Full / Upload / None) for each WordPress role.
- **Folder field in Quick Edit** — toggle the folder dropdown in the Quick Edit row on list screens.

Saving this step updates `plathix_role_access` and `plathix_quick_edit`.

## Step 3 — Structure

Choose how to set up your initial folder structure.

- **Apply a preset** — select one of the built-in or custom presets and Plathix creates the folder tree automatically.
- **Start from scratch** — skip preset application and begin with an empty folder tree.

Completing this step marks the wizard as done (sets `plathix_preset_onboarding` to `completed` or `skipped`). The wizard does not appear again until you reset it.

## Skip

Clicking **Skip** at any step closes the wizard without saving the current step's data and marks it as skipped. Previously saved steps (1 and 2 if you skipped on step 3) remain saved.

## Related

- [Onboarding overview](index.md)
- [Rerun the wizard](rerun.md)

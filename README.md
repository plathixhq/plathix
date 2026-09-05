# Plathix

Organize your WordPress Media Library with folders, ready-made folder sets, replace media,
SVG handling, and Trash.

> PHP 8.1+ · WordPress 7.0+ · GPLv3 or later

## What It Is

Plathix adds a virtual folder system to the WordPress Media Library. This free version
organizes the Media Library only — it does not organize posts, pages, or custom post types
(that, along with per-role access control, a gallery builder, an audit log, and WP-CLI, is
part of the PRO add-on, not this repository).

## Main Capabilities

- Virtual folders for the Media Library: nested folders, drag and drop, bulk move, upload
  directly into a folder
- Ready-made folder sets for common site types (blogs, agencies, stores, newsrooms,
  photographers, restaurants, nonprofits, property listings) via first-run onboarding
- Trash: deleted folders and files wait in Trash and can be restored before removal
- Replace media: update a file in place while keeping its attachment ID
- SVG upload policy: allow with sanitization, block site-wide, or leave to another plugin;
  per-role upload control
- Export/import a folder tree to reuse it on another site
- Import adapters for other media-folder plugins
- Multilingual folder names (any language, emoji, special characters)
- No tracking by default — no telemetry or analytics

## Admin Workspace

| Page | Purpose |
|---|---|
| Media Library | Folder panel in the sidebar, drag and drop, bulk actions |
| Presets | Ready-made folder sets, search, apply, first-run onboarding |
| Settings | SVG policy, role access to SVG uploads, advanced options |
| Tools | Import adapters for other media-folder plugins |
| System Info | Environment, plugin state, support diagnostics |

See [docs/user/](./docs/user/) for the admin pages and presets user guides (when present in this repository).

## Presets And Onboarding

- Built-in presets are stored under `assets/presets/`
- Presets are discovered when the Presets page or onboarding flow opens
- Users can upload custom preset ZIP packages containing `preset.plx.md` and a preview image
- First-run onboarding can guide the initial folder setup
- Users can reopen preset-driven setup later
- "Start from scratch" intentionally resets the current folder tree before rebuilding

## Import Tools

Plathix can import folder structures from other media-folder plugins through the Tools page.

## SVG Upload

SVG support is disabled by default.

When enabled:

- allow SVG uploads with sanitization, or block SVG uploads site-wide, or leave SVG
  handling to another plugin
- files that do not pass sanitization are rejected
- you choose which roles may upload SVG files

## Data Model

Plathix uses a WordPress taxonomy for folder relationships.

| What | Where |
|---|---|
| Media folders | `plathix_folder` taxonomy |
| Folder positions | term meta |
| Folder colors | term meta |
| Settings | `wp_options` with `plathix_*` keys |
| Per-user overrides | `wp_usermeta` |
| Preset catalog | plugin data + preset storage |

This keeps the core folder model aligned with standard WordPress storage instead of custom folder tables.

## Requirements

| Requirement | Minimum |
|---|---|
| PHP | 8.1 |
| WordPress | 7.0 |
| Browser | modern admin browser |

Operationally recommended:

- WP-Cron or real cron for background jobs
- object cache for larger sites
- PHP zip extension for ZIP and preset export flows

## Installation

Code built from this repository is not recommended for use in a production environment —
see [CONTRIBUTING.md](./CONTRIBUTING.md). For production use, install a tagged release zip
from [GitHub Releases](https://github.com/plathixhq/plathix/releases).

## FAQ

### Can I organize posts, pages, or custom post types with this free version?

No. This free version organizes the WordPress Media Library only.

### Does deactivating the plugin delete folders?

No. Deactivation preserves data. Uninstall is the destructive path.

### Are `spec/` files part of the plugin runtime?

No — and this repository does not contain a `spec/` directory. It is local workflow/
documentation space used during development and is not shipped as part of the plugin.

## License

Plathix is licensed under [GPLv3 or later](https://www.gnu.org/licenses/gpl-3.0.html).

## Contributing

See [CONTRIBUTING.md](./CONTRIBUTING.md).

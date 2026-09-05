# Plathix PRO

> **This section describes Plathix PRO features. A separate PRO plugin and a valid license are required.**

## What It Is

Plathix PRO is a paid add-on plugin that installs alongside the free Plathix plugin. It adds premium modules on top of the free core. The free plugin must be active for PRO to work.

## How PRO Connects to Free

PRO uses the `plathix/modules/register` hook to register its own modules alongside Free's modules. PRO modules follow the same two-phase bootstrap and autonomy rules as Free modules. Activating PRO without Free installed results in a no-op (PRO checks for Free and silently does nothing).

## What PRO Adds

| Module | What It Does |
|---|---|
| [Gallery](gallery/index.md) | `[plathix_gallery]` shortcode, Gutenberg block, PhotoSwipe lightbox |
| [Access Control](access/index.md) | Per-role and per-user folder access policy |
| [ZIP Download](zip-download/index.md) | Download a folder's contents as a ZIP archive |
| [Folder Upload](folder-upload/index.md) | Upload a local folder with automatic subfolder recreation |
| [Folder Info](folder-info/index.md) | Folder size display in the sidebar |
| [Audit Log](audit/index.md) | Who did what and when in the media library |
| [Content Types](content-types/index.md) | Folders for posts, pages, and custom post types |
| [Attachment Meta](attachment-meta/index.md) | Folder assignment in Quick Edit and the post editor |
| [Onboarding](onboarding/index.md) | Multi-step first-run setup wizard |
| [License](license/index.md) | LemonSqueezy-based license activation |
| [API Keys](api-keys/index.md) | REST authentication via service tokens |

## Requirements

- WordPress 6.0+
- PHP 8.1+
- Plathix (free plugin) installed and active
- An active Plathix PRO license

## Get PRO

Visit [plathix.com](https://plathix.com) to purchase a license and download the PRO plugin.

## Related

- [License activation](license/activate.md)
- [Getting Started](../getting-started.md)

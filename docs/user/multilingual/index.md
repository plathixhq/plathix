# Multilingual Support

Plathix works out of the box with WPML and Polylang. No additional configuration is needed.

## How It Works

WPML and Polylang by default make custom taxonomies translatable — each language would get its own copy of the folder terms. Plathix overrides this behavior to keep folders **shared across all languages**.

This means:
- A folder you create is visible in all site languages.
- Moving a file into a folder in English also moves it in French, German, or any other active language.
- There is no per-language folder tree.

## What You Can Do

- [WPML compatibility](wpml.md) — how Plathix integrates with WPML
- [Polylang compatibility](polylang.md) — how Plathix integrates with Polylang

## Notes

- Plathix's folder sharing is automatic and transparent — no setup required.
- Media files themselves may still be language-specific (depending on your WPML/Polylang configuration), but the folder structure is always shared.

## Related

- [Folders overview](../folders/index.md)

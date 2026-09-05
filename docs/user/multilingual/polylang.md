# Polylang Compatibility

## What It Does

Plathix automatically integrates with Polylang so that folder taxonomies are shared across all languages.

## How It Works

When Polylang is active, Plathix hooks into the `pll_is_translated_taxonomy` filter and returns `false` for all Plathix taxonomies. Polylang then treats folders as a shared (non-translated) vocabulary.

Plathix also passes `lang=all` to the media grid AJAX query when filtering by a Plathix folder, so Polylang does not filter out files from other languages.

## Setup

No configuration is needed. Install Plathix and Polylang, activate both, and they work together automatically.

## Known Behavior

- Folders are always shown in all languages and cannot be made language-specific.
- Files in a selected folder appear regardless of which language is currently active in Polylang.

## Related

- [Multilingual overview](index.md)
- [WPML](wpml.md)

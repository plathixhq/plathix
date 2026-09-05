# WPML Compatibility

## What It Does

Plathix automatically integrates with WPML so that folder taxonomies are treated as non-translatable (shared across all languages).

## How It Works

When WPML is active, Plathix hooks into the `wpml_is_translated_taxonomy` filter and returns `false` for all Plathix taxonomies. WPML then treats folders as a single shared vocabulary rather than creating per-language copies.

Additionally, Plathix disables WPML's language filter for the media grid AJAX query when a folder is selected, so files in the selected folder are shown regardless of the language being browsed.

## Setup

No configuration is needed. Install Plathix and WPML, activate both, and they work together automatically.

## Known Behavior

- Folders are always displayed in all languages — you cannot have a folder that is visible only in one language.
- If WPML is configured to separate media by language, a file assigned to a folder will still appear in all languages when that folder is selected in Plathix.

## Related

- [Multilingual overview](index.md)
- [Polylang](polylang.md)

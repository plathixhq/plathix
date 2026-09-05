# Content Types

> **This feature requires Plathix PRO.**

## What It Does

By default, Plathix folders work with the **Media Library** (attachments) only. The Content Types module extends Plathix to also manage folders for **Posts**, **Pages**, and any registered **Custom Post Types** (CPT). Each enabled post type gets its own folder sidebar on its admin list screen.

## What You Can Do

- [Enable post types](enable.md) — select which post types use Plathix folders
- [Sidebar on post lists](sidebar-on-posts.md) — how the Plathix sidebar works on non-media screens
- [Filtering posts by folder](filtering.md) — how to filter posts using Plathix folders

## Default Behavior After Activating PRO

On a fresh PRO installation, **attachment**, **post**, and **page** are enabled by default. You can change this in **Plathix → Settings → General → Enabled Sections**.

## How It Works

The module registers a filter on `plathix/enabled_post_types` that adds the selected post types (read from the `plathix_post_types` option) to the list that the Free core processes. The Free core's folder column, sidebar, REST routes, and taxonomy registrations are all type-agnostic — once a type is in the enabled list, all of those features activate for it automatically without any new code in PRO.

## Requirements

- Plathix PRO active with a valid license.
- Without a valid license, the `plathix/enabled_post_types` filter returns only `[attachment]` — the Free default.

## Related

- [Settings — General](../../settings/general.md)
- [PRO overview](../index.md)

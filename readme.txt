=== Plathix - Media Library Folders ===

Contributors: plathix
Tags: media-library, folders, organize, replace-media, svg
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Everything in its place. Organize your WordPress Media Library with folders, ready sets, replace media, SVG handling, and Trash.

== Description ==

= Keep your WordPress media organized as your site grows =

Everything in its place. Plathix helps you organize your Media Library - images, PDFs, logos, banners, and campaign files. One folder system your site can follow.

Find files faster, reduce duplicate uploads, and keep projects easier to hand over. Stay in control as your Media Library grows.

And you don't start from an empty tree. Pick a ready folder set and your library has a structure from day one.

= Where the mess comes from =

A Media Library rarely becomes messy overnight. It grows file by file: photos, price lists, banners, logos, icons, old versions. Soon it is one long list.

Then the same questions come back:

* Where is the latest version of this file?
* Which image is already used on the site?
* Why do we have four copies of this PDF?
* Which uploads still need sorting?

Search helps when you remember the file name. But many files are called IMG_2391.jpg or banner-final-2.png.

Plathix gives files a visible place, so finding one no longer depends on who remembers where it went.

= Everything Plathix does =

Plathix is a set of tools for keeping media in order:

* **Ready folder sets** - start from a structure for your site, not an empty tree
* **Folders** - nested folders, drag and drop, bulk move, upload into a folder
* **Find fast** - folder search, sorting, counts, a folder column, folders in the media picker
* **Colors and favorites** - color-code folders and pin the ones you use often
* **Overview** - file types, folder counts, and how much is still unsorted
* **Trash** - deleted folders and files wait in Trash and can be restored
* **Replace media** - update a file in place instead of uploading a new copy
* **SVG, handled** - allow SVG with sanitization and per-role control, or block it site-wide
* **Move your structure** - export a tree and reuse it on another site
* **Multilingual** - folder names in any language, with emoji and special characters

= Stop uploading the same file again =

Duplicate uploads happen because the original is hard to find. A clear tree keeps it visible and easy to reuse.

And inside a folder, new uploads go straight into it. No uploading first and moving the file later.

= Virtual folders: order without broken URLs =

Plathix uses virtual folders. The structure lives inside WordPress, while your files stay in the upload location configured for your site.

Changing a file's Plathix folder does not move the physical file or change its attachment ID. Files already used on the site keep their media location.

= Delete without fear =

Plathix does not delete your media the moment you click. Deleted folders go to Trash first.

Three system views sit at the top of the tree and cannot be renamed or removed:

* **Media Files** - the full Media Library
* **Unsorted** - files that have no folder yet
* **Trash** - deleted folder structures and related items, depending on your settings

When you activate Plathix, existing files are not moved. Anything without a folder lands in Unsorted, and the count drops as you sort. You clean up an old library gradually, without losing track.

Deleting a folder does not delete your media right away. The folder goes to Trash first and stays for a period you set. Depending on your settings, related files can be handled with the folder or left in place. Nothing is removed the moment you click.

= Start from a ready folder set =

Ready folder sets are one of the fastest ways to bring a Media Library into order. Instead of building folders from zero, pick a starter structure for your kind of site and adjust it.

Ready sets cover common site types:

* blogs
* agencies
* stores
* newsrooms
* photographers
* restaurants
* nonprofits
* property listings

Apply the closest set, then rename, remove, recolor, or add folders. A set adds to your structure - it does not replace your files. You can also start from an empty tree or restart the first-run helper.

Built a structure you like? Export it and reuse it on another site - good for backups, client handoff, and agency starters.

If Plathix detects a supported folder plugin, open Plathix > Tools to import it. Source data is kept; back up first.

= Replace a file, keep the attachment ID =

Some files change after they are used: a price list, a logo, a banner.

Plathix replaces the file behind an attachment while keeping the attachment ID. Content that uses the attachment record can then use the updated file. Keep the same file type where you can. After replacing, check the pages that use it and clear any caches or CDN.

= One SVG policy for the whole site =

WordPress blocks SVG uploads, so sites often end up with several plugins fighting over it. Plathix gives you one policy for the whole site:

* allow SVG uploads with sanitization
* block SVG uploads
* leave SVG handling to another plugin

Files that do not pass sanitization are rejected, and you pick which roles may upload SVG. A stricter mode suits sites with editors you do not fully trust.

= Safe to try =

* **Files are not moved** - Plathix folder changes do not move files on disk or change their existing media URLs
* **Attachment IDs stay the same** - folder assignment does not create new media records
* **Safe deactivation** - the Media Library returns to the normal WordPress view
* **Unsorted view** - existing files stay visible after activation
* **Trash first, clean removal** - review deletions; a Danger Zone clears Plathix data but keeps your media
* **No tracking by default** - no telemetry or analytics

The folder tree loads progressively for large libraries. A System Info screen reports environment and plugin status for support.

== Installation ==

1. Go to Plugins > Add New, search for Plathix, then Install Now and Activate.
2. Open Media > Library - the folder panel appears in the sidebar.

To install manually, upload the ZIP via Plugins > Add New > Upload.

= After activation =

Your existing files are not moved or renamed. Files with no folder appear in Unsorted.

On first run, an onboarding step helps you start: apply a ready folder set, or build your own tree. You can restart it from the Presets screen.

Switching from another plugin? Open Plathix > Tools to import.

== Frequently Asked Questions ==

= Does Plathix move my files on the server? =

No. Plathix uses virtual folders. The structure is stored inside WordPress, and your files stay in the upload location configured for your site.

= Will my existing image URLs change? =

Moving a file between folders does not move it on disk, so existing media URLs are designed to stay unchanged.

= Will my site break if I deactivate Plathix? =

No. Your media files stay in WordPress. The folder panel disappears and the Media Library returns to normal.

= What happens to files that are not in any folder? =

They appear in Unsorted. You can move them into folders whenever you are ready.

= Can I use emoji or non-English folder names? =

Yes. Folder names support different languages, emoji, and special characters.

= Can I organize posts, pages, or WooCommerce products with this free version? =

No. This free version organizes the WordPress Media Library.

= Can I replace a file that is already used on the site? =

Yes, keeping the attachment ID, so content that uses the attachment record can use the updated file. Keep the same file type where you can.

= Is anything limited in the free version? =

No artificial folder or file limits, and no trial period.

== Screenshots ==

1. Ready folder sets. Start with a structure for your kind of site instead of an empty tree.
2. The Plathix overview screen: file counts, file types, folder counts, disk usage, and unsorted files.
3. First-run onboarding.
4. The folder panel in the Media Library, with counts and system views.
5. The Unsorted view shows files that still need a folder.
6. Drag and drop a batch of files into a folder.
7. Folder colors, favorites, and the right-click menu.
8. Search folders by name and sort the tree.
9. The folder tree inside the WordPress media picker.
10. The folder column in list view.
11. Replace the file behind an existing attachment.
12. SVG upload policy, strict mode, and role settings.

== Third-Party Libraries ==

Plathix bundles the following open-source libraries. Each is distributed under its own
license; the notices below are reproduced as those licenses require.

* Alpine.js 3.16.1 - MIT License - Copyright (c) Caleb Porzio - https://alpinejs.dev

== Changelog ==

= 1.0.0 =
* Initial public release.

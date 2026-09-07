# Changelog

## 1.0.18 — 2026-09-07

- 📝 docs: document the changelog convention and link it from the README
- 📝 docs(changelog): seed CHANGELOG.md from the tagged history
- 📝 docs(neo_favicon): link CONTRIBUTING.md from README
- 📝 docs: add CONTRIBUTING.md

## An unopenable favicon package leaves the installed icons in place

_Released in 1.0.17 — 2026-08-31._

**The favicon package is unpacked through `neo_config_file`'s `ZipExtractor`
instead of core's archiver plugin manager.** Drupal 12 removes that plugin
namespace with no replacement; the extractor is the one implementation the
modules that unpack a config file's payload share now. The call is
`extract($zip_uri, 'public://neo-favicon')` from
`FaviconConfigFileSubscriber::onConfigFilePreSave()`.

**Nothing is emptied first.** The extractor unpacks elsewhere and replaces the
directory only once the whole package is on disk, so a package that will not
open leaves the site's existing favicons where they are. The subscriber used
to `deleteRecursive()` the directory and then extract into it; a zip that
would not open still left `public://neo-favicon` gone.

**A refusal is logged, not thrown.** `ExtractionRefusedException` is caught
and written as a warning on the `neo_favicon` logger channel, naming the file
and saying the installed favicons are unchanged. This runs inside a settings
save; an uncaught exception there takes the form down. Only the refusal is
caught — anything else is a bug that should surface. The save completes.

The package's first tests landed here: five kernel cases on
`FaviconConfigFileSubscriberTest`, covering a successful unpack, a config
file that belongs to another form, a config file with no file, a package that
cannot be opened, and a delete of the favicon directory.

## The toolbar item can fill its slot

_Released in 1.0.16 — 2026-06-12._

**The favicon toolbar item gains an Image Size option.** Default keeps the
image at 36×36. **Full** makes it fill the item: the element gets `relative`,
the image gets `absolute top-0 left-0 w-full h-full object-cover`, and the
image size is set to 100×100. The select refreshes the image radios over
AJAX, same as Image Filter.

A site that never picks Full behaves as it did. There is nothing to migrate.

## The toolbar item can invert the favicon for a dark bar

_Released in 1.0.15 — 2025-10-08._

**The favicon toolbar item gains an Image Filter option** — two preset CSS
filter strings, meant for light-on-dark toolbars. Style 1 is
`filter: brightness(0) invert(1);`. Style 2 is
`mix-blend-mode: screen; filter: invert(1) brightness(2);`.

**The chosen string is applied to the rendered item and to the radio
previews in the item form.** Changing the select refreshes those previews
over AJAX. None leaves the image unfiltered. The value is stored on the
item as `filter`.

## Deleting another module's config file no longer wipes the favicons

_Released in 1.0.14 — 2025-08-07._

**`onConfigFilePreDelete()` ran `deleteRecursive('public://neo-favicon')` on
every config-file delete on the site.** A config file belonging to any other
module's form was enough: this module's unpacked favicons disappeared with
it. The save path already returned unless the parent form was
`neo_favicon_settings`; the delete path did not.

**It now returns unless the config file's parent form is
`neo_favicon_settings`.** Deleting this module's package still removes
`public://neo-favicon`. Deleting anyone else's config file leaves the
favicons alone. That guard is still the one in
`FaviconConfigFileSubscriber.php`.

This is the entry most worth taking on a site that shares `neo_config_file`
with other modules.

## The toolbar item is not blank on a fresh install

_Released in 1.0.12 — 2025-07-08._

**The toolbar item falls back to a bundled `images/favicon.png` when no
package has been uploaded or the configured image is gone.**
`Favicon::getElement()` checks `file_exists()` on the configured path and,
if that fails, points at the PNG shipped with the module, so the item is
not blank on a fresh install.

**`FaviconManager::getImages()` checks the directory exists before scanning
it.** `public://neo-favicon` is not created until a package is unpacked; a
scan of a missing directory used to error. It now returns an empty list
when the path is not a directory, and the page renders.

## The favicon toolbar item is configurable

_Released in 1.0.11 — 2025-07-08._

**The item's URL, open-in-new-window, image and color scheme now have
config schema.** `neo_toolbar_item.settings.favicon` declares `url`,
`target`, `image` and `scheme`, so those choices persist as typed
toolbar-item settings rather than untyped extras. The plugin already
offered the form; this is the schema that makes the values a saved
configuration.

## Drupal 11 is a supported core

_Released in 1.0.10 — 2025-07-01._

**Composer now accepts Drupal 11.** The `drupal/core` constraint is
`^10.3 || ^11`. A site on Drupal 11 can install this package; a site on
Drupal 10.3 is unchanged. There is nothing to migrate.

## A missing logo token falls back to the largest favicon PNG

_Released in 1.0.8 / 1.0.9 — 2025-06-04._

**Neo's `logo` and `image` smart tokens fall back to the largest PNG in
the favicon package when nothing else has supplied a URI.**
`neo_favicon_neo_token_logo_alter()` — and `neo_favicon_neo_token_image_alter()`,
which calls it — walk `FaviconManager::getImages()`, score each file by
width × height, and write the largest URI only when `$uri` is still empty.
`getImages()` itself only returns PNG files under `public://neo-favicon`.

**The hooks run last.** `hook_module_implements_alter()` moves this
module to the end of `neo_token_logo` and `neo_token_image`, so another
module that does set a URI keeps it. The `if (!$uri)` guard is the other
half of that: this module fills a gap, it does not overwrite.

**1.0.9 made the entity argument nullable.** Both alters take
`?ContentEntityInterface $entity = NULL`, so a token that fires with no
entity no longer fatals. Same-day as 1.0.8; one entry because it is the
same fallback, made to survive being called without an entity.

## The favicon manager and the toolbar item arrive

_Released in 1.0.3 — 2024-10-01._

**`neo_favicon.manager` and the `favicon` toolbar item land together.**
Until this release the module only emitted `<head>` tags from the
settings form's uploaded package. The manager now builds that HTML from
the unpacked files under `public://neo-favicon` and attaches it through
`hook_page_attachments_alter()`, and the toolbar item plugin renders one
of those images in the toolbar. That is the point the module stopped
being only a tag emitter.

## Releases before 1.0.3 are not recorded

This file starts at 1.0.3 deliberately, and the omission is not a
truncation. `1.0.0`–`1.0.2` are the initial commit, an icon and a
menu-weight removal — the module as a `<head>` tag emitter, before the
manager and the toolbar item existed. The releases skipped between 1.0.3
and 1.0.17 changed nothing a site observes: `1.0.4`–`1.0.7`
(`1.0.3..1.0.7`) hold five `Cleanup.` commits plus `Set mstile to white`,
`Better preview` and `If we don't yet have a favicon set`, and `1.0.13`
is the schema `text`→`string` correction on `neo_favicon.settings.tags`.
Summarising those would produce entries about behaviour no current site
can tell apart from the releases that surround them, which is worse than
saying plainly that they are not covered.

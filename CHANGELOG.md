# Changelog

All notable changes to `plg_system_dinkytags` are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed
- A defaulted list field (`excluded_components`, `strip_query_params`, and
  `enabled_views`) could not be **cleared**: `Registry::get()` returns the manifest
  default for an empty string, so an admin who emptied the field got the default
  back. These are now read from the raw params array so a saved-but-empty field
  means "none". Found while removing `com_icagenda` from `excluded_components` on
  the empulsiv test instance.
- `override_mode=only-if-missing` now also detects `og:*` that another extension
  emitted as a raw custom `<meta>` tag via `Document::addCustomTag()` (a component
  view — e.g. iCagenda's event view), which `getMetaData()` cannot see. Previously
  those produced duplicate `og:title` / `og:image` / etc. when the component was
  not in `excluded_components`. Kept custom values also feed the Twitter block.

### Changed
- Display name is now **DinkyTags** (one word) — plugin name shown in the
  admin ("System - DinkyTags"), the head debug comment (`<!-- DinkyTags debug`),
  docs and the build metadata. Technical identifiers are unchanged: element
  `dinkytags`, package `plg_system_dinkytags`, namespace
  `TheLoom\Plugin\System\DinkyTags`.
- Config form polish after review feedback:
  - `description_source` is a select instead of a two-state switcher — its long
    option labels no longer wrap over the next field / inline help.
  - All yes/no switchers list `No` before `Yes` (Joomla convention) so the toggle
    colour and position read correctly.
  - `override_mode` reframed as a plain yes/no "Overwrite existing tags" toggle
    (stored values `only-if-missing` / `always` unchanged, no code impact).
  - Clearer labels/descriptions for "Overwrite existing tags", "Menu items to
    skip" (says the list is the site's menu items) and `og:locale` (emphasises the
    `auto` default).
  - Dropped the custom `label` on both `<fieldset>` elements (and their now-unused
    language keys); the fieldset name drives the tab.

### Fixed
- Context detection classified an article or category reached without its own menu
  item as `home` (it inherits the site's default/home Itemid, so `getActive()->home`
  is 1). The `com_content` view is now checked first; `home` requires no `id` and a
  matching component. Found during the Phase 10 Docker pass.
- `ContextDetector` / `buildArticle` / `buildCategory` now pass an explicit `0`
  default to `Input::getInt('id')`, which otherwise returns `null` for a missing key.

### Added
- Repository skeleton: GPLv3 license, work plan, README, changelog.
- Extension manifest `dinkytags.xml` with the full parameter form (18 params,
  `basic` + `advanced` fieldsets) and an update server entry.
- DI service provider `services/provider.php`.
- `DinkyTags` system-plugin class: subscribes to `onBeforeCompileHead`, implements
  the skip-condition chain (site client, HTML non-error document, `tmpl`/`format`/
  `print`, offline, excluded components, excluded menu items).
- `Helper\ContextDetector`: request context classification (article / category /
  featured / home / other), home menu item wins.
- `Helper\Text`: HTML-to-excerpt (tag/shortcode strip, entity decode incl.
  double-encoded `&amp;nbsp;`, whitespace collapse, word-boundary cut + ellipsis).
- `Helper\ImageResolver`: `#joomlaImage://` suffix strip, remote/protocol-relative
  passthrough with scheme, root-relative to absolute via `Uri::root()`, per-request
  `getimagesize()` cache, first inline `<img>` scan.
- `Helper\ArticleLoader`: article via com_content site model, tag titles (model or
  fallback map query), category row, custom-field value.
- `DinkyTags::buildData()` and per-context builders producing the tag payload
  (type, title, description, image, `article:*` block) with the full article image
  resolution order.
- `Helper\MetaWriter`: writes the payload to the document. Open Graph block
  (`og:type/title/description/url/site_name/locale/image[:width|:height|:alt]`);
  `og:url` reuses an existing `rel=canonical` else builds from the request, then
  strips configured tracking params (order/encoding preserved, whole URL never
  urlencoded); `override_mode=only-if-missing` keeps a pre-existing
  `og:title/description/image` (and then also skips our `og:image:*` siblings);
  `article:*` block with repeated `article:tag` via `addCustomTag` (static
  double-run guard); `fb:app_id`; Twitter Card block with
  `summary_large_image` when the effective image is >= 300 px wide or unknown.
  Returns a decision log for the upcoming debug comment.
- Debug mode: with `debug=1` the plugin appends one HTML comment to the head
  listing every decision (skip reason, context, title, description length +
  source, image source step, `og:url` source, and every tag set / kept / skipped),
  regardless of whether tags were emitted. `--` is neutralised for the comment.
  `inject()` restructured into `process()` + a decision log so a skip can still be
  explained.
- Complete `en-GB` and `de-DE` language files (`.ini` + `.sys.ini`).
- `build.xml` Phing target `package`: builds `.releases/plg_system_dinkytags-<x.y.z>.zip`
  (version read from `dinkytags.xml`) and writes `.releases/update.xml` with
  sha256/384/512, plugin update fields (`<element>dinkytags</element>`,
  `<folder>system</folder>`), targetplatform `5.1-5.5 / 6.0-6.3`, `php_minimum` 8.2.
  README/CHANGELOG/LICENSE excluded from the package; `LICENSE.txt` shipped.
- `.doc/ARCHITECTURE.md` (full technical reference) and a complete `README.md`
  (install, per-parameter docs, debugging, FAQ).
- Phase 10 functional test pass on a Joomla 5.x / PHP 8.3 Docker stack against the
  spec section 7 matrix — see `.doc/ARCHITECTURE.md` for what was verified.

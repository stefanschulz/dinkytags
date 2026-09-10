# Changelog

All notable changes to `plg_system_dinkytags` are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

_No changes yet._

## [1.1.0] - 2026-09-10

Supersedes the initial 1.0.0 publish; rolls up the fixes and refinements made
since, plus two new parameters. All changes are backward compatible — new
parameters default to the previous behaviour.

### Added
- An on/off switch in front of each of the two defaulted text lists:
  `exclude_components_enable` gates `excluded_components`,
  `strip_query_params_enable` gates `strip_query_params` (both default on).
  Turning a switch off ignores its list entirely at runtime; the list field
  keeps its pre-filled default, so it can no longer be lost by an unrelated
  save. This is the supported way to disable either list — an emptied text
  field cannot be told apart from "never configured": `Registry::get()` returns
  the manifest default for an empty string, at runtime *and* in the edit form,
  which then wrote the default back on the next save.

### Changed
- `override_mode=only-if-missing` now also guards `og:site_name` and `og:locale`
  (not just `og:title` / `og:description` / `og:image`): if another extension
  has already set either — through the document API or as a raw custom `<meta>`
  tag — DinkyTags keeps the existing value. Extends spec §3.7.
- `override_mode=only-if-missing` also detects `og:*` that another extension
  emitted as a raw custom `<meta>` via `Document::addCustomTag()` (a component
  view — e.g. iCagenda's event view), which `getMetaData()` cannot see; those
  used to produce a duplicate `og:title` / `og:image` when the component was not
  in `excluded_components`. Kept custom values also feed the Twitter block. The
  scan tolerates attribute order, single/double quotes and whitespace around
  `=`.
- Context detection checks the `com_content` view before the home-menu check, so
  an article or category reached without its own menu item is no longer
  misclassified as `home` (it inherits the site's default Itemid). `id` lookups
  pass an explicit `0` default to `Input::getInt()`.
- Config form polish: `description_source` is a select (its long labels no
  longer wrap); all yes/no switchers list `No` before `Yes`; `override_mode` is
  shown as an "Overwrite existing tags" toggle (stored values unchanged);
  clearer labels for "Overwrite existing tags", "Menu items to skip" and
  `og:locale`; the custom `<fieldset>` `label` attributes were dropped.
- Display name is **DinkyTags** (one word) in the admin, the head debug comment
  and the build metadata. Technical identifiers are unchanged: element
  `dinkytags`, package `plg_system_dinkytags`, namespace
  `TheLoom\Plugin\System\DinkyTags`.

### Fixed
- `og:locale` (auto): a language tag with a script subtag (`zh-Hans-CN`)
  produced the invalid `zh_Hans_CN`; it is normalised to `language_TERRITORY`
  (`zh_CN`). Two-segment tags are unchanged (`de-DE` → `de_DE`).
- `twitter:card` reads a kept extension's own `og:image:width` (when it
  published one next to its `og:image`) instead of always assuming an unknown
  width.
- `Helper\Text::excerpt()` drops `<script>` / `<style>` blocks (content
  included) before `strip_tags()`, which would otherwise leave their text in the
  excerpt.
- `Helper\ArticleLoader` logs a caught data-access failure to the
  `plg_system_dinkytags` log category (silent unless a logger is configured) so
  a real DB problem is not completely invisible.

### Verified
- Full spec section 7 matrix re-run on **Joomla 6.1.3 / PHP 8.4.25** (disposable
  Docker stack): install, config form and all 11 pages behave identically to
  Joomla 5 — one `og:image` per page, correct `og:type` / `twitter:card` /
  `og:url` / skip conditions, zero PHP notices. Confirms the 5.1+ / 6.x claim.

### Added (dev tooling — not shipped in the package)
- Test infrastructure matching the sibling extensions: `composer.json`
  (PHPUnit `^11.5 || ^12.0`, PHP_CodeSniffer `^3.10`), `phpunit.xml.dist`,
  `phpcs.xml.dist` (PSR-12), `tests/bootstrap.php`, `.editorconfig`,
  `.gitattributes`.
- `tests/Unit/TextTest.php` and `tests/Unit/ImageResolverTest.php` — 44 unit
  tests over `Text::excerpt()` and `ImageResolver::firstImageSrc()` /
  `resolve()` (the helpers with plain scalar signatures that never touch the
  CMS). Run with `composer test` / `composer lint`.
- `\defined('_JEXEC') or die;` is wrapped in
  `// phpcs:disable PSR1.Files.SideEffects` in every source file so `phpcs`
  passes clean.
- `build.xml` excludes `composer.*`, `vendor/`, `tests/` and the `.dist` files
  from the package zip.

## [1.0.0] - 2026-08-30

First public release.

### Added
- Repository skeleton: GPLv3 license, work plan, README, changelog.
- Extension manifest `dinkytags.xml` with the full parameter form (`basic` +
  `advanced` fieldsets) and an update server entry.
- DI service provider `services/provider.php`.
- `DinkyTags` system-plugin class: subscribes to `onBeforeCompileHead`,
  implements the skip-condition chain (site client, HTML non-error document,
  `tmpl`/`format`/`print`, offline, excluded components, excluded menu items).
- `Helper\ContextDetector`: request context classification (article / category /
  featured / home / other), home menu item wins.
- `Helper\Text`: HTML-to-excerpt (tag/shortcode strip, entity decode incl.
  double-encoded `&amp;nbsp;`, whitespace collapse, word-boundary cut +
  ellipsis).
- `Helper\ImageResolver`: `#joomlaImage://` suffix strip, remote/protocol-
  relative passthrough with scheme, root-relative to absolute via `Uri::root()`,
  per-request `getimagesize()` cache, first inline `<img>` scan.
- `Helper\ArticleLoader`: article via com_content site model, tag titles (model
  or fallback map query), category row, custom-field value.
- `DinkyTags::buildData()` and per-context builders producing the tag payload
  (type, title, description, image, `article:*` block) with the full article
  image resolution order.
- `Helper\MetaWriter`: writes the payload to the document. Open Graph block
  (`og:type/title/description/url/site_name/locale/image[:width|:height|:alt]`);
  `og:url` reuses an existing `rel=canonical` else builds from the request, then
  strips configured tracking params (order/encoding preserved, whole URL never
  urlencoded); `override_mode=only-if-missing` keeps a pre-existing
  `og:title/description/image` (and then also skips our `og:image:*` siblings);
  `article:*` block with repeated `article:tag` via `addCustomTag` (static
  double-run guard); `fb:app_id`; Twitter Card block with `summary_large_image`
  when the effective image is >= 300 px wide or unknown. Returns a decision log
  for the debug comment.
- Debug mode: with `debug=1` the plugin appends one HTML comment to the head
  listing every decision (skip reason, context, title, description length +
  source, image source step, `og:url` source, and every tag set / kept /
  skipped), regardless of whether tags were emitted.
- Complete `en-GB` and `de-DE` language files (`.ini` + `.sys.ini`).
- `build.xml` Phing target `package`: builds
  `.releases/plg_system_dinkytags-<x.y.z>.zip` (version read from
  `dinkytags.xml`) and writes `.releases/update.xml` with sha256/384/512, plugin
  update fields (`<element>dinkytags</element>`, `<folder>system</folder>`),
  targetplatform `5.1-5.5 / 6.0-6.3`, `php_minimum` 8.2. README/CHANGELOG/LICENSE
  excluded from the package; `LICENSE.txt` shipped.
- `.doc/ARCHITECTURE.md` (full technical reference) and a complete `README.md`
  (install, per-parameter docs, debugging, FAQ).
- Phase 10 functional test pass on a Joomla 5.x / PHP 8.3 Docker stack against
  the spec section 7 matrix.

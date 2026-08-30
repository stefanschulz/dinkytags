# DinkyTags

`plg_system_dinkytags` — a small, dependency-free Joomla **system plugin** that emits
correct, complete, non-duplicated **Open Graph** and **Twitter Card** meta tags for
frontend pages.

Joomla core (verified on 6.1.x) ships no `og:*` / `twitter:*` meta for `com_content`
articles, categories or the home page, so links shared to Facebook, LinkedIn, X,
WhatsApp, Slack, Signal, Discord or Mastodon fall back to the site logo or nothing.
DinkyTags fills that gap: drop it in, set a default image, done — portable across sites,
configured entirely through plugin parameters.

- **Target:** Joomla 5.1+ and 6.x, PHP 8.2+, site client only.
- **License:** GNU General Public License v3 or later — see [LICENSE](LICENSE).
- **Architecture:** [.doc/ARCHITECTURE.md](.doc/ARCHITECTURE.md).

## Install

Install the package zip (`plg_system_dinkytags-<x.y.z>.zip`) through **System →
Install → Extensions**, then enable **System - DinkyTags** under **System → Plugins**.
Set at least `default_image`. That is the whole setup.

To build the zip from source you need [Phing](https://www.phing.info/):

```bash
phing package
```

The zip and a matching `update.xml` land in `.releases/`.

## What it does

| Page | `og:type` | Title | Description | Image |
|---|---|---|---|---|
| Single article | `article` | article title | meta description or intro-text excerpt | article image → custom field → first inline `<img>` → category image → `default_image` |
| Category blog / list | `website` | category title | category description → site meta description | category image → `default_image` |
| Featured | `website` | menu item title | site meta description | `default_image` |
| Home | `website` | site name | site meta description | `home_image` → `default_image` |
| Any other HTML page | `website` | page title (site-name affix stripped) | document meta description | `default_image` |

For articles it also emits `article:published_time`, `article:modified_time`,
`article:section`, one `article:tag` per tag, and `article:author` (toggle with
`emit_article_meta`). `og:url` reuses the page's existing `rel=canonical` when present.

It stays silent on: non-HTML output, `tmpl=component` / `print=1`, the offline page, the
error page, admin / CLI / API, and any component in `excluded_components` (default
`com_icagenda`, which emits its own Open Graph for event pages).

## Parameters

**Tags**

- **Handle these views** (`enabled_views`) — tick the contexts that should get tags.
- **Default image** (`default_image`) — site-wide fallback share image, ideally
  1200×630. Empty ⇒ no `og:image` rather than a placeholder.
- **Home page image** (`home_image`) — overrides the default on the home page only.
- **Article description from** (`description_source`) — `metadesc-first` (default) or
  `introtext-first`.
- **Description length** (`description_length`) — character cap, word-boundary cut,
  default 200.
- **Image custom field** (`image_custom_field`) — machine name of a `com_content`
  custom field holding a media path/URL; checked after the article images.
- **Emit article:\* tags** (`emit_article_meta`) — default yes.
- **Emit Twitter Card tags** (`emit_twitter`) — default yes. `twitter:card` is
  `summary_large_image` when the image is ≥ 300 px wide or its size is unknown, else
  `summary`.
- **twitter:site / twitter:creator** — `@handle` values.
- **fb:app_id** — emitted on every page when set.
- **og:locale** (`og_locale`) — `auto` derives it from the active language
  (`de-DE` → `de_DE`), or set an explicit value.

**Advanced**

- **When a tag already exists** (`override_mode`) — `only-if-missing` (default) leaves a
  pre-existing `og:title` / `og:description` / `og:image` untouched; `always` overwrites.
- **Last-resort logo image** (`fallback_to_logo`) — off by default; when on, uses the
  template logo as `og:image` if a page has no image and no default.
- **Strip query parameters** (`strip_query_params`) — comma list removed from `og:url`
  (tracking params). Order and encoding of the rest are preserved; the path is untouched.
- **Excluded components** (`excluded_components`) — `option` values to skip entirely.
- **Excluded menu items** (`excluded_menu_items`).
- **Debug comment** (`debug`) — see below. Off in production.

## Debugging

Set **Debug comment** to *Yes*. Every frontend HTML response then carries one comment in
the head listing the decision trail — skip reason, context, chosen title / description
(and its source) / image (and which resolution step produced it), `og:url` source, and
every tag set / kept / skipped:

```html
<!-- DinkyTags debug
context: article
description source: metadesc
image source: article image_fulltext
og:url: reused page canonical
set og:type
set og:title
...
-->
```

Quick check without the browser:

```bash
curl -s https://example.com/some/page | grep -E 'og:|twitter:|article:'
```

## FAQ

**A page shows no `og:image`.** By design: the page has no own image and `default_image`
is empty (or `fallback_to_logo` is off). Set `default_image`.

**`og:url` comes out as `http://` on an HTTPS site behind a proxy.** Enable Global
Configuration → Server → *Behind Load Balancer* so `Uri` honours `X-Forwarded-*`. A
canonical link set by the site is always used verbatim regardless.

**An iCagenda / other event page still has its own tags.** Correct — `com_icagenda` is in
`excluded_components` by default, so DinkyTags does not touch it. Remove it from the
list to override.

**Tags appear twice.** Another extension (e.g. an older social-meta plugin) is also
emitting them. Disable it, or leave `override_mode` on `only-if-missing` and DinkyTags
will defer to whatever is already set.

## Scope

**v1.0** does everything above. Deferred to **v1.1+**: on-the-fly image resizing
(crop/pad to 1200×630, cached and served as `og:image`) and per-language default images.
Out of scope entirely: JSON-LD (Joomla core covers it), sitemaps, redirects, a per-page
meta editor UI.

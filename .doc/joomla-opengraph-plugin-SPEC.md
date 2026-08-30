# Joomla Open Graph / Social Meta Plugin — Requirements & Development Spec

Self-contained brief for building a reusable Joomla system plugin that emits
Open Graph + Twitter Card meta tags for frontend pages. Written so a developer —
human or AI, in a fresh session — can implement it from this document alone.

Working name: **`plg_system_opengraph`** (rename freely).
Target: a standalone, multi-site-reusable extension in its own repository.

---

## 1. Why

Joomla core (verified on **6.1.3**) emits **no** `og:*` or `twitter:*` meta for
`com_content` articles, categories, or the home page. Result: links shared to
Facebook, LinkedIn, X/Twitter, WhatsApp, Slack, Signal, Discord, Mastodon show
the site logo or nothing instead of the article's own image and text.

`plg_schemaorg_*` (core) only produces JSON-LD — the Facebook/LinkedIn sharers do
**not** read it for the preview image. OG `<meta property="og:image">` is
required.

### Goal
One dependency-free `type=system` plugin that auto-builds **correct, complete,
non-duplicated** OG + Twitter Card meta for the relevant frontend views, driven
by plugin parameters, portable across sites (drop in, set a default image, done).

### Non-goals (v1)
Sitemaps, redirects, canonical management, a per-page meta editor UI, an image
CDN, on-the-fly image resizing (noted as a v1.1 option), JSON-LD (core covers it).

---

## 2. Target platform

- Joomla **5.1+ and 6.x**. Namespaced plugin: `services/provider.php` +
  `Joomla\Event\SubscriberInterface`. No legacy `JPlugin`.
- PHP **8.2+**.
- **Site** client only. Configuration entirely via plugin params (`config.xml`);
  no admin views in v1.
- GPLv2-or-later header in every PHP file.

---

## 3. Functional requirements

### 3.1 Views handled (each toggleable via params)

| context | `og:type` | title source | description source | image source |
|---|---|---|---|---|
| `com_content` single article (`view=article`) | `article` | article title | see 3.4 | see 3.3 |
| `com_content` category blog/list (`view=category`) | `website` | category title | category description → site desc | category image → default image |
| `com_content` featured (`view=featured`) | `website` | menu/page title | site description | default image |
| site **home** (any component) | `website` | site name | site description param | `home_image` → `default_image` |
| **any other** frontend HTML page | `website` | document `<title>` (site-name suffix stripped) | document meta description | `default_image` |

### 3.2 Skip conditions (emit nothing, bail early)

- `$doc->getType() !== 'html'` (feeds, JSON, raw)
- request has `tmpl=component`, `format=` other than html, `print=1`
- application is admin / CLI / API
- active menu item id is in param `excluded_menu_items`
- `option` is in param `excluded_components` — **default list includes
  `com_icagenda`** (its PRO edition emits its own clean OG for event pages;
  do not fight it)
- offline page, error document

### 3.3 Image resolution order (article)

1. `#__content.images` JSON → `image_fulltext`
2. `#__content.images` JSON → `image_intro`
3. Custom field whose name == param `image_custom_field` (if set), value is a
   media path or URL
4. First `<img src="…">` found in `fulltext`, then `introtext` (raw text scan,
   see 4.4 — no content-plugin expansion in v1)
5. Category image: `#__categories.params.image`
6. Param `default_image`
7. **Nothing** → emit no `og:image` at all (do **not** substitute the logo
   unless param `fallback_to_logo` is on, default off)

Processing for the chosen value:
- strip a `#joomlaImage://…` suffix (`explode('#', $value, 2)[0]`)
- if it starts with `http://` / `https://` / `//` → use as-is (remote)
- else treat as root-relative; make absolute with `Uri::root()` (handles
  subfolder installs) — result must be an **absolute URL with scheme + host**
- for a local file: `@getimagesize(JPATH_ROOT . '/' . ltrim($rel,'/'))` →
  width/height (cache per request); on failure just omit width/height

### 3.4 Description resolution (article)

- param `description_source`:
  - `metadesc-first` (default): `#__content.metadesc` if non-empty, else excerpt
  - `introtext-first`: excerpt of introtext, else `metadesc`
- excerpt = `HTMLHelper::_('content.prepare', …)` is **too heavy**; instead:
  `strip_tags`, remove `{…}` shortcodes, `html_entity_decode(..., ENT_QUOTES|ENT_HTML5, 'UTF-8')`,
  collapse whitespace, cut on a word boundary at param `description_length`
  (default **200**), append `…` if cut.

### 3.5 Tags emitted

**Open Graph** (`setMetaData($k, $v, 'property')`):
`og:type`, `og:title`, `og:description`, `og:url`, `og:site_name`, `og:locale`,
`og:image`, `og:image:width`, `og:image:height`, `og:image:alt`.
For `og:type=article` and param `emit_article_meta=1` also:
`article:published_time`, `article:modified_time` (ISO-8601 from `publish_up` /
`modified`), `article:section` (category title), `article:tag` (one tag per
value — **repeated** tags, see 4.6), `article:author` (author name if
`show_author`-ish, optional).
`fb:app_id` if param set.

**Twitter Card** (`setMetaData($k, $v, 'name')`), only if `emit_twitter=1`:
`twitter:card` = `summary_large_image` when the resolved image is ≥ 300 px wide
(or unknown), else `summary`; `twitter:title`, `twitter:description`,
`twitter:image`, `twitter:image:alt`; `twitter:site` / `twitter:creator` from
params (`@handle`).

### 3.6 og:url — the important detail

- Reuse the canonical the document already has if present: scan
  `$doc->getHeadData()['links']` for `relation === 'canonical'`, take its href.
  (Joomla's `plg_system_sef` / `com_content` set this before `onBeforeCompileHead`.)
- Else build from `Uri::getInstance()->toString(['scheme','host','port','path','query'])`.
- Remove query params listed in param `strip_query_params`
  (default `utm_source,utm_medium,utm_campaign,utm_term,utm_content,fbclid,gclid,mc_cid,mc_eid`).
- Pass the **plain** string to `setMetaData` — Joomla escapes on render.
  **Never `urlencode()` the whole URL** (that is the concrete bug in the
  extension this replaces — it emitted `og:url` as `http%3A%2F%2F…`).

### 3.7 Deduplication / override

- param `override_mode`: `only-if-missing` (**default**) | `always`.
  In `only-if-missing`, before setting `og:image` / `og:title` / `og:description`
  check `$doc->getMetaData($k, 'property')` — skip if already non-empty.
- Build all values into one assoc array, then write once each — never call
  `setMetaData` for the same key twice in one run.

---

## 4. Technical design

### 4.1 Event

- `type=system`, subscribe to **`onBeforeCompileHead`**.
  Rationale: at this point the component view has fully rendered (so the article
  model has been populated and the canonical link is set), the document is the
  final `HtmlDocument`, and nothing else will run before `<head>` is serialised.
- Also acceptable: `onAfterDispatch`. Document the choice; `onBeforeCompileHead`
  is preferred because the canonical link is guaranteed present.

`getSubscribedEvents(): array` → `['onBeforeCompileHead' => 'inject']`.

### 4.2 Skeleton (Joomla 5/6 namespaced)

```
plg_system_opengraph/
├── opengraph.xml                       manifest, method="upgrade", <namespace path="src">Joomla\Plugin\System\OpenGraph
├── services/provider.php               DI registration
├── src/Extension/OpenGraph.php          implements SubscriberInterface
├── src/Helper/ImageResolver.php
├── src/Helper/Text.php                  excerpt / entity / whitespace helpers
├── language/en-GB/plg_system_opengraph.ini
├── language/en-GB/plg_system_opengraph.sys.ini
├── language/de-DE/plg_system_opengraph.ini
└── language/de-DE/plg_system_opengraph.sys.ini
```

`services/provider.php` (pattern):

```php
<?php
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Plugin\System\OpenGraph\Extension\OpenGraph;

return new class implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(PluginInterface::class, function (Container $c) {
            $plugin = new OpenGraph(
                $c->get(DispatcherInterface::class),
                (array) PluginHelper::getPlugin('system', 'opengraph')
            );
            $plugin->setApplication(Factory::getApplication());
            return $plugin;
        });
    }
};
```

`src/Extension/OpenGraph.php` shape:

```php
final class OpenGraph extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return ['onBeforeCompileHead' => 'inject'];
    }

    public function inject(Event $event): void
    {
        $app = $this->getApplication();
        if (!$app->isClient('site')) return;

        $doc = $app->getDocument();
        if ($doc->getType() !== 'html') return;

        $input = $app->getInput();
        if ($input->getCmd('tmpl') === 'component'
            || $input->getCmd('format', 'html') !== 'html'
            || $input->getInt('print') === 1) return;

        // … skip-list checks (menu id, component) …

        $ctx = $this->detectContext($app, $input);        // 'article' | 'category' | 'featured' | 'home' | 'other'
        if (!$this->viewEnabled($ctx)) return;

        $data = $this->buildData($ctx, $app, $input, $doc); // assoc: type,title,description,url,image,...
        $this->writeTags($doc, $data);
    }
}
```

### 4.3 Loading the article without a second heavy query

For `view=article`:

```php
$id = $app->getInput()->getInt('id');
$model = $app->bootComponent('com_content')
    ->getMVCFactory()
    ->createModel('Article', 'Site', ['ignore_request' => true]);
$model->setState('article.id', $id);
$model->setState('params', $app->getParams());
$item = $model->getItem();   // has: title, images (JSON string), metadesc,
                             // introtext, fulltext, publish_up, modified,
                             // catid, category_title, tags (may need getItem to include)
```

If `$item->tags` is not populated, query `#__contentitem_tag_map` +
`#__tags.title` for `type_alias='com_content.article'` and `content_item_id=$id`.

Category context: `$app->bootComponent('com_content')->getMVCFactory()
->createModel('Category', 'Site', …)` or read `#__categories` directly for
`title`, `description`, `params->image`.

### 4.4 First-image fallback

```php
if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $item->fulltext . ' ' . $item->introtext, $m)) {
    $candidate = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
}
```

Only used when the `images` JSON yields nothing. Accept that content-plugin
constructs (`{gallery …}`, video shortcodes) won't produce an image in v1.

### 4.5 Absolute URL / width-height

```php
use Joomla\CMS\Uri\Uri;

$img = explode('#', $raw, 2)[0];
if (!preg_match('#^(https?:)?//#i', $img)) {
    $rel = ltrim($img, '/');
    $abs = rtrim(Uri::root(), '/') . '/' . $rel;
    $size = @getimagesize(JPATH_ROOT . '/' . $rel) ?: null;   // [0]=w,[1]=h
} else {
    $abs = $img; $size = null;
}
```

### 4.6 Repeated `article:tag` tags

`$doc->setMetaData` overwrites by key. For multiple tags use:

```php
foreach ($tags as $t) {
    $doc->addCustomTag('<meta property="article:tag" content="'
        . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '">');
}
```

`addCustomTag` output is not deduped by Joomla — guard against running twice
(the plugin runs once per request anyway).

### 4.7 og:locale

`str_replace('-', '_', $app->getLanguage()->getTag())` → e.g. `de-DE` → `de_DE`.
Param `og_locale` = `auto` (default) or an explicit override.

---

## 5. Parameters (`config.xml`)

| param | type | default | note |
|---|---|---|---|
| `enabled_views` | checkboxes | article,category,featured,home,other | which contexts to handle |
| `default_image` | media (`accept="image"`) | — | site-wide fallback |
| `home_image` | media | — | overrides default on the home page |
| `description_source` | radio | `metadesc-first` | / `introtext-first` |
| `description_length` | number | 200 | characters, word-boundary cut |
| `image_custom_field` | text | — | custom-field name to check for an image |
| `emit_article_meta` | radio yes/no | yes | the `article:*` tags |
| `emit_twitter` | radio yes/no | yes | Twitter Card block |
| `twitter_site` | text | — | `@handle` |
| `twitter_creator` | text | — | `@handle` |
| `fb_app_id` | text | — | `fb:app_id` |
| `og_locale` | text | `auto` | or `de_DE` etc. |
| `strip_query_params` | textarea | `utm_source,utm_medium,utm_campaign,utm_term,utm_content,fbclid,gclid,mc_cid,mc_eid` | removed from `og:url` |
| `override_mode` | radio | `only-if-missing` | / `always` |
| `fallback_to_logo` | radio yes/no | no | last-resort `og:image` = site logo |
| `excluded_components` | text | `com_icagenda` | comma list of `option` values to skip |
| `excluded_menu_items` | menuitem multiselect | — | |
| `debug` | radio yes/no | no | emit an HTML comment listing every decision |

All labels/descriptions via `PLG_OPENGRAPH_PARAM_*` keys; en-GB + de-DE shipped.

---

## 6. Edge cases to get right

- Article with **no image and no default** → no `og:image` (never fake it).
- Subfolder install / non-SEF / SEF → always absolute via `Uri::root()`.
- HTTPS behind a proxy → `Uri::getInstance()` honours `X-Forwarded-*` when
  Global Config *Behind Load Balancer* is on; document that dependency.
- `tmpl=component`, AJAX, RSS, sitemap XML → bailed by the type/format check.
- **Another OG producer already ran** (iCagenda on events; a future plugin) →
  `override_mode=only-if-missing` + `excluded_components` leaves it untouched.
- Title/description with entities, shortcodes, emoji, non-Latin → decode, strip,
  keep UTF-8, cut on word boundary, re-encode only via `setMetaData`.
- Very long category descriptions → same excerpt logic as articles.
- Multilingual site → correct `og:locale`; per-language `default_image` is **v2**.
- Home page that *is* a `com_content` featured/category view → treat as `home`
  (check `$menu->getActive()->home` first, before the component check).
- Plugin disabled / params empty → no output, no notices.

---

## 7. Test checklist

Content matrix (create fixtures):
- article: image_fulltext set / only image_intro / neither but `<img>` in body /
  nothing at all / image via custom field / remote image URL
- category blog page, featured page, home page, a contact page, a tag page, a 404

For each, verify:
- exactly **one** `og:image`
- `og:url` is a real, singly-encoded absolute URL (paste into a browser → loads)
- `og:type` = `article` only on single articles
- `twitter:card` = `summary_large_image` when image ≥ 300 px wide
- no PHP notices/warnings; every `<meta>` well-formed
- `tmpl=component` request → zero og/twitter tags
- `override_mode=only-if-missing` → an iCagenda event page is byte-identical to before

External validators: Facebook Sharing Debugger, X Card Validator, LinkedIn Post
Inspector, opengraph.xyz. Also `curl -s URL | grep -E 'og:|twitter:'`.

Multi-site reuse: install on a second, unrelated Joomla site, set only
`default_image`, confirm every page type gets sane tags with no per-site code.

---

## 8. Packaging & delivery

- Installable ZIP `plg_system_opengraph-<x.y.z>.zip`, manifest `method="upgrade"`.
- Optional self-hosted `update.xml` + `<updateservers>` in the manifest.
- Repo: `README.md` (install, every param explained, FAQ, screenshots),
  `CHANGELOG.md`, `LICENSE` (GPLv2+), `.github/workflows` build-zip action
  (optional).
- Language files complete for **en-GB** and **de-DE**.
- **v1.0.0 scope** = everything above **except**: on-the-fly image resizing
  (crop/pad to 1200×630, cache in `media/plg_system_opengraph/cache/`, serve
  that as `og:image`) and per-language default images — both explicitly **v1.1+**.

---

## 9. Concrete first target: replace TAGZ on empulsiv (w2026)

Context that motivated this spec (not part of the plugin, just the acceptance
case):

- Site currently runs **TAGZ 6.0.3 FREE** (`com_tagz` + `plg_system_tagz` +
  `pkg_tagz`, roosterz.nl). It produces article OG **with bugs**:
  `og:url` double-URL-encoded, `og:type=website` (FREE can't do `article`),
  **two** `og:image` tags (raw article image + a TAGZ-resized copy under
  `administrator/cache/preview/…`), literal `&amp;nbsp;` in `twitter:description`.
- Home page and all `com_content` list/blog pages: **no OG at all**.
- Event detail pages: clean OG from **iCagenda PRO**, independent of TAGZ —
  must stay untouched (hence `excluded_components` default `com_icagenda`).

Acceptance for the empulsiv rollout:
1. Install `plg_system_opengraph`, set `default_image` (a branded 1200×630),
   `og_locale=de_DE`, `twitter_site`/`twitter_creator` if they have handles.
2. Verify article, category, home, event pages per §7.
3. Remove `com_tagz` + `plg_system_tagz` + `pkg_tagz`
   (`php cli/joomla.php extension:remove <pkg id>`).
4. Record in that project's `DEPLOYMENT.md` (§G / a new §H) as a live delta.

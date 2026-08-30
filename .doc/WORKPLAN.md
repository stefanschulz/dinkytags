# DinkyTags — Arbeitsplan

Basis: [joomla-opengraph-plugin-SPEC.md](joomla-opengraph-plugin-SPEC.md). Referenz-Repo-Struktur:
`P:\dev\cuterweblinks` (ist ein *Modul*, DinkyTags wird ein *System-Plugin* — Repo-Konventionen
übernehmbar, Code-Skelett nicht).

## Festlegungen

| Aspekt | Wert |
|---|---|
| Element / Paket | `plg_system_dinkytags`, Gruppe `system`, Client `site` |
| Namespace | `TheLoom\Plugin\System\DinkyTags` (`<namespace path="src">`) |
| Sprach-Präfix | `PLG_SYSTEM_DINKYTAGS_*` |
| Lizenz | GPLv3-or-later — `LICENSE`/`LICENSE.txt` aus `cuterweblinks` übernehmen, die vorhandene Apache-2.0-`LICENSE` **ersetzen**; GPLv3+-Header in jede PHP-Datei (Spec sagt GPLv2+, wird bewusst auf v3+ angehoben) |
| Ziel | Joomla 5.1+ / 6.x, PHP 8.2+, `method="upgrade"` |
| Autor-Metadaten | The Loom / Stefan Schulz, `schulz@the-loom.de`, `https://www.the-loom.de` (analog Cuter Weblinks) |
| Update-Server | `https://www.the-loom.de/extensions/dinkytags/update.xml` (Muster aus `cuterweblinks`) |
| Repo-Layout | Plugin-Dateien im Repo-**Root** (wie `mod_cuterweblinks.*` bei Cuter Weblinks). Die vorhandene `.gitignore` ist eine Joomla-Root-Ignore-Liste → Entwicklung erfolgt in einer echten Joomla-Installation, nur die Plugin-Dateien werden getrackt. |

---

## Phase 0 — Repo-Gerüst

- `LICENSE` (Apache) löschen → `LICENSE` + `LICENSE.txt` (GPLv3) aus `cuterweblinks` kopieren.
- Verzeichnisbaum anlegen:
  ```
  dinkytags/
  ├── dinkytags.xml                       Manifest (method="upgrade", <namespace path="src">)
  ├── services/provider.php               DI-Registrierung
  ├── src/Extension/DinkyTags.php          SubscriberInterface, onBeforeCompileHead → inject()
  ├── src/Helper/ContextDetector.php       article|category|featured|home|other + Skip-Logik
  ├── src/Helper/ArticleLoader.php         Artikel-/Kategorie-Model ohne Zweitquery-Overhead, Tag-Fallback
  ├── src/Helper/ImageResolver.php         Auflösungsreihenfolge 3.3, Abs-URL, getimagesize-Cache
  ├── src/Helper/Text.php                  Excerpt / Entities / Whitespace / Wortgrenzen-Cut
  ├── src/Helper/MetaWriter.php            Dedup, override_mode, addCustomTag für article:tag, debug-Kommentar
  ├── language/en-GB/plg_system_dinkytags.ini + .sys.ini
  ├── language/de-DE/plg_system_dinkytags.ini + .sys.ini
  ├── build.xml                            Phing-Target „package" (aus cuterweblinks adaptiert)
  ├── README.md  CHANGELOG.md  .doc/ARCHITECTURE.md
  └── .releases/                           Build-Output (generiert)
  ```
- `README.md` erweitern (aktuell Einzeiler).

## Phase 1 — Manifest & Bootstrap

1. **`dinkytags.xml`**: `<extension type="plugin" group="system" method="upgrade">`, Metadaten,
   `<namespace>`, `<files>` (services, src, language), kein `<media>` in v1.0 (erst v1.1 mit
   Image-Cache), `<config>` (Phase 6), `<updateservers>`.
2. **`services/provider.php`**: DI-Pattern exakt wie Spec §4.2 — `PluginInterface` →
   `new DinkyTags(Dispatcher, PluginHelper::getPlugin('system','dinkytags'))`,
   `setApplication(Factory::getApplication())`.
3. **`src/Extension/DinkyTags.php`**: `final class … extends CMSPlugin implements SubscriberInterface`,
   `$autoloadLanguage = true`, `getSubscribedEvents(): ['onBeforeCompileHead' => 'inject']`.
4. **`inject(Event $event)`** — Skip-Kette (Spec §3.2, §4.2), früh raus:
   - nicht `isClient('site')` / Admin / CLI / API
   - `$doc->getType() !== 'html'`
   - `tmpl=component`, `format != html`, `print=1`
   - aktives Menü-Item in `excluded_menu_items`
   - `option` in `excluded_components` (Default `com_icagenda`)
   - Offline-Page, Error-Document
   - Plugin-Params leer → geräuschlos nichts tun

## Phase 2 — Kontext-Erkennung (`ContextDetector`)

- Reihenfolge: **Home zuerst** (`$menu->getActive()?->home === 1`) → `home`, auch wenn die Seite
  technisch `featured`/`category` ist (Spec §6).
- dann nach `option` + `view`: `com_content` + `article` → `article`; `+ category` → `category`;
  `+ featured` → `featured`; sonst → `other`.
- `viewEnabled($ctx)` gegen Param `enabled_views`.

## Phase 3 — Datenaufbau pro Kontext (`buildData` → assoziatives Array)

Gemeinsames Zielschema: `type, title, description, url, site_name, locale,
image{url,width,height,alt}, article{published,modified,section,tags[],author}`.

- **article** (Spec §3.3/§3.4/§4.3): Artikel via
  `bootComponent('com_content')->getMVCFactory()->createModel('Article','Site',['ignore_request'=>true])`,
  `setState('article.id'/'params')`, `getItem()`. Tags-Fallback über `#__contentitem_tag_map` +
  `#__tags` wenn `$item->tags` leer.
- **category**: Category-Model bzw. direkt `#__categories` (title, description, `params->image`).
  `og:type=website`.
- **featured**: Menü-/Seitentitel + Site-Description + `default_image`.
- **home**: Site-Name + Param-Description + `home_image` → `default_image`.
- **other**: `$doc->getTitle()` (Site-Name-Suffix strippen) + `$doc->getDescription()` +
  `default_image`.

## Phase 4 — Helfer

- **`Text`**: `excerpt($html, $len)` = `strip_tags` → `{…}`-Shortcodes raus →
  `html_entity_decode(ENT_QUOTES|ENT_HTML5,'UTF-8')` → Whitespace kollabieren → Cut an Wortgrenze
  bei `description_length` (Default 200) → `…` anhängen wenn geschnitten. **Kein** `content.prepare`.
- **`ImageResolver`**:
  - Auflösungsreihenfolge Spec §3.3 Punkte 1–7 (`image_fulltext` → `image_intro` → Custom-Field
    `image_custom_field` → erstes `<img src>` in `fulltext` dann `introtext` per Regex §4.4 →
    Kategoriebild → `default_image` → **nichts** außer `fallback_to_logo=1`).
  - Verarbeitung: `#joomlaImage://`-Suffix via `explode('#',$v,2)[0]`; `http(s)://`/`//` →
    unverändert; sonst root-relativ → absolut mit `Uri::root()` (Subfolder-fest, Ergebnis mit
    Scheme+Host); lokal: `@getimagesize(JPATH_ROOT.'/'…)` für w/h, **per Request cachen**, bei
    Fehler w/h weglassen.

## Phase 5 — Tag-Ausgabe (`MetaWriter`)

- **og:url** (Spec §3.6 — kritisch): vorhandenen Canonical aus `$doc->getHeadData()['links']`
  (`relation==='canonical'`) wiederverwenden; sonst
  `Uri::getInstance()->toString(['scheme','host','port','path','query'])`. Query-Parameter aus
  `strip_query_params` entfernen. **Klartext** an `setMetaData` — **niemals** `urlencode()` auf die
  ganze URL (der Bug der Vorgänger-Extension).
- **OG** via `setMetaData($k,$v,'property')`: `og:type, og:title, og:description, og:url,
  og:site_name, og:locale, og:image, og:image:width, og:image:height, og:image:alt`.
- **article:\*** wenn `emit_article_meta=1`: `article:published_time`/`modified_time` (ISO-8601 aus
  `publish_up`/`modified`), `article:section`, `article:author`; `article:tag` **mehrfach** via
  `$doc->addCustomTag('<meta property="article:tag" …>')` (nicht `setMetaData`, das überschreibt).
  `fb:app_id` wenn gesetzt.
- **Twitter** via `setMetaData($k,$v,'name')` wenn `emit_twitter=1`: `twitter:card` =
  `summary_large_image` bei Bildbreite ≥ 300 px oder unbekannt, sonst `summary`;
  `twitter:title/description/image/image:alt`; `twitter:site/creator` aus Params.
- **og:locale**: `str_replace('-','_',$app->getLanguage()->getTag())`, Param `og_locale`
  (`auto`|Override).
- **Dedup / `override_mode`**: bei `only-if-missing` vor `og:image`/`og:title`/`og:description`
  `$doc->getMetaData($k,'property')` prüfen, nicht-leer → überspringen. Alle Werte in **ein** Array
  bauen, jeden Key **einmal** schreiben.
- Idempotenz-Guard, falls `inject()` doppelt liefe (`addCustomTag` wird von Joomla nicht
  dedupliziert).

## Phase 6 — `config.xml` + Sprachdateien

- Alle 18 Params aus Spec §5 als `<fields name="params">` im `dinkytags.xml` (Typen: `checkboxes`
  für `enabled_views`, `media accept="image"`, `radio`, `number`, `text`, `textarea`,
  `menuitem`-Multiselect). Defaults exakt aus der Tabelle.
- Alle Labels/Beschreibungen als `PLG_SYSTEM_DINKYTAGS_PARAM_*` — vollständig in **en-GB und
  de-DE** (`.ini`), plus `.sys.ini` (Plugin-Name/Beschreibung). Jeder XML-referenzierte Key muss in
  beiden Sprachen existieren.

## Phase 7 — Debug-Modus

- Param `debug=yes` → am Ende von `inject()` einen HTML-Kommentar mit jeder Entscheidung ausgeben
  (erkannter Kontext, gewählte Bildquelle + finale URL, Description-Quelle, og:url-Quelle
  canonical/gebaut, ausgelassene Keys wegen `only-if-missing`).

## Phase 8 — Build-Tooling

- `build.xml` aus `cuterweblinks` adaptieren: `package`-Property `dinkytags`, `type=plugin`,
  `group=system`, `<element>` gemäß Joomla-Update-Konvention, `targetplatform`
  `5.(1|2|3|4)|6.(0|1|2)`, `php_minimum` `8.2`.
- Ausgabe: `.releases/plg_system_dinkytags-<x.y.z>.zip` + `update.xml` + sha256/384/512 (wie Cuter
  Weblinks).
- Optional: `.github/workflows/build.yml` (Zip-Action) — Spec markiert das als optional.

## Phase 9 — Dokumentation

- `README.md`: Installation, jeder Param erklärt, FAQ, `curl … | grep -E 'og:|twitter:'`-Beispiel,
  Hinweis „Behind Load Balancer" für HTTPS hinter Proxy (Spec §6).
- `CHANGELOG.md`: `1.0.0` Initial.
- `.doc/ARCHITECTURE.md` im Stil von `cuterweblinks/.doc/ARCHITECTURE.md` (Dateibaum, Request-Flow,
  Event-Begründung `onBeforeCompileHead`, Params-Tabelle, Edge Cases, Docker-Testverfahren).

## Phase 10 — Test & Verifikation (Spec §7)

- Docker-Wegwerf-Stack (Joomla-Source-Zip + PHP-apache + MySQL), Plugin-Zip via
  `cli/joomla.php extension:install`.
- Fixture-Matrix: Artikel mit `image_fulltext` / nur `image_intro` / nur `<img>` im Body / gar
  nichts / Custom-Field-Bild / Remote-URL; Kategorie-Blog, Featured, Home, Kontaktseite, Tag-Seite,
  404.
- Pro Seite prüfen: genau **ein** `og:image`; `og:url` einfach-kodierte absolute URL (im Browser
  ladbar); `og:type=article` nur bei Einzelartikel; `twitter:card=summary_large_image` bei Bild
  ≥ 300 px; keine PHP-Notices; `tmpl=component` → **null** og/twitter-Tags;
  `override_mode=only-if-missing` → iCagenda-Event-Seite byte-identisch.
- Externe Validatoren: Facebook Sharing Debugger, X Card Validator, LinkedIn Post Inspector,
  opengraph.xyz.
- Multi-Site-Reuse: zweite unabhängige Joomla-Instanz, nur `default_image` setzen, alle
  Seitentypen sinnvoll.

## Außerhalb Plugin-Scope (nur Abnahme, Spec §9)

empulsiv-Rollout: `default_image` + `og_locale=de_DE` + Twitter-Handles setzen, §7 verifizieren,
dann `com_tagz`/`plg_system_tagz`/`pkg_tagz` entfernen, in `DEPLOYMENT.md` dokumentieren. **Kein
Teil dieses Repos.**

## v1.1+ (nicht jetzt)

On-the-fly-Resize/Crop auf 1200×630 mit Cache in `media/plg_system_dinkytags/cache/`; per-Sprache
`default_image`.

---

**Reihenfolge der Umsetzung:** Phase 0 → 1 → 6 (Manifest/Params/Sprache zusammen, damit
installierbar) → 2 → 4 → 3 → 5 → 7 → 8 → 9 → 10.

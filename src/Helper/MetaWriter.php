<?php

/**
 * @package     TheLoom.Plugin
 * @subpackage  System.DinkyTags
 *
 * @copyright   Copyright (C) 2026 The Loom / Stefan Schulz. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * @link        https://www.the-loom.de
 */

namespace TheLoom\Plugin\System\DinkyTags\Helper;

use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * Writes the resolved payload to the document as Open Graph + Twitter Card meta.
 *
 * All values are built into one map and written once per key. Plain strings are
 * passed to setMetaData(); Joomla escapes them on render, so the whole URL is never
 * urlencode()d here.
 *
 * @since  1.0.0
 */
final class MetaWriter
{
    /**
     * Minimum resolved image width (px) for twitter:card = summary_large_image.
     *
     * @since  1.0.0
     */
    private const LARGE_IMAGE_MIN_WIDTH = 300;

    /**
     * Default strip_query_params value, mirroring the manifest.
     *
     * @since  1.0.0
     */
    private const DEFAULT_STRIP = 'utm_source,utm_medium,utm_campaign,utm_term,utm_content,fbclid,gclid,mc_cid,mc_eid';

    /**
     * og:* keys that override_mode=only-if-missing must not overwrite.
     *
     * @since  1.0.0
     */
    private const GUARDED = ['og:title', 'og:description', 'og:image'];

    /**
     * og:image side-channel keys that only make sense next to our own og:image.
     *
     * @since  1.0.0
     */
    private const IMAGE_DEPENDENTS = ['og:image:width', 'og:image:height', 'og:image:alt'];

    /**
     * Guards the repeated article:tag output against a double run within one request.
     *
     * @var    boolean
     * @since  1.0.0
     */
    private static bool $tagsWritten = false;

    /**
     * Human-readable log of every decision, for the debug comment.
     *
     * @var    string[]
     * @since  1.0.0
     */
    private array $decisions = [];

    /**
     * @param   HtmlDocument     $doc     The current document.
     * @param   SiteApplication  $app     The site application.
     * @param   Registry         $params  The plugin parameters.
     *
     * @since   1.0.0
     */
    public function __construct(
        private readonly HtmlDocument $doc,
        private readonly SiteApplication $app,
        private readonly Registry $params
    ) {
    }

    /**
     * Writes all tags for the given payload and returns the decision log.
     *
     * @param   array{type:string, title:string, description:string, image:array|null, article:array|null}  $data
     *
     * @return  string[]
     *
     * @since   1.0.0
     */
    public function write(array $data): array
    {
        $custom    = $this->customOpenGraph();
        $effective = $this->applyProperties($this->collectOpenGraph($data), $custom);
        $this->writeArticleMeta($data);
        $this->writeTwitter($data, $effective, $custom);

        return $this->decisions;
    }

    /**
     * Builds the ordered og:* map, dropping empty values.
     *
     * @param   array  $data  The payload.
     *
     * @return  array<string, string>
     *
     * @since   1.0.0
     */
    private function collectOpenGraph(array $data): array
    {
        $og = [
            'og:type'        => (string) ($data['type'] ?? 'website'),
            'og:title'       => trim((string) ($data['title'] ?? '')),
            'og:description' => trim((string) ($data['description'] ?? '')),
            'og:url'         => $this->resolveUrl(),
            'og:site_name'   => trim((string) $this->app->get('sitename', '')),
            'og:locale'      => $this->resolveLocale(),
        ];

        $image = $data['image'] ?? null;

        if (\is_array($image) && !empty($image['url'])) {
            $og['og:image'] = (string) $image['url'];

            if (!empty($image['width'])) {
                $og['og:image:width'] = (string) (int) $image['width'];
            }

            if (!empty($image['height'])) {
                $og['og:image:height'] = (string) (int) $image['height'];
            }

            $alt = trim((string) ($image['alt'] ?? ''));

            if ($alt !== '') {
                $og['og:image:alt'] = $alt;
            }
        }

        return array_filter($og, static fn ($value): bool => $value !== '');
    }

    /**
     * Writes the og:* map, honouring override_mode, and returns the values that are
     * effectively in force afterwards (ours, or a kept pre-existing one).
     *
     * @param   array<string, string>  $og      The og:* map.
     * @param   array<string, string>  $custom  og:* already present as custom tags.
     *
     * @return  array<string, string>
     *
     * @since   1.0.0
     */
    private function applyProperties(array $og, array $custom): array
    {
        $always        = (string) $this->params->get('override_mode', 'only-if-missing') === 'always';
        $custom        = $always ? [] : $custom;
        $effective     = [];
        $keepOtherImage = false;

        foreach ($og as $key => $value) {
            if (!$always) {
                // Spec: only-if-missing guards og:title / og:description / og:image
                // against a value set earlier via setMetaData().
                $existing = \in_array($key, self::GUARDED, true)
                    ? trim((string) $this->doc->getMetaData($key, 'property'))
                    : '';

                // Also keep *any* og:* another extension already emitted as a raw
                // custom <meta> tag (a component view using addCustomTag, e.g.
                // iCagenda) - getMetaData() cannot see those.
                if ($existing === '' && isset($custom[$key])) {
                    $existing = trim($custom[$key]);
                }

                if ($existing !== '') {
                    $effective[$key]  = $existing;
                    $keepOtherImage   = $keepOtherImage || $key === 'og:image';
                    $this->decisions[] = 'keep ' . $key . ' (set elsewhere)';

                    continue;
                }
            }

            if ($keepOtherImage && \in_array($key, self::IMAGE_DEPENDENTS, true)) {
                $this->decisions[] = 'skip ' . $key . ' (og:image kept from elsewhere)';

                continue;
            }

            $this->doc->setMetaData($key, $value, 'property');
            $effective[$key]  = $value;
            $this->decisions[] = 'set ' . $key;
        }

        return $effective;
    }

    /**
     * Collects og:* values already on the document as raw custom <meta> tags
     * (added via Document::addCustomTag, typically by a component view - e.g.
     * iCagenda's event view).
     *
     * Tolerant of attribute order, single/double quotes and whitespace around
     * "=", since the markup is authored by other extensions.
     *
     * @return  array<string, string>  Lower-cased og key => decoded content.
     *
     * @since   1.0.0
     */
    private function customOpenGraph(): array
    {
        $found = [];

        foreach ($this->doc->getHeadData()['custom'] ?? [] as $chunk) {
            if (!\is_string($chunk) || stripos($chunk, 'og:') === false) {
                continue;
            }

            if (!preg_match_all('/<meta\b[^>]*>/i', $chunk, $tags)) {
                continue;
            }

            foreach ($tags[0] as $tag) {
                if (
                    preg_match('/\bproperty\s*=\s*(["\'])\s*(og:[a-z:]+)\s*\1/i', $tag, $p)
                    && preg_match('/\bcontent\s*=\s*(["\'])(.*?)\1/is', $tag, $c)
                ) {
                    $found[strtolower($p[2])] = html_entity_decode($c[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }

        return $found;
    }

    /**
     * Writes fb:app_id (if set) and, for articles, the article:* block.
     *
     * @param   array  $data  The payload.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function writeArticleMeta(array $data): void
    {
        $fbAppId = trim((string) $this->params->get('fb_app_id', ''));

        if ($fbAppId !== '') {
            $this->doc->setMetaData('fb:app_id', $fbAppId, 'property');
            $this->decisions[] = 'set fb:app_id';
        }

        if (($data['type'] ?? '') !== 'article' || empty($data['article'])) {
            return;
        }

        $meta = $data['article'];

        $map = [
            'article:published_time' => $meta['published'] ?? null,
            'article:modified_time'  => $meta['modified'] ?? null,
            'article:section'        => $meta['section'] ?? null,
            'article:author'         => $meta['author'] ?? null,
        ];

        foreach ($map as $key => $value) {
            $value = trim((string) $value);

            if ($value !== '') {
                $this->doc->setMetaData($key, $value, 'property');
                $this->decisions[] = 'set ' . $key;
            }
        }

        $tags = array_values(
            array_unique(array_filter(array_map('trim', (array) ($meta['tags'] ?? [])), 'strlen'))
        );

        if ($tags !== [] && !self::$tagsWritten) {
            foreach ($tags as $tag) {
                $this->doc->addCustomTag(
                    '<meta property="article:tag" content="' . htmlspecialchars($tag, ENT_QUOTES, 'UTF-8') . '">'
                );
            }

            self::$tagsWritten = true;
            $this->decisions[] = \sprintf('add %d article:tag', \count($tags));
        }
    }

    /**
     * Writes the twitter:* block when emit_twitter is on.
     *
     * @param   array                  $data       The payload.
     * @param   array<string, string>  $effective  The og:* values in force.
     * @param   array<string, string>  $custom     og:* already present as custom tags.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function writeTwitter(array $data, array $effective, array $custom): void
    {
        if ((int) $this->params->get('emit_twitter', 1) !== 1) {
            return;
        }

        $ownImageUrl = \is_array($data['image'] ?? null) ? ($data['image']['url'] ?? null) : null;
        $effImage    = $effective['og:image'] ?? '';

        // Width: ours when the effective image is the one we resolved; otherwise a
        // width another extension published next to its own og:image; else unknown.
        if ($effImage !== '' && $effImage === $ownImageUrl) {
            $width = $data['image']['width'] ?? null;
        } elseif ($effImage !== '' && isset($custom['og:image:width'])) {
            $width = (int) $custom['og:image:width'] ?: null;
        } else {
            $width = null;
        }

        $card = ($width === null || $width >= self::LARGE_IMAGE_MIN_WIDTH) ? 'summary_large_image' : 'summary';

        $twitter = [
            'twitter:card'        => $card,
            'twitter:title'       => $effective['og:title'] ?? trim((string) ($data['title'] ?? '')),
            'twitter:description' => $effective['og:description'] ?? trim((string) ($data['description'] ?? '')),
        ];

        if ($effImage !== '') {
            $twitter['twitter:image'] = $effImage;
            $alt = $effective['og:image:alt']
                ?? trim((string) (\is_array($data['image'] ?? null) ? ($data['image']['alt'] ?? '') : ''));

            if ($alt !== '') {
                $twitter['twitter:image:alt'] = $alt;
            }
        }

        $twitter['twitter:site']    = $this->handle('twitter_site');
        $twitter['twitter:creator'] = $this->handle('twitter_creator');

        foreach ($twitter as $key => $value) {
            if (trim((string) $value) === '') {
                continue;
            }

            $this->doc->setMetaData($key, $value, 'name');
            $this->decisions[] = 'set ' . $key;
        }
    }

    /**
     * Resolves og:url: reuse the page canonical if present, else build from the
     * current request, then drop the configured tracking query parameters.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function resolveUrl(): string
    {
        $url = $this->canonicalFromHead();

        if ($url === '') {
            $url = Uri::getInstance()->toString(['scheme', 'host', 'port', 'path', 'query']);
            $this->decisions[] = 'og:url: built from request';
        } else {
            $this->decisions[] = 'og:url: reused page canonical';
        }

        return $this->stripQueryParams($url);
    }

    /**
     * Returns the href of the first rel="canonical" link already on the document.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function canonicalFromHead(): string
    {
        $links = $this->doc->getHeadData()['links'] ?? [];

        foreach ($links as $href => $link) {
            $relation = \is_array($link) ? ($link['relation'] ?? '') : '';

            if (strtolower((string) $relation) === 'canonical' && \is_string($href) && $href !== '') {
                return $href;
            }
        }

        return '';
    }

    /**
     * Removes the configured query keys from a URL, preserving the order and
     * encoding of the parameters that remain. Never touches the path.
     *
     * @param   string  $url  The URL.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function stripQueryParams(string $url): string
    {
        $strip = $this->csvParam('strip_query_params', self::DEFAULT_STRIP);

        if ($strip === [] || !str_contains($url, '?')) {
            return $url;
        }

        $fragment = '';

        if (str_contains($url, '#')) {
            [$url, $frag] = explode('#', $url, 2);
            $fragment     = '#' . $frag;
        }

        [$base, $queryString] = explode('?', $url, 2);
        $keep = [];

        foreach (explode('&', $queryString) as $pair) {
            if ($pair === '') {
                continue;
            }

            $name = urldecode(explode('=', $pair, 2)[0]);

            if (!\in_array($name, $strip, true)) {
                $keep[] = $pair;
            }
        }

        if ($keep === []) {
            return $base . $fragment;
        }

        return $base . '?' . implode('&', $keep) . $fragment;
    }

    /**
     * Resolves og:locale from the og_locale param. 'auto' derives it from the active
     * site language tag as language_TERRITORY, e.g. de-DE -> de_DE, and a language
     * tag with a script subtag such as zh-Hans-CN -> zh_CN (og:locale has no place
     * for the script).
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function resolveLocale(): string
    {
        $override = trim((string) $this->params->get('og_locale', 'auto'));

        if ($override !== '' && strtolower($override) !== 'auto') {
            return $override;
        }

        $parts = array_values(array_filter(explode('-', $this->app->getLanguage()->getTag()), 'strlen'));

        if ($parts === []) {
            return '';
        }

        if (\count($parts) === 1) {
            return $parts[0];
        }

        // First segment is the language; the last is the territory.
        return $parts[0] . '_' . strtoupper($parts[\count($parts) - 1]);
    }

    /**
     * Normalises an @handle parameter, adding the leading @ when missing.
     *
     * @param   string  $param  The parameter name.
     *
     * @return  string  The handle, or an empty string.
     *
     * @since   1.0.0
     */
    private function handle(string $param): string
    {
        $handle = trim((string) $this->params->get($param, ''));

        if ($handle === '') {
            return '';
        }

        return str_starts_with($handle, '@') ? $handle : '@' . $handle;
    }

    /**
     * Reads a comma-separated parameter into a trimmed, non-empty list.
     *
     * The default applies only when the parameter has never been saved; a cleared
     * field means an empty list. Registry::get() returns the default for an empty
     * string too, so read the raw params array to tell the two apart.
     *
     * @param   string  $name     The parameter name.
     * @param   string  $default  The default raw value for a never-saved config.
     *
     * @return  string[]
     *
     * @since   1.0.0
     */
    private function csvParam(string $name, string $default): array
    {
        $params = $this->params->toArray();
        $raw    = \array_key_exists($name, $params) ? (string) $params[$name] : $default;

        return array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
    }
}

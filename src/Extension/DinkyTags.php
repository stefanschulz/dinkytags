<?php

/**
 * @package     TheLoom.Plugin
 * @subpackage  System.DinkyTags
 *
 * @copyright   Copyright (C) 2026 The Loom / Stefan Schulz. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * @link        https://www.the-loom.de
 */

namespace TheLoom\Plugin\System\DinkyTags\Extension;

use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Document\ErrorDocument;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Input\Input;
use TheLoom\Plugin\System\DinkyTags\Helper\ArticleLoader;
use TheLoom\Plugin\System\DinkyTags\Helper\ContextDetector;
use TheLoom\Plugin\System\DinkyTags\Helper\ImageResolver;
use TheLoom\Plugin\System\DinkyTags\Helper\MetaWriter;
use TheLoom\Plugin\System\DinkyTags\Helper\Text;

\defined('_JEXEC') or die;

/**
 * Emits Open Graph and Twitter Card meta tags for frontend pages.
 *
 * Runs on onBeforeCompileHead: by then the component view has rendered (the article
 * model is populated, the canonical link is set) and the document is the final
 * HtmlDocument that is about to be serialised.
 *
 * @since  1.0.0
 */
final class DinkyTags extends CMSPlugin implements SubscriberInterface
{
    /**
     * Load the plugin language files automatically.
     *
     * @var    boolean
     * @since  1.0.0
     */
    protected $autoloadLanguage = true;

    /**
     * Human-readable log of every decision this run, emitted as an HTML comment when
     * the debug parameter is on.
     *
     * @var    string[]
     * @since  1.0.0
     */
    private array $log = [];

    /**
     * Returns the events this subscriber listens to.
     *
     * @return  array<string, string>
     *
     * @since   1.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return ['onBeforeCompileHead' => 'inject'];
    }

    /**
     * Builds and writes the social meta tags for the current request, unless a skip
     * condition applies. When the debug parameter is on, an HTML comment listing
     * every decision is appended regardless of outcome.
     *
     * @param   Event  $event  The onBeforeCompileHead event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function inject(Event $event): void
    {
        $app = $this->getApplication();

        // Site client only - also rules out administrator, CLI and API applications.
        if (!$app instanceof SiteApplication) {
            return;
        }

        $doc = $app->getDocument();

        // HTML frontend documents only; never the error page.
        if (!$doc instanceof HtmlDocument || $doc instanceof ErrorDocument) {
            return;
        }

        $this->log = [];

        try {
            $this->process($app, $doc);
        } catch (\Throwable $e) {
            // A page render must never fail because of a meta tag - bail silently.
            $this->log[] = 'aborted: ' . $e->getMessage();
        }

        if ((int) $this->params->get('debug', 0) === 1) {
            $this->emitDebugComment($doc);
        }
    }

    /**
     * Runs the skip checks, then builds and writes the payload, recording each
     * decision in $this->log.
     *
     * @param   SiteApplication  $app  The site application.
     * @param   HtmlDocument     $doc  The current document.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function process(SiteApplication $app, HtmlDocument $doc): void
    {
        $input = $app->getInput();

        // Non-page renders: module/component tmpl, non-HTML formats, print view.
        if (
            $input->getCmd('tmpl') === 'component'
            || strtolower((string) $input->getCmd('format', 'html')) !== 'html'
            || $input->getInt('print') === 1
        ) {
            $this->log[] = 'skip: non-page request (tmpl/format/print)';

            return;
        }

        // Offline site: the offline page is not a page we want to describe.
        if ($app->get('offline')) {
            $this->log[] = 'skip: site is offline';

            return;
        }

        // Component opt-out list (default: com_icagenda emits its own clean OG).
        $option = (string) $input->getCmd('option');

        if ($option !== '' && \in_array($option, $this->listParam('excluded_components', 'com_icagenda'), true)) {
            $this->log[] = 'skip: excluded component "' . $option . '"';

            return;
        }

        // Menu-item opt-out list.
        $active        = $app->getMenu()?->getActive();
        $excludedItems = array_map('intval', (array) $this->params->get('excluded_menu_items', []));

        if ($active !== null && \in_array((int) $active->id, $excludedItems, true)) {
            $this->log[] = 'skip: excluded menu item ' . (int) $active->id;

            return;
        }

        // Which context is this request, and is it switched on?
        $context = ContextDetector::detect($app, $input);
        $this->log[] = 'context: ' . $context;

        if (!$this->viewEnabled($context)) {
            $this->log[] = 'skip: context not in enabled_views';

            return;
        }

        $data = $this->buildData($context, $app, $input, $doc);

        $this->log[] = 'og:type: ' . $data['type'];
        $this->log[] = 'title: ' . $this->clip($data['title']);
        $this->log[] = 'description: ' . mb_strlen($data['description'], 'UTF-8') . ' chars - ' . $this->clip($data['description']);
        $this->log[] = $data['image'] === null
            ? 'image: none emitted'
            : 'image: ' . $data['image']['url'] . ' ('
                . ($data['image']['width'] ? $data['image']['width'] . 'x' . $data['image']['height'] : 'size unknown') . ')';

        foreach ((new MetaWriter($doc, $app, $this->params))->write($data) as $line) {
            $this->log[] = $line;
        }
    }

    /**
     * Appends the decision log as a single HTML comment.
     *
     * @param   HtmlDocument  $doc  The current document.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function emitDebugComment(HtmlDocument $doc): void
    {
        $lines = $this->log === [] ? ['no decisions recorded'] : $this->log;

        // "--" may not appear inside an HTML comment.
        $body = str_replace(['--', '>'], ['- -', '&gt;'], implode("\n", $lines));

        $doc->addCustomTag("<!-- Dinky Tags debug\n" . $body . "\n-->");
    }

    /**
     * Collapses whitespace and truncates a value for the debug log.
     *
     * @param   string   $value  The value.
     * @param   integer  $max    Maximum length.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function clip(string $value, int $max = 140): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return mb_strlen($value, 'UTF-8') > $max ? mb_substr($value, 0, $max, 'UTF-8') . "\u{2026}" : $value;
    }

    /**
     * Tests whether the given context is switched on in the plugin parameters.
     *
     * @param   string  $context  One of ContextDetector::CONTEXTS.
     *
     * @return  boolean
     *
     * @since   1.0.0
     */
    private function viewEnabled(string $context): bool
    {
        $enabled = $this->params->get('enabled_views', ContextDetector::CONTEXTS);

        if (\is_string($enabled)) {
            $enabled = explode(',', $enabled);
        }

        return \in_array($context, array_map('trim', (array) $enabled), true);
    }

    /**
     * Builds the tag payload for a context.
     *
     * @param   string           $context  One of ContextDetector::CONTEXTS.
     * @param   SiteApplication  $app      The site application.
     * @param   Input            $input    The request input.
     * @param   HtmlDocument     $doc      The current document.
     *
     * @return  array{type:string, title:string, description:string, image:array|null, article:array|null}
     *
     * @since   1.0.0
     */
    private function buildData(string $context, SiteApplication $app, Input $input, HtmlDocument $doc): array
    {
        return match ($context) {
            'article'  => $this->buildArticle($app, $input, $doc),
            'category' => $this->buildCategory($app, $input, $doc),
            'home'     => $this->buildHome($app, $doc),
            'featured' => $this->buildSimple(
                $this->menuTitle($app) ?: $this->pageTitle($app, $doc),
                $this->siteDescription($app),
                $this->params->get('default_image')
            ),
            default    => $this->buildSimple(
                $this->pageTitle($app, $doc),
                (string) $doc->getDescription() !== '' ? (string) $doc->getDescription() : $this->siteDescription($app),
                $this->params->get('default_image')
            ),
        };
    }

    /**
     * Assembles a generic (og:type=website) payload from a title, description and
     * raw image reference.
     *
     * @param   string       $title        The title.
     * @param   string       $description  The description (cleaned and cut here).
     * @param   string|null  $imageRaw     A raw image reference, or null/empty.
     * @param   string       $type         The og:type value.
     *
     * @return  array{type:string, title:string, description:string, image:array|null, article:null}
     *
     * @since   1.0.0
     */
    private function buildSimple(string $title, string $description, ?string $imageRaw, string $type = 'website'): array
    {
        $image = ImageResolver::resolve($imageRaw !== null && $imageRaw !== '' ? $imageRaw : null);

        if ($image !== null) {
            $image['alt'] = $title;
        }

        return [
            'type'        => $type,
            'title'       => $title,
            'description' => Text::excerpt($description, $this->descriptionLength()),
            'image'       => $image,
            'article'     => null,
        ];
    }

    /**
     * Builds the payload for a single article.
     *
     * @param   SiteApplication  $app    The site application.
     * @param   Input            $input  The request input.
     * @param   HtmlDocument     $doc    The current document.
     *
     * @return  array{type:string, title:string, description:string, image:array|null, article:array|null}
     *
     * @since   1.0.0
     */
    private function buildArticle(SiteApplication $app, Input $input, HtmlDocument $doc): array
    {
        $id   = $input->getInt('id', 0);
        $item = ArticleLoader::article($app, $id);

        if ($item === null) {
            $this->log[] = 'article ' . $id . ' could not be loaded - falling back to page/site data';

            return $this->buildSimple(
                $this->pageTitle($app, $doc),
                $this->siteDescription($app),
                $this->params->get('default_image')
            );
        }

        $title = trim((string) ($item->title ?? '')) ?: $this->pageTitle($app, $doc);

        $length   = $this->descriptionLength();
        $metadesc = Text::excerpt((string) ($item->metadesc ?? ''), $length);
        $intro    = Text::excerpt((string) ($item->introtext ?? ''), $length);

        if ((string) $this->params->get('description_source', 'metadesc-first') === 'introtext-first') {
            $description  = $intro !== '' ? $intro : $metadesc;
            $this->log[]  = 'description source: ' . ($intro !== '' ? 'introtext' : 'metadesc (introtext empty)');
        } else {
            $description  = $metadesc !== '' ? $metadesc : $intro;
            $this->log[]  = 'description source: ' . ($metadesc !== '' ? 'metadesc' : 'introtext (metadesc empty)');
        }

        $image = ImageResolver::resolve($this->resolveArticleImage($app, $item, $id));

        if ($image !== null) {
            $image['alt'] = $title;
        }

        $articleMeta = null;

        if ((int) $this->params->get('emit_article_meta', 1) === 1) {
            $section = trim((string) ($item->category_title ?? ''));

            $articleMeta = [
                'published' => $this->iso($item->publish_up ?? null),
                'modified'  => $this->iso($item->modified ?? null),
                'section'   => $section !== '' ? $section : null,
                'tags'      => ArticleLoader::tags($item, $id),
                'author'    => $this->authorName($item),
            ];
        }

        return [
            'type'        => 'article',
            'title'       => $title,
            'description' => $description,
            'image'       => $image,
            'article'     => $articleMeta,
        ];
    }

    /**
     * Builds the payload for a category blog / list view.
     *
     * @param   SiteApplication  $app    The site application.
     * @param   Input            $input  The request input.
     * @param   HtmlDocument     $doc    The current document.
     *
     * @return  array{type:string, title:string, description:string, image:array|null, article:null}
     *
     * @since   1.0.0
     */
    private function buildCategory(SiteApplication $app, Input $input, HtmlDocument $doc): array
    {
        $category = ArticleLoader::category($input->getInt('id', 0));

        if ($category === null) {
            $this->log[] = 'category ' . $input->getInt('id', 0) . ' could not be loaded - falling back to page/site data';

            return $this->buildSimple(
                $this->pageTitle($app, $doc),
                $this->siteDescription($app),
                $this->params->get('default_image')
            );
        }

        $title        = trim((string) ($category->title ?? '')) ?: $this->pageTitle($app, $doc);
        $description   = trim((string) ($category->description ?? ''));
        $categoryImage = $this->categoryImage($category->params ?? null);
        $imageRaw      = $categoryImage ?? (trim((string) $this->params->get('default_image', '')) ?: null);
        $this->log[]   = 'image source: ' . ($categoryImage !== null ? 'category image' : 'plugin default_image');

        return $this->buildSimple(
            $title,
            $description !== '' ? $description : $this->siteDescription($app),
            $imageRaw
        );
    }

    /**
     * Builds the payload for the site home page.
     *
     * @param   SiteApplication  $app  The site application.
     * @param   HtmlDocument     $doc  The current document.
     *
     * @return  array{type:string, title:string, description:string, image:array|null, article:null}
     *
     * @since   1.0.0
     */
    private function buildHome(SiteApplication $app, HtmlDocument $doc): array
    {
        $homeImage = trim((string) $this->params->get('home_image', ''));
        $imageRaw  = $homeImage !== '' ? $homeImage : (trim((string) $this->params->get('default_image', '')) ?: null);

        return $this->buildSimple(
            trim((string) $app->get('sitename', '')) ?: $this->pageTitle($app, $doc),
            $this->siteDescription($app),
            $imageRaw
        );
    }

    /**
     * Resolves the raw image reference for an article, following the spec order:
     * fulltext image, intro image, custom field, first inline <img>, category image,
     * plugin default, then optionally the site logo.
     *
     * @param   SiteApplication  $app   The site application.
     * @param   object           $item  The loaded article item.
     * @param   integer          $id    The article id.
     *
     * @return  string|null
     *
     * @since   1.0.0
     */
    private function resolveArticleImage(SiteApplication $app, object $item, int $id): ?string
    {
        $images = json_decode((string) ($item->images ?? ''));

        if (\is_object($images)) {
            foreach (['image_fulltext', 'image_intro'] as $key) {
                $candidate = trim((string) ($images->$key ?? ''));

                if ($candidate !== '') {
                    $this->log[] = 'image source: article ' . $key;

                    return $candidate;
                }
            }
        }

        $fieldName = trim((string) $this->params->get('image_custom_field', ''));

        if ($fieldName !== '') {
            $fromField = $this->normalizeMediaValue(ArticleLoader::customFieldValue($id, $fieldName));

            if ($fromField !== null) {
                $this->log[] = 'image source: custom field "' . $fieldName . '"';

                return $fromField;
            }
        }

        $inline = ImageResolver::firstImageSrc((string) ($item->fulltext ?? ''), (string) ($item->introtext ?? ''));

        if ($inline !== null && $inline !== '') {
            $this->log[] = 'image source: first inline <img> in body';

            return $inline;
        }

        if (!empty($item->catid)) {
            $category = ArticleLoader::category((int) $item->catid);

            if ($category !== null) {
                $categoryImage = $this->categoryImage($category->params ?? null);

                if ($categoryImage !== null) {
                    $this->log[] = 'image source: category ' . (int) $item->catid . ' image';

                    return $categoryImage;
                }
            }
        }

        $default = trim((string) $this->params->get('default_image', ''));

        if ($default !== '') {
            $this->log[] = 'image source: plugin default_image';

            return $default;
        }

        if ((int) $this->params->get('fallback_to_logo', 0) === 1) {
            $logo = $this->siteLogo($app);
            $this->log[] = $logo !== null ? 'image source: site logo (fallback_to_logo)' : 'image source: none (logo not found)';

            return $logo;
        }

        $this->log[] = 'image source: none (no image, no default)';

        return null;
    }

    /**
     * Normalises a media-field stored value (plain path or {"imagefile":"..."} JSON)
     * to a bare path.
     *
     * @param   string|null  $value  The stored value.
     *
     * @return  string|null
     *
     * @since   1.0.0
     */
    private function normalizeMediaValue(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        if (\is_array($decoded)) {
            $path = trim((string) ($decoded['imagefile'] ?? $decoded['image'] ?? ''));

            return $path !== '' ? $path : null;
        }

        return $value;
    }

    /**
     * Extracts the image path from a category's params JSON.
     *
     * @param   string|null  $paramsJson  The raw #__categories.params value.
     *
     * @return  string|null
     *
     * @since   1.0.0
     */
    private function categoryImage(?string $paramsJson): ?string
    {
        if ($paramsJson === null || $paramsJson === '') {
            return null;
        }

        $decoded = json_decode($paramsJson, true);
        $image   = \is_array($decoded) ? trim((string) ($decoded['image'] ?? '')) : '';

        return $image !== '' ? $image : null;
    }

    /**
     * Best-effort lookup of the active template's logo file.
     *
     * @param   SiteApplication  $app  The site application.
     *
     * @return  string|null
     *
     * @since   1.0.0
     */
    private function siteLogo(SiteApplication $app): ?string
    {
        try {
            $params = $app->getTemplate(true)->params ?? null;

            if ($params !== null) {
                foreach (['logoFile', 'logo', 'brandLogo'] as $key) {
                    $logo = trim((string) $params->get($key, ''));

                    if ($logo !== '') {
                        return $logo;
                    }
                }
            }
        } catch (\Throwable $e) {
            // No logo available - fall through.
        }

        return null;
    }

    /**
     * Converts a Joomla datetime string to ISO-8601, or null when empty/invalid.
     *
     * @param   string|null  $value  The datetime string (assumed UTC).
     *
     * @return  string|null
     *
     * @since   1.0.0
     */
    private function iso(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        try {
            return (new Date($value))->toISO8601();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Returns the display author name for an article.
     *
     * @param   object  $item  The article item.
     *
     * @return  string|null
     *
     * @since   1.0.0
     */
    private function authorName(object $item): ?string
    {
        foreach (['created_by_alias', 'author'] as $key) {
            $name = trim((string) ($item->$key ?? ''));

            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }

    /**
     * The active menu item's title, or an empty string.
     *
     * @param   SiteApplication  $app  The site application.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function menuTitle(SiteApplication $app): string
    {
        return trim((string) ($app->getMenu()?->getActive()->title ?? ''));
    }

    /**
     * The document title with the site-name affix stripped, per Global Config's
     * "Include Site Name in Page Titles" setting (handles both before and after).
     *
     * @param   SiteApplication  $app  The site application.
     * @param   HtmlDocument     $doc  The current document.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function pageTitle(SiteApplication $app, HtmlDocument $doc): string
    {
        $title    = trim((string) $doc->getTitle());
        $siteName = trim((string) $app->get('sitename', ''));

        if ($siteName === '' || $title === '') {
            return $title;
        }

        if (str_ends_with($title, ' - ' . $siteName)) {
            return trim(substr($title, 0, -\strlen(' - ' . $siteName)));
        }

        if (str_starts_with($title, $siteName . ' - ')) {
            return trim(substr($title, \strlen($siteName . ' - ')));
        }

        return $title;
    }

    /**
     * The site meta description from Global Configuration.
     *
     * @param   SiteApplication  $app  The site application.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function siteDescription(SiteApplication $app): string
    {
        return (string) $app->get('MetaDesc', '');
    }

    /**
     * The configured description length, floored at 0.
     *
     * @return  integer
     *
     * @since   1.0.0
     */
    private function descriptionLength(): int
    {
        return max(0, (int) $this->params->get('description_length', 200));
    }

    /**
     * Reads a comma-separated plugin parameter into a trimmed, non-empty list.
     *
     * @param   string  $name     The parameter name.
     * @param   string  $default  The default raw value.
     *
     * @return  string[]
     *
     * @since   1.0.0
     */
    private function listParam(string $name, string $default = ''): array
    {
        $raw = (string) $this->params->get($name, $default);

        return array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
    }
}

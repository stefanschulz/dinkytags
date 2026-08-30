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
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Data access for the article and category contexts.
 *
 * Uses the com_content site model for the article (so no second heavy query and the
 * same access rules apply) and small direct queries for the bits the model does not
 * reliably carry (tags, category row, custom field value).
 *
 * A failure here degrades to a generic payload rather than breaking the page, but is
 * logged to the 'plg_system_dinkytags' category (silent unless an admin configures a
 * logger for it) so a real DB problem is not completely invisible.
 *
 * @since  1.0.0
 */
final class ArticleLoader
{
    /**
     * Logs a caught data-access failure without letting it surface.
     *
     * @param   string      $what  Short description of the operation that failed.
     * @param   \Throwable   $e     The caught exception.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private static function warn(string $what, \Throwable $e): void
    {
        Log::add('DinkyTags: ' . $what . ' failed - ' . $e->getMessage(), Log::WARNING, 'plg_system_dinkytags');
    }

    /**
     * Loads a single published article via the com_content site model.
     *
     * @param   SiteApplication  $app  The site application.
     * @param   integer          $id   The article id.
     *
     * @return  object|null  The article item, or null when it cannot be loaded.
     *
     * @since   1.0.0
     */
    public static function article(SiteApplication $app, int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        try {
            $model = $app->bootComponent('com_content')
                ->getMVCFactory()
                ->createModel('Article', 'Site', ['ignore_request' => true]);

            if (!$model) {
                return null;
            }

            $model->setState('article.id', $id);
            $model->setState('params', $app->getParams());

            $item = $model->getItem();
        } catch (\Throwable $e) {
            self::warn('article ' . $id . ' load', $e);

            return null;
        }

        return \is_object($item) ? $item : null;
    }

    /**
     * Returns the titles of the tags assigned to an article.
     *
     * Prefers whatever the model already populated on $item->tags; falls back to a
     * direct map query.
     *
     * @param   object   $item  The loaded article item.
     * @param   integer  $id    The article id (for the fallback query).
     *
     * @return  string[]
     *
     * @since   1.0.0
     */
    public static function tags(object $item, int $id): array
    {
        $titles = [];

        if (!empty($item->tags)) {
            $collection = $item->tags->itemTags ?? $item->tags;

            if (is_iterable($collection)) {
                foreach ($collection as $tag) {
                    $title = \is_object($tag) ? ($tag->title ?? null) : null;

                    if (\is_string($title) && $title !== '') {
                        $titles[] = $title;
                    }
                }
            }
        }

        if ($titles !== []) {
            return $titles;
        }

        try {
            $db    = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select($db->quoteName('t.title'))
                ->from($db->quoteName('#__contentitem_tag_map', 'm'))
                ->join(
                    'INNER',
                    $db->quoteName('#__tags', 't') . ' ON ' . $db->quoteName('t.id') . ' = ' . $db->quoteName('m.tag_id')
                )
                ->where($db->quoteName('m.type_alias') . ' = ' . $db->quote('com_content.article'))
                ->where($db->quoteName('m.content_item_id') . ' = :id')
                ->bind(':id', $id, ParameterType::INTEGER);
            $db->setQuery($query);

            return array_values(array_filter(array_map('strval', (array) $db->loadColumn())));
        } catch (\RuntimeException $e) {
            self::warn('tag query for article ' . $id, $e);

            return [];
        }
    }

    /**
     * Loads title, description and params for a content category.
     *
     * @param   integer  $id  The category id.
     *
     * @return  object|null
     *
     * @since   1.0.0
     */
    public static function category(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        try {
            $db    = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select($db->quoteName(['title', 'description', 'params']))
                ->from($db->quoteName('#__categories'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $id, ParameterType::INTEGER);
            $db->setQuery($query);
            $row = $db->loadObject();
        } catch (\RuntimeException $e) {
            self::warn('category ' . $id . ' query', $e);

            return null;
        }

        return \is_object($row) ? $row : null;
    }

    /**
     * Reads the stored value of a com_content custom field by field name.
     *
     * @param   integer  $articleId  The article id.
     * @param   string   $fieldName  The field's machine name (not its label).
     *
     * @return  string|null  The raw stored value, or null when unset/empty.
     *
     * @since   1.0.0
     */
    public static function customFieldValue(int $articleId, string $fieldName): ?string
    {
        if ($articleId <= 0 || $fieldName === '') {
            return null;
        }

        try {
            $db    = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select($db->quoteName('fv.value'))
                ->from($db->quoteName('#__fields', 'f'))
                ->join(
                    'INNER',
                    $db->quoteName('#__fields_values', 'fv') . ' ON ' . $db->quoteName('fv.field_id') . ' = ' . $db->quoteName('f.id')
                )
                ->where($db->quoteName('f.context') . ' = ' . $db->quote('com_content.article'))
                ->where($db->quoteName('f.name') . ' = :name')
                ->where($db->quoteName('fv.item_id') . ' = :id')
                ->bind(':name', $fieldName)
                ->bind(':id', $articleId)
                ->setLimit(1);
            $db->setQuery($query);
            $value = $db->loadResult();
        } catch (\RuntimeException $e) {
            self::warn('custom field "' . $fieldName . '" query for article ' . $articleId, $e);

            return null;
        }

        $value = \is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }
}

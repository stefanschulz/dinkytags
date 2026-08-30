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
use Joomla\Input\Input;

\defined('_JEXEC') or die;

/**
 * Classifies a frontend request into one of the contexts the plugin can describe.
 *
 * @since  1.0.0
 */
final class ContextDetector
{
    /**
     * Every context this plugin knows how to build tags for.
     *
     * @var    string[]
     * @since  1.0.0
     */
    public const CONTEXTS = ['article', 'category', 'featured', 'home', 'other'];

    /**
     * Returns the context for the current request.
     *
     * The com_content view is honoured first, so an article or category reached
     * without its own menu item (and therefore rendered under the default / home
     * Itemid) is still classified as `article` / `category` rather than `home`.
     * A home menu item only yields `home` when the request really is the front page:
     * its own featured / category view (or a non-com_content home), with no `id` and
     * the same component as the menu item.
     *
     * @param   SiteApplication  $app    The site application.
     * @param   Input            $input  The request input.
     *
     * @return  string  One of self::CONTEXTS.
     *
     * @since   1.0.0
     */
    public static function detect(SiteApplication $app, Input $input): string
    {
        $active = $app->getMenu()?->getActive();
        $option = $input->getCmd('option');
        $view   = $input->getCmd('view');

        $isHome = $active !== null
            && (int) ($active->home ?? 0) === 1
            && $input->getInt('id', 0) === 0
            && ($option === '' || $option === ($active->query['option'] ?? 'com_content'));

        if ($option === 'com_content') {
            if ($view === 'article') {
                return 'article';
            }

            if ($view === 'category') {
                return $isHome ? 'home' : 'category';
            }

            if ($view === 'featured') {
                return $isHome ? 'home' : 'featured';
            }
        }

        return $isHome ? 'home' : 'other';
    }
}

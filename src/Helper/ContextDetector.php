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
     * Home is checked first: a home menu item wins even when it technically points at
     * a com_content featured or category view (spec section 6).
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
        $menu   = $app->getMenu();
        $active = $menu ? $menu->getActive() : null;

        if ($active !== null && (int) ($active->home ?? 0) === 1) {
            return 'home';
        }

        if ($input->getCmd('option') === 'com_content') {
            return match ($input->getCmd('view')) {
                'article'  => 'article',
                'category' => 'category',
                'featured' => 'featured',
                default    => 'other',
            };
        }

        return 'other';
    }
}

<?php

/**
 * @package     TheLoom.Plugin
 * @subpackage  System.DinkyTags
 *
 * @copyright   Copyright (C) 2026 The Loom / Stefan Schulz. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * @link        https://www.the-loom.de
 */

// The helpers under test guard themselves with `defined('_JEXEC') or die`, the way every
// file in a Joomla extension does. They need nothing else from the CMS, which is exactly
// why they are the part of this plugin that can be unit tested at all - everything that
// touches the document, the application or the database is covered by the .docker stack.
// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or \define('_JEXEC', 1);

require_once __DIR__ . '/../vendor/autoload.php';
// phpcs:enable PSR1.Files.SideEffects

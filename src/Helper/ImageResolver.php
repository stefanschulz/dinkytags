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

use Joomla\CMS\Uri\Uri;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Turns a raw image reference (article images JSON value, custom field, category
 * param, inline <img>, plugin default) into an absolute URL plus, for local files,
 * pixel dimensions.
 *
 * @since  1.0.0
 */
final class ImageResolver
{
    /**
     * Per-request getimagesize() cache, keyed by absolute filesystem path.
     *
     * @var    array<string, array{0:int, 1:int}|null>
     * @since  1.0.0
     */
    private static array $sizeCache = [];

    /**
     * Resolves a raw value to a shareable image.
     *
     * @param   string|null  $raw  The raw reference, or null/empty for "no image".
     *
     * @return  array{url:string, width:int|null, height:int|null}|null
     *
     * @since   1.0.0
     */
    public static function resolve(?string $raw): ?array
    {
        $value = $raw === null ? '' : trim($raw);

        if ($value === '') {
            return null;
        }

        // Strip Joomla's image-attributes suffix: "images/x.jpg#joomlaImage://...".
        $value = trim(explode('#', $value, 2)[0]);

        if ($value === '') {
            return null;
        }

        // Remote or protocol-relative: use as-is, but guarantee a scheme so og:image
        // is always an absolute URL with scheme + host.
        if (preg_match('#^(https?:)?//#i', $value)) {
            if (str_starts_with($value, '//')) {
                $value = (Uri::getInstance()->getScheme() ?: 'https') . ':' . $value;
            }

            return ['url' => $value, 'width' => null, 'height' => null];
        }

        // Local: treat as root-relative and make absolute via Uri::root() so it also
        // works on subfolder installs.
        $rel  = ltrim($value, '/');
        $size = self::localImageSize($rel);

        return [
            'url'    => rtrim(Uri::root(), '/') . '/' . $rel,
            'width'  => $size[0] ?? null,
            'height' => $size[1] ?? null,
        ];
    }

    /**
     * Finds the first <img src="..."> in the given HTML chunks, in order.
     *
     * @param   string  ...$htmlChunks  HTML fragments to scan (e.g. fulltext, introtext).
     *
     * @return  string|null  The decoded src attribute, or null if none was found.
     *
     * @since   1.0.0
     */
    public static function firstImageSrc(string ...$htmlChunks): ?string
    {
        foreach ($htmlChunks as $html) {
            if ($html !== '' && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) {
                return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            }
        }

        return null;
    }

    /**
     * Reads pixel dimensions of a local image, cached per request.
     *
     * @param   string  $rel  Root-relative path (no leading slash).
     *
     * @return  array{0:int, 1:int}|null
     *
     * @since   1.0.0
     */
    private static function localImageSize(string $rel): ?array
    {
        $path = JPATH_ROOT . '/' . $rel;

        if (\array_key_exists($path, self::$sizeCache)) {
            return self::$sizeCache[$path];
        }

        $result = null;

        if (is_file($path)) {
            $info = @getimagesize($path);

            if ($info !== false && isset($info[0], $info[1])) {
                $result = [(int) $info[0], (int) $info[1]];
            }
        }

        return self::$sizeCache[$path] = $result;
    }
}

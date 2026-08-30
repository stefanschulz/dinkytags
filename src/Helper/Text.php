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

\defined('_JEXEC') or die;

/**
 * Small text utilities for turning HTML content into a clean meta description.
 *
 * Deliberately does not use HTMLHelper's content.prepare - that runs every content
 * plugin and is far too heavy for a meta tag.
 *
 * @since  1.0.0
 */
final class Text
{
    /**
     * Turns an HTML fragment into a plain, single-line excerpt.
     *
     * Strips tags and {...} shortcodes, decodes entities (including the double-encoded
     * &amp;nbsp; seen in the wild), collapses whitespace, then cuts on a word boundary
     * at $maxLength characters, appending an ellipsis when the text was shortened.
     *
     * @param   string  $html       The source HTML (or plain text).
     * @param   integer $maxLength   Maximum length in characters; <= 0 disables the cut.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    public static function excerpt(string $html, int $maxLength): string
    {
        // strip_tags() removes the <script>/<style> tags but keeps their text
        // content - drop those blocks whole first.
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $html) ?? $html;
        $text = strip_tags($text);
        $text = preg_replace('/\{[^}]*\}/u', '', $text) ?? $text;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Catch entities that were encoded twice at the source (&amp;nbsp; -> &nbsp;).
        $text = str_ireplace(['&nbsp;', '&#160;', "\xC2\xA0"], ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        if ($maxLength <= 0 || mb_strlen($text, 'UTF-8') <= $maxLength) {
            return $text;
        }

        return self::cutOnWordBoundary($text, $maxLength) . "\u{2026}";
    }

    /**
     * Cuts a string to at most $maxLength characters without splitting a word.
     *
     * @param   string   $text       The already-cleaned text.
     * @param   integer  $maxLength   Maximum length in characters.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private static function cutOnWordBoundary(string $text, int $maxLength): string
    {
        $slice    = mb_substr($text, 0, $maxLength, 'UTF-8');
        $lastGap  = mb_strrpos($slice, ' ', 0, 'UTF-8');

        if ($lastGap !== false && $lastGap > 0) {
            $slice = mb_substr($slice, 0, $lastGap, 'UTF-8');
        }

        // Drop trailing ASCII punctuation so we do not produce "word,\u{2026}".
        return rtrim($slice, " \t\n\r\0\x0B.,;:!?-");
    }
}

<?php

/**
 * @package     TheLoom.Plugin
 * @subpackage  System.DinkyTags
 *
 * @copyright   Copyright (C) 2026 The Loom / Stefan Schulz. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * @link        https://www.the-loom.de
 */

namespace TheLoom\Plugin\System\DinkyTags\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheLoom\Plugin\System\DinkyTags\Helper\Text;

final class TextTest extends TestCase
{
    /**
     * The cleaning pass: what has to be gone from a meta description, and what has to
     * survive it. maxLength is high here so only the cleaning is under test.
     *
     * @return  array<string, array{0: string, 1: string}>
     */
    public static function cleaningCases(): array
    {
        return [
            'plain text is untouched'      => ['Infos zu elektronischer Musik', 'Infos zu elektronischer Musik'],

            // strip_tags handles the markup...
            'tags are stripped'            => ['<p>Hello <b>world</b></p>', 'Hello world'],
            // ...but only the tags: two <p>s with nothing between them run together.
            // Editor output always has whitespace between blocks, so this stays theoretical.
            'adjacent blocks run together' => ['<p>one</p><p>two</p>', 'onetwo'],
            'blocks with a newline'        => ["<p>one</p>\n<p>two</p>", 'one two'],

            // ...but not the text inside <script>/<style>, so those blocks go first.
            'script body is removed'       => ['A<script>alert("x")</script>B', 'A B'],
            'style body is removed'        => ['A<style>.a{color:red}</style>B', 'A B'],
            'script with attrs, newlines'  => ["x<script type=\"text/js\">\n f();\n</script>y", 'x y'],

            // {...} content-plugin constructs never belong in a description.
            'shortcode is removed'         => ['Read this {emailcloak=off} now', 'Read this now'],

            // Entities are decoded once...
            'ampersand entity'             => ['Fish &amp; Chips', 'Fish & Chips'],
            'quote entity'                 => ['a &quot;word&quot; here', 'a "word" here'],
            'html5 entity'                 => ['5 &euro;', "5 \u{20AC}"],

            // ...and a &nbsp; - however it arrives - becomes an ordinary space.
            'named nbsp'                   => ["a&nbsp;b", 'a b'],
            'numeric nbsp'                 => ["a&#160;b", 'a b'],
            'literal nbsp char'            => ["a\u{00A0}b", 'a b'],
            // The concrete bug in the extension DinkyTags replaces: &nbsp; encoded twice.
            'double-encoded nbsp'          => ['nimmt&amp;nbsp;Bernd-Michael Land', 'nimmt Bernd-Michael Land'],

            // Whitespace of every kind collapses to single spaces, ends trimmed.
            'runs of whitespace collapse'  => ["  a \n\t  b   c  ", 'a b c'],
        ];
    }

    #[DataProvider('cleaningCases')]
    public function testCleaning(string $html, string $expected): void
    {
        $this->assertSame($expected, Text::excerpt($html, 500));
    }

    /**
     * The word-boundary cut and the ellipsis.
     *
     * @return  array<string, array{0: string, 1: int, 2: string}>
     */
    public static function cutCases(): array
    {
        return [
            'shorter than the limit, no cut' => ['one two three', 20, 'one two three'],
            'exactly the limit, no ellipsis' => ['one two three', 13, 'one two three'],

            // Cut lands on the last space inside the limit, not mid-word.
            'cut on a word boundary'         => ['the quick brown fox jumps', 12, "the quick\u{2026}"],
            // Trailing punctuation is dropped so we never produce "word,…".
            'trailing comma is dropped'      => ['alpha beta, gamma delta', 12, "alpha beta\u{2026}"],

            // A single word longer than the limit has no boundary to cut on.
            'one long word is hard-cut'      => ['Donaudampfschifffahrt', 10, "Donaudampf\u{2026}"],

            // maxLength <= 0 disables the cut entirely.
            'zero length keeps everything'   => ['one two three four five', 0, 'one two three four five'],
            'negative length keeps it'       => ['one two three four five', -5, 'one two three four five'],
        ];
    }

    #[DataProvider('cutCases')]
    public function testCut(string $html, int $maxLength, string $expected): void
    {
        $this->assertSame($expected, Text::excerpt($html, $maxLength));
    }

    /**
     * The limit counts characters, not bytes: a multibyte string must not be cut in
     * the middle of a character, and its length must be measured with mb_strlen.
     */
    public function testTheLimitIsCharactersNotBytes(): void
    {
        // 12 characters, well over 12 bytes.
        $twelve = 'Grüße über Öl';

        $this->assertSame($twelve, Text::excerpt($twelve, 13));
        $this->assertSame("Grüße\u{2026}", Text::excerpt($twelve, 8));
    }

    /**
     * The result is always a single trimmed line, whatever the input looked like.
     */
    public function testResultIsAlwaysOneTrimmedLine(): void
    {
        $out = Text::excerpt("  <h1>Title</h1>\n\n  <p>Body\ttext.</p>  ", 500);

        $this->assertSame('Title Body text.', $out);
        $this->assertSame($out, trim($out));
        $this->assertStringNotContainsString("\n", $out);
    }
}

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
use TheLoom\Plugin\System\DinkyTags\Helper\MetaWriter;

final class MetaWriterTest extends TestCase
{
    /**
     * Raw custom <meta> chunks as other extensions write them, and the og:* map
     * the only-if-missing guard has to see.
     *
     * @return  array<string, array{0: string[], 1: array<string, string>}>
     */
    public static function customChunks(): array
    {
        return [
            'plain keys'                      => [
                ['<meta property="og:title" content="T">'],
                ['og:title' => 'T'],
            ],
            // Regression: the pattern used to stop at the underscore, so a custom
            // og:site_name was never seen and DinkyTags emitted a second one.
            'underscore in the key'           => [
                ['<meta property="og:site_name" content="Site">'],
                ['og:site_name' => 'Site'],
            ],
            'underscore and nested key'       => [
                ['<meta property="og:image:secure_url" content="https://e.test/a.png">'],
                ['og:image:secure_url' => 'https://e.test/a.png'],
            ],
            'content before property'         => [
                ["<meta content='Hallo' property='og:locale'>"],
                ['og:locale' => 'Hallo'],
            ],
            'spaces around the equals sign'   => [
                ['<meta property = "og:locale" content = "de_DE" />'],
                ['og:locale' => 'de_DE'],
            ],
            'several tags in one chunk'       => [
                ["<meta property=\"og:title\" content=\"A\">\n<meta property=\"og:site_name\" content=\"B\">"],
                ['og:title' => 'A', 'og:site_name' => 'B'],
            ],
            'key and entities are normalised' => [
                ['<meta property="OG:Site_Name" content="Foo &amp; Bar">'],
                ['og:site_name' => 'Foo & Bar'],
            ],
            'non-og and non-meta are ignored' => [
                ['<meta name="description" content="x">', '<link rel="canonical" href="y">', 'og:title'],
                [],
            ],
            'non-string chunks are skipped'   => [
                [['not a string'], '<meta property="og:title" content="T">'],
                ['og:title' => 'T'],
            ],
        ];
    }

    /**
     * @param   array  $chunks    The head data 'custom' entries.
     * @param   array  $expected  The expected og map.
     *
     * @return  void
     */
    #[DataProvider('customChunks')]
    public function testParseCustomOpenGraph(array $chunks, array $expected): void
    {
        $this->assertSame($expected, MetaWriter::parseCustomOpenGraph($chunks));
    }
}

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
use TheLoom\Plugin\System\DinkyTags\Helper\ImageResolver;

/**
 * Only the parts of ImageResolver that never reach Joomla's Uri are exercised here:
 * firstImageSrc() in full, and resolve() for empty input and for an already-absolute
 * remote URL. The root-relative and protocol-relative branches need Uri::root() /
 * Uri::getInstance() and are covered by the .docker stack.
 */
final class ImageResolverTest extends TestCase
{
    /**
     * @return  array<string, array{0: array<int, string>, 1: string|null}>
     */
    public static function firstImageCases(): array
    {
        return [
            'src in the first chunk'        => [['<p><img src="images/a.jpg"></p>'], 'images/a.jpg'],
            'first img of several wins'     => [['<img src="one.jpg"> text <img src="two.jpg">'], 'one.jpg'],
            'single quotes'                 => [["<img alt='x' src='images/b.png'>"], 'images/b.png'],
            'attributes around src'         => [['<img class="lead" src="c.webp" loading="lazy">'], 'c.webp'],

            // Chunks are scanned in order: fulltext, then introtext.
            'falls through to next chunk'   => [['<p>no image here</p>', '<img src="intro.jpg">'], 'intro.jpg'],
            'empty chunk is skipped'        => [['', '<img src="second.jpg">'], 'second.jpg'],
            'first chunk with an image wins' => [['<img src="full.jpg">', '<img src="intro.jpg">'], 'full.jpg'],

            // The src attribute is HTML-decoded (query separators, entities).
            'entity-decoded src'           => [['<img src="p.php?a=1&amp;b=2">'], 'p.php?a=1&b=2'],

            'no image anywhere'            => [['<p>text</p>', 'more <b>text</b>'], null],
            'no chunks at all'            => [[], null],
        ];
    }

    #[DataProvider('firstImageCases')]
    public function testFirstImageSrc(array $chunks, ?string $expected): void
    {
        $this->assertSame($expected, ImageResolver::firstImageSrc(...$chunks));
    }

    /**
     * @return  array<string, array{0: string|null, 1: array{url:string,width:int|null,height:int|null}|null}>
     */
    public static function resolveCases(): array
    {
        $none = null;

        return [
            'null is nothing'             => [null, $none],
            'empty is nothing'            => ['', $none],
            'whitespace is nothing'      => ['   ', $none],
            'only the # suffix'          => ['#joomlaImage://x', $none],

            'plain remote url'           => [
                'https://cdn.example.com/og.png',
                ['url' => 'https://cdn.example.com/og.png', 'width' => null, 'height' => null],
            ],
            'http is also remote'        => [
                'http://example.com/a.jpg',
                ['url' => 'http://example.com/a.jpg', 'width' => null, 'height' => null],
            ],
            'scheme case is preserved'   => [
                'HTTPS://Example.com/A.JPG',
                ['url' => 'HTTPS://Example.com/A.JPG', 'width' => null, 'height' => null],
            ],
            'surrounding whitespace'     => [
                '  https://example.com/a.jpg  ',
                ['url' => 'https://example.com/a.jpg', 'width' => null, 'height' => null],
            ],
            // Joomla appends its image-attributes descriptor after a #; drop from the first #.
            'joomlaImage suffix removed' => [
                'https://example.com/a.jpg#joomlaImage://local-images/a.jpg?width=1200&height=630',
                ['url' => 'https://example.com/a.jpg', 'width' => null, 'height' => null],
            ],
        ];
    }

    #[DataProvider('resolveCases')]
    public function testResolve(?string $raw, ?array $expected): void
    {
        $this->assertSame($expected, ImageResolver::resolve($raw));
    }
}

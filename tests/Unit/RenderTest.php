<?php

/**
 * @package     TheLoom.Plugin
 * @subpackage  Content.DinkyGallery
 *
 * @copyright   Copyright (C) 2026 The Loom / Stefan Schulz. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * @link        https://www.the-loom.de
 */

namespace TheLoom\Plugin\Content\DinkyGallery\Tests;

use PHPUnit\Framework\TestCase;
use TheLoom\Plugin\Content\DinkyGallery\Helper\Render;

/**
 * The server-rendered markup. The class names and data-* attributes here are the
 * contract the CSS and the ES module read, so this pins them; it also guards the
 * attribute-escaping and the "no data-w/data-h on the link" cleanup.
 */
final class RenderTest extends TestCase
{
    /**
     * @param   array<string, mixed>  $over
     *
     * @return  array<string, mixed>
     */
    private static function opts(array $over = []): array
    {
        return $over + [
            'cards'    => 3,
            'size'     => 80,
            'loop'     => 0,
            'middle'   => 'none',
            'gap'      => '0px',
            'aspect'   => '4/3',
            'sizes'    => '(min-width: 1200px) 400px, 33vw',
            'card_min' => '13rem',
            'backdrop' => 60,
            'lb_color' => '0, 0, 0',
            'lb_pad'   => '10px',
            'lb_aspect' => 'viewport',
        ];
    }

    /**
     * @param   array<string, mixed>  $over
     *
     * @return  array<string, mixed>
     */
    private static function image(array $over = []): array
    {
        return $over + [
            'url'    => '/images/g/01.jpg',
            'src'    => '/images/g/.thumbs/01.abc.1024x768.jpg',
            'srcset' => '/images/g/.thumbs/01.abc.480x360.jpg 480w, /images/g/01.jpg 4032w',
            'full'   => '/images/g/.thumbs/01.abc.large-1920x1440.jpg',
            'alt'    => '01',
            'w'      => 4032,
            'h'      => 3024,
        ];
    }

    private static function labels(): array
    {
        return ['carousel' => 'Image gallery', 'prev' => 'Previous image', 'next' => 'Next image'];
    }

    public function testDataAttributesReflectOptions(): void
    {
        $html = Render::carousel(
            [self::image()],
            self::opts(['cards' => 3, 'size' => 80, 'loop' => 0, 'middle' => 'close', 'backdrop' => 55]),
            self::labels()
        );

        $this->assertStringContainsString('data-cards="3"', $html);
        $this->assertStringContainsString('data-size="80"', $html);
        $this->assertStringContainsString('data-loop="0"', $html);
        $this->assertStringContainsString('data-middle="close"', $html);
        $this->assertStringContainsString('data-backdrop="55"', $html);
        $this->assertStringContainsString('data-lb-color="0, 0, 0"', $html);
        $this->assertStringContainsString('data-lb-pad="10px"', $html);
        $this->assertStringContainsString('data-lb-aspect="viewport"', $html);
        $this->assertStringContainsString('role="group"', $html);
        $this->assertStringContainsString('aria-label="Image gallery"', $html);
        $this->assertStringContainsString('<ul class="dg-track" role="list">', $html);
    }

    public function testStyleCustomProperties(): void
    {
        $html = Render::carousel(
            [self::image()],
            self::opts(['cards' => 4, 'gap' => '12px', 'aspect' => '16/9', 'card_min' => '10rem']),
            self::labels()
        );

        $this->assertStringContainsString(
            'style="--dg-cards:4;--dg-gap:12px;--dg-aspect:16/9;--dg-card-min:10rem"',
            $html
        );
    }

    public function testClampsCardsAndSize(): void
    {
        $low = Render::carousel([self::image()], self::opts(['cards' => 0, 'size' => 3]), self::labels());
        $this->assertStringContainsString('data-cards="1"', $low);
        $this->assertStringContainsString('--dg-cards:1;', $low);
        $this->assertStringContainsString('data-size="10"', $low);

        $high = Render::carousel([self::image()], self::opts(['size' => 500]), self::labels());
        $this->assertStringContainsString('data-size="100"', $high);
    }

    public function testLoopNormalisation(): void
    {
        $this->assertStringContainsString(
            'data-loop="1"',
            Render::carousel([self::image()], self::opts(['loop' => 1]), self::labels())
        );
        $this->assertStringContainsString(
            'data-loop="0"',
            Render::carousel([self::image()], self::opts(['loop' => 2]), self::labels())
        );
    }

    public function testCountBadgeOnlyOnFirstCardOfAMultiImageGallery(): void
    {
        $html = Render::carousel(
            [self::image(), self::image(['alt' => '02']), self::image(['alt' => '03'])],
            self::opts(),
            self::labels()
        );

        $this->assertSame(1, substr_count($html, 'class="dg-count"'));
        $this->assertStringContainsString('>1 / 3</span>', $html);
    }

    public function testNoCountBadgeForASingleImage(): void
    {
        $html = Render::carousel([self::image()], self::opts(), self::labels());

        $this->assertStringNotContainsString('dg-count', $html);
    }

    public function testSrcsetAndSizesEmittedTogether(): void
    {
        $html = Render::carousel([self::image()], self::opts(), self::labels());

        $this->assertStringContainsString('srcset="/images/g/.thumbs/01.abc.480x360.jpg 480w', $html);
        $this->assertStringContainsString('sizes="(min-width: 1200px) 400px, 33vw"', $html);
        $this->assertStringContainsString('src="/images/g/.thumbs/01.abc.1024x768.jpg"', $html);
    }

    public function testNoSrcsetWhenImageHasNone(): void
    {
        $html = Render::carousel(
            [self::image(['srcset' => '', 'src' => '/images/g/01.jpg', 'full' => '/images/g/01.jpg'])],
            self::opts(),
            self::labels()
        );

        $this->assertStringNotContainsString('srcset=', $html);
        $this->assertStringNotContainsString('sizes=', $html);
        $this->assertStringContainsString('src="/images/g/01.jpg"', $html);
    }

    public function testLinkPointsAtTheFullDerivative(): void
    {
        $html = Render::carousel([self::image()], self::opts(), self::labels());

        $this->assertStringContainsString(
            '<a class="dg-card__link" href="/images/g/.thumbs/01.abc.large-1920x1440.jpg"'
            . ' data-full="/images/g/.thumbs/01.abc.large-1920x1440.jpg">',
            $html
        );
    }

    public function testFallsBackToUrlWhenNoDerivatives(): void
    {
        $html = Render::carousel(
            [['url' => '/images/g/01.jpg', 'alt' => '01', 'w' => null, 'h' => null]],
            self::opts(),
            self::labels()
        );

        $this->assertStringContainsString('href="/images/g/01.jpg" data-full="/images/g/01.jpg"', $html);
        $this->assertStringContainsString('src="/images/g/01.jpg"', $html);
        $this->assertStringNotContainsString('width=', $html);
    }

    public function testWidthAndHeightOmittedWhenUnknown(): void
    {
        $with = Render::carousel([self::image()], self::opts(), self::labels());
        $this->assertStringContainsString('width="4032" height="3024"', $with);

        $without = Render::carousel([self::image(['w' => null, 'h' => null])], self::opts(), self::labels());
        $this->assertStringNotContainsString('width=', $without);
        $this->assertStringNotContainsString('height=', $without);
    }

    public function testNoDataWOrDataHOnTheLink(): void
    {
        // Removed in the 1.5.0 cleanup — nothing reads them; the dims live on the <img>.
        $html = Render::carousel([self::image()], self::opts(), self::labels());

        $this->assertStringNotContainsString('data-w=', $html);
        $this->assertStringNotContainsString('data-h=', $html);
    }

    public function testAttributesAreEscaped(): void
    {
        $html = Render::carousel(
            [self::image(['alt' => 'a "quote" & <tag>', 'url' => '/img/a&b.jpg', 'src' => '/img/a&b.jpg', 'srcset' => '', 'full' => '/img/a&b.jpg'])],
            self::opts(),
            self::labels()
        );

        $this->assertStringContainsString('alt="a &quot;quote&quot; &amp; &lt;tag&gt;"', $html);
        $this->assertStringContainsString('src="/img/a&amp;b.jpg"', $html);
        $this->assertStringNotContainsString('<tag>', $html);
    }

    public function testCarouselHasBothArrowsHidden(): void
    {
        $html = Render::carousel([self::image()], self::opts(), self::labels());

        $this->assertStringContainsString('class="dg-arrow dg-arrow--prev" aria-label="Previous image" hidden', $html);
        $this->assertStringContainsString('class="dg-arrow dg-arrow--next" aria-label="Next image" hidden', $html);
    }

    public function testPlainList(): void
    {
        $html = Render::plain([
            ['url' => '/images/g/01.jpg', 'alt' => '01'],
            ['url' => '/images/g/02.jpg', 'alt' => 'a & b'],
        ]);

        $this->assertStringStartsWith('<ul class="dg-plain">', $html);
        $this->assertStringEndsWith('</ul>', $html);
        $this->assertStringContainsString('<li><a href="/images/g/01.jpg"><img src="/images/g/01.jpg" alt="01"></a></li>', $html);
        $this->assertStringContainsString('alt="a &amp; b"', $html);
        $this->assertStringNotContainsString('srcset', $html);
        $this->assertStringNotContainsString('dg-track', $html);
    }
}

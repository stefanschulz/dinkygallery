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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheLoom\Plugin\Content\DinkyGallery\Helper\Sanitize;

/**
 * The parameter / shortcode sanitisers. Several of these are the only thing standing
 * between a user-typed value and a style attribute or a filesystem path, so the
 * rejection cases matter as much as the happy ones.
 */
final class SanitizeTest extends TestCase
{
    /**
     * @return  array<string, array{0: string, 1: string}>
     */
    public static function cssAspectCases(): array
    {
        return [
            'plain ratio'          => ['4/3', '4/3'],
            'colon is normalised'  => ['16:9', '16/9'],
            'bare number'          => ['1.5', '1.5'],
            'leading dot number'   => ['.5', '.5'],
            'spaces around slash'  => ['3 / 2', '3 / 2'],
            'blank falls back'     => ['', '4/3'],
            'word falls back'      => ['widescreen', '4/3'],
            'three parts fall back' => ['1/2/3', '4/3'],
            'incomplete falls back' => ['4/', '4/3'],
            'negative falls back'  => ['-1/2', '4/3'],
            'declaration breakout' => ['4/3;color:red', '4/3'],
        ];
    }

    #[DataProvider('cssAspectCases')]
    public function testCssAspect(string $raw, string $expected): void
    {
        $this->assertSame($expected, Sanitize::cssAspect($raw));
    }

    /**
     * @return  array<string, array{0: string, 1: string}>
     */
    public static function lightboxAspectCases(): array
    {
        return [
            'viewport'             => ['viewport', 'viewport'],
            'viewport upper-case'  => ['VIEWPORT', 'viewport'],
            'image'                => ['image', 'image'],
            'image padded'         => ['  image  ', 'image'],
            'ratio with colon'     => ['3:2', '3/2'],
            'bare number'          => ['1.5', '1.5'],
            'blank falls back'     => ['', 'viewport'],
            'word falls back'      => ['fullscreen', 'viewport'],
            'breakout falls back'  => ['3/2}', 'viewport'],
        ];
    }

    #[DataProvider('lightboxAspectCases')]
    public function testLightboxAspect(string $raw, string $expected): void
    {
        $this->assertSame($expected, Sanitize::lightboxAspect($raw));
    }

    /**
     * @return  array<string, array{0: string, 1: string|null, 2: string}>
     */
    public static function cssLengthCases(): array
    {
        return [
            // A bare zero must gain a unit or the card calc() collapses.
            'zero gains a unit'        => ['0', null, '0px'],
            'zero px stays'           => ['0px', null, '0px'],
            'pixels'                  => ['12px', null, '12px'],
            'rem with decimal'        => ['1.5rem', null, '1.5rem'],
            'percent'                 => ['50%', null, '50%'],
            'viewport unit'           => ['2vw', null, '2vw'],
            'trimmed'                 => ['  8px  ', null, '8px'],
            'blank uses fallback'     => ['', '0px', '0px'],
            'blank uses given fallback' => ['', '10px', '10px'],
            'unitless is rejected'    => ['10', '10px', '10px'],
            'space before unit'       => ['10 px', '0px', '0px'],
            'calc is rejected'        => ['calc(1px + 2px)', '0px', '0px'],
            'declaration breakout'    => ['red;}', '0px', '0px'],
        ];
    }

    #[DataProvider('cssLengthCases')]
    public function testCssLength(string $raw, ?string $fallback, string $expected): void
    {
        $this->assertSame(
            $expected,
            $fallback === null ? Sanitize::cssLength($raw) : Sanitize::cssLength($raw, $fallback)
        );
    }

    /**
     * @return  array<string, array{0: string, 1: string}>
     */
    public static function hexToRgbCases(): array
    {
        return [
            'six digit black'      => ['#000000', '0, 0, 0'],
            'six digit white'      => ['#ffffff', '255, 255, 255'],
            'short form expands'    => ['#fff', '255, 255, 255'],
            'short form mixed'      => ['#abc', '170, 187, 204'],
            'hash is optional'     => ['000', '0, 0, 0'],
            'upper case, padded'   => ['  #ABCDEF  ', '171, 205, 239'],
            'too short falls back'  => ['#12', '0, 0, 0'],
            'non-hex falls back'    => ['#gggggg', '0, 0, 0'],
            'blank falls back'      => ['', '0, 0, 0'],
        ];
    }

    #[DataProvider('hexToRgbCases')]
    public function testHexToRgb(string $raw, string $expected): void
    {
        $this->assertSame($expected, Sanitize::hexToRgb($raw));
    }

    /**
     * @return  array<string, array{0: string, 1: list<string>}>
     */
    public static function extensionListCases(): array
    {
        $default = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];

        return [
            'plain list'          => ['jpg,png', ['jpg', 'png']],
            'case and spaces'     => ['JPG, PNG ', ['jpg', 'png']],
            'leading dots dropped' => ['.webp,.avif', ['webp', 'avif']],
            'empty entries dropped' => ['jpg,,png', ['jpg', 'png']],
            'blank falls back'    => ['', $default],
            'only separators'     => [' , , ', $default],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('extensionListCases')]
    public function testExtensionList(string $raw, array $expected): void
    {
        $this->assertSame($expected, Sanitize::extensionList($raw));
    }

    /**
     * @return  array<string, array{0: string, 1: string}>
     */
    public static function thumbDirNameCases(): array
    {
        return [
            'default'              => ['.thumbs', '.thumbs'],
            'plain name'           => ['thumbs', 'thumbs'],
            'another name'         => ['cache', 'cache'],
            'trimmed'              => ['  .thumbs  ', '.thumbs'],
            'blank falls back'     => ['', '.thumbs'],
            'slash falls back'     => ['a/b', '.thumbs'],
            'parent falls back'    => ['../x', '.thumbs'],
            'bare dotdot falls back' => ['..', '.thumbs'],
            'backslash falls back'  => ['a\\b', '.thumbs'],
        ];
    }

    #[DataProvider('thumbDirNameCases')]
    public function testThumbDirName(string $raw, string $expected): void
    {
        $this->assertSame($expected, Sanitize::thumbDirName($raw));
    }

    /**
     * @return  array<string, array{0: string, 1: list<int>}>
     */
    public static function widthListCases(): array
    {
        $default = [480, 768, 1024, 1600];

        return [
            'default ladder'      => ['480,768,1024,1600', $default],
            'sorted ascending'    => ['1600,480,768', [480, 768, 1600]],
            'de-duplicated'       => ['480,480,480', [480]],
            'trimmed'             => [' 480 , 768 ', [480, 768]],
            'zero dropped'        => ['0,5000', [5000]],
            'over cap dropped'    => ['12000,800', [800]],
            'garbage falls back'  => ['abc,def', $default],
            'blank falls back'    => ['', $default],
        ];
    }

    /**
     * @param  list<int>  $expected
     */
    #[DataProvider('widthListCases')]
    public function testWidthList(string $raw, array $expected): void
    {
        $this->assertSame($expected, Sanitize::widthList($raw));
    }
}

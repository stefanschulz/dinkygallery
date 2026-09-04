<?php

/**
 * @package     TheLoom.Plugin
 * @subpackage  Content.DinkyGallery
 *
 * @copyright   Copyright (C) 2026 The Loom / Stefan Schulz. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * @link        https://www.the-loom.de
 */

namespace TheLoom\Plugin\Content\DinkyGallery\Helper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Pure sanitisers for parameter and shortcode values, gathered so they can be unit
 * tested on their own. Several carry security weight: cssLength() and cssAspect() feed
 * a style attribute, thumbDirName() feeds a filesystem path.
 *
 * All methods are static and stateless.
 *
 * @since  1.6.0
 */
final class Sanitize
{
    /**
     * Sanitises a card aspect ratio for the "--dg-aspect" custom property. Accepts
     * "W/H", "W:H" (normalised to "/") or a bare number; anything else -> "4/3".
     *
     * @param   string  $raw  The parameter / shortcode value.
     *
     * @return  string
     *
     * @since   1.3.0
     */
    public static function cssAspect(string $raw): string
    {
        $raw = str_replace(':', '/', trim($raw));

        return preg_match('#^\d*\.?\d+(\s*/\s*\d*\.?\d+)?$#', $raw) === 1 ? $raw : '4/3';
    }

    /**
     * Resolves the lightbox_aspect parameter to "viewport", "image" or a sanitised
     * "W/H" ratio. Anything unrecognised -> "viewport" (the pre-1.4.0 behaviour).
     *
     * @param   string  $raw  The parameter value.
     *
     * @return  string
     *
     * @since   1.4.0
     */
    public static function lightboxAspect(string $raw): string
    {
        $raw = strtolower(str_replace(':', '/', trim($raw)));

        if ($raw === 'image') {
            return 'image';
        }

        return preg_match('#^\d*\.?\d+(\s*/\s*\d*\.?\d+)?$#', $raw) === 1 ? $raw : 'viewport';
    }

    /**
     * Sanitises a user-supplied CSS length before it goes into a style attribute.
     * Anything that is not "0" or a number with a length unit becomes the fallback, so
     * the value cannot break out of the custom-property declaration.
     *
     * @param   string  $raw       The raw parameter / shortcode value.
     * @param   string  $fallback  Value to use when $raw is empty or not a length.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    public static function cssLength(string $raw, string $fallback = '0px'): string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return $fallback;
        }

        // "0" goes out as "0px": a unitless zero makes "100% - (n-1)*var(--dg-gap)"
        // an invalid calc() (percentage minus number) and the sizing collapses.
        if ($raw === '0') {
            return '0px';
        }

        return preg_match('/^\d*\.?\d+(px|rem|em|%|vw|vh|vmin|vmax|ch)$/', $raw) === 1 ? $raw : $fallback;
    }

    /**
     * Converts a "#rgb" / "#rrggbb" colour to a "r, g, b" triplet for use inside
     * rgba(). Falls back to black on anything unparseable.
     *
     * @param   string  $hex  The colour value from the color field.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    public static function hexToRgb(string $hex): string
    {
        $hex = ltrim(trim($hex), '#');

        if (preg_match('/^[0-9a-fA-F]{3}$/', $hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return '0, 0, 0';
        }

        return hexdec(substr($hex, 0, 2)) . ', ' . hexdec(substr($hex, 2, 2)) . ', ' . hexdec(substr($hex, 4, 2));
    }

    /**
     * Splits a comma list of extensions into a clean lower-case array. An empty or
     * all-blank list falls back to the default set.
     *
     * @param   string  $raw  The image_extensions parameter value.
     *
     * @return  string[]
     *
     * @since   1.0.0
     */
    public static function extensionList(string $raw): array
    {
        $list = [];

        foreach (explode(',', strtolower($raw)) as $ext) {
            $ext = trim($ext, " \t\n.");

            if ($ext !== '') {
                $list[] = $ext;
            }
        }

        return $list !== [] ? $list : ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];
    }

    /**
     * Sanitises the thumb_dir parameter to a single, safe path segment. A slash, "..",
     * backslashes or an empty value fall back to ".thumbs".
     *
     * @param   string  $raw  The thumb_dir parameter value.
     *
     * @return  string
     *
     * @since   1.5.0
     */
    public static function thumbDirName(string $raw): string
    {
        $name = trim(str_replace('\\', '/', $raw), " \t/");

        if ($name === '' || str_contains($name, '/') || str_contains($name, '..')) {
            return '.thumbs';
        }

        return $name;
    }

    /**
     * Parses the thumb_widths list into a sorted, de-duplicated array of pixel widths
     * (1-10000). Empty / all-invalid input falls back to the default ladder.
     *
     * @param   string  $raw  The thumb_widths parameter value.
     *
     * @return  list<int>
     *
     * @since   1.5.0
     */
    public static function widthList(string $raw): array
    {
        $out = [];

        foreach (explode(',', $raw) as $token) {
            $n = (int) trim($token);

            if ($n >= 1 && $n <= 10000) {
                $out[$n] = $n;
            }
        }

        if ($out === []) {
            return [480, 768, 1024, 1600];
        }

        ksort($out);

        return array_values($out);
    }
}

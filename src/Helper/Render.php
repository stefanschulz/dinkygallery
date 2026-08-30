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

\defined('_JEXEC') or die;

/**
 * Builds the server-rendered markup for a gallery: the scroll-snap card carousel for
 * HTML pages, or a plain linked list for feeds / component views / print.
 *
 * The class names and data-* attributes here are the contract the CSS and JS rely on
 * - keep them stable.
 *
 * @since  1.0.0
 */
final class Render
{
    /**
     * Renders the in-article carousel.
     *
     * @param   list<array{url:string, alt:string, w:?int, h:?int}>  $images  Image list.
     * @param   array{cards:mixed, size:mixed, loop:mixed, middle:mixed, gap:string, aspect:string, card_min:string, backdrop:int}  $o  Resolved options.
     * @param   array{carousel:string, prev:string, next:string}  $labels  Translated ARIA labels.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    public static function carousel(array $images, array $o, array $labels): string
    {
        $cards  = max(1, (int) $o['cards']);
        $size   = min(100, max(10, (int) $o['size']));
        $loop   = (int) $o['loop'] === 1 ? '1' : '0';
        $middle = $o['middle'] === 'close' ? 'close' : 'none';

        $style = 'style="' . self::e(
            '--dg-cards:' . $cards
            . ';--dg-gap:' . $o['gap']
            . ';--dg-aspect:' . $o['aspect']
            . ';--dg-card-min:' . $o['card_min']
        ) . '"';

        $html = '<div class="dg" data-dg data-cards="' . $cards . '" data-size="' . $size . '"'
            . ' data-loop="' . $loop . '" data-middle="' . $middle . '" data-backdrop="' . (int) $o['backdrop'] . '"'
            . ' ' . $style . ' role="group" aria-label="' . self::e($labels['carousel']) . '">';

        $html .= '<button type="button" class="dg-arrow dg-arrow--prev" aria-label="' . self::e($labels['prev']) . '" hidden></button>';
        $html .= '<ul class="dg-track" role="list">';

        foreach ($images as $img) {
            $url  = self::e($img['url']);
            $dims = ($img['w'] && $img['h']) ? ' data-w="' . (int) $img['w'] . '" data-h="' . (int) $img['h'] . '"' : '';
            $wh   = ($img['w'] && $img['h']) ? ' width="' . (int) $img['w'] . '" height="' . (int) $img['h'] . '"' : '';

            $html .= '<li class="dg-card">'
                . '<a class="dg-card__link" href="' . $url . '" data-full="' . $url . '"' . $dims . '>'
                . '<img class="dg-card__img" src="' . $url . '" alt="' . self::e($img['alt']) . '"'
                . ' loading="lazy" decoding="async"' . $wh . '>'
                . '</a></li>';
        }

        $html .= '</ul>';
        $html .= '<button type="button" class="dg-arrow dg-arrow--next" aria-label="' . self::e($labels['next']) . '" hidden></button>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Renders the no-JS / non-HTML fallback: a plain list of linked images.
     *
     * @param   list<array{url:string, alt:string, w:?int, h:?int}>  $images  Image list.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    public static function plain(array $images): string
    {
        $html = '<ul class="dg-plain">';

        foreach ($images as $img) {
            $url = self::e($img['url']);

            $html .= '<li><a href="' . $url . '"><img src="' . $url . '" alt="' . self::e($img['alt']) . '"></a></li>';
        }

        return $html . '</ul>';
    }

    /**
     * HTML-attribute escape.
     *
     * @param   string  $value  Raw value.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private static function e(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES, 'UTF-8');
    }
}

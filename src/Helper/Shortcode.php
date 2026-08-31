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
 * Finds {gallery ...} shortcodes in article text and parses their attributes.
 *
 * Forms, all starting with "{gallery":
 *   {gallery my-folder}
 *   {gallery folder="my-folder" cards="4" size="80" loop="0" sort="desc" middle="close"}
 *   {gallery ...opening attrs...}path/or/file{/gallery}   (sigplus compatibility)
 *
 * The bare form is everything up to the closing brace, trimmed, taken as the folder
 * name. The attribute form (body contains "=") is parsed into name="value" pairs with
 * a shell-style token grammar; a leading bare token is still accepted as the folder.
 * The closing-tag form takes the folder (or single image file) from the text between
 * the tags; the opening attributes are parsed the same way, so any recognised
 * DinkyGallery attribute still applies while sigplus-only ones (width, height,
 * alignment, deftitle, lightbox, …) are ignored.
 *
 * @since  1.0.0
 */
final class Shortcode
{
    /**
     * Attributes a shortcode may carry, each overriding the matching plugin parameter.
     *
     * @var    string[]
     * @since  1.0.0
     */
    private const ATTRIBUTES = ['folder', 'cards', 'size', 'loop', 'sort', 'middle', 'gap'];

    /**
     * Locates every {gallery ...} in the given text.
     *
     * @param   string  $text  The raw article text.
     *
     * @return  list<array{raw:string, start:int, length:int, options:array<string,string>, skip:?string}>
     *          One entry per tag, in document order. Byte offsets. `skip` is non-null
     *          when the tag must be left untouched (currently only "code-block").
     *
     * @since   1.0.0
     */
    public function find(string $text): array
    {
        // Opening tag, then optionally "inner text {/gallery}" (sigplus form).
        $pattern = '#\{gallery\b\s*(?<body>[^}]*)\}(?:(?<inner>[^{}]*)\{/gallery\})?#i';

        if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return [];
        }

        $out = [];

        foreach ($matches as $m) {
            $raw     = $m[0][0];
            $start   = $m[0][1];
            $options = $this->parseBody($m['body'][0]);

            // Closing-tag form: the folder / file lives between the tags and wins.
            if (isset($m['inner'][1]) && $m['inner'][1] !== -1) {
                $options['folder'] = trim($m['inner'][0]);
            }

            $out[] = [
                'raw'     => $raw,
                'start'   => $start,
                'length'  => \strlen($raw),
                'options' => $options,
                'skip'    => $this->insideCodeOrPre(substr($text, 0, $start)) ? 'code-block' : null,
            ];
        }

        return $out;
    }

    /**
     * Parses a shortcode body into a partial options map (only the keys it carries).
     *
     * @param   string  $body  The text between "{gallery" and "}".
     *
     * @return  array<string,string>
     *
     * @since   1.0.0
     */
    private function parseBody(string $body): array
    {
        $body = trim($body);

        if ($body === '') {
            return [];
        }

        // Bare form: no "=" anywhere -> the whole body is the folder name.
        if (!str_contains($body, '=')) {
            return ['folder' => $body];
        }

        $opts = [];

        // Shell-style tokens: optional name=, then a quoted, numeric or bare value.
        $pattern = '#(?<=\s|^)(?:([A-Za-z_][\w:.\-]*)=)?(\'[^\']*\'|"[^"]*"|-?\d+(?:\.\d+)?|[\w:.\/\-]+)(?=\s|$)#';

        if (preg_match_all($pattern, $body, $pairs, PREG_SET_ORDER)) {
            foreach ($pairs as $i => $pair) {
                $key = strtolower($pair[1]);
                $val = trim($pair[2], "\"'");

                if ($key === '' && $i === 0) {
                    // A leading value with no name is the folder (back-compat behaviour).
                    $opts['folder'] = $val;

                    continue;
                }

                if (\in_array($key, self::ATTRIBUTES, true)) {
                    $opts[$key] = $val;
                }
            }
        }

        return $opts;
    }

    /**
     * Best-effort test for whether an offset sits inside an unclosed <code> or <pre>.
     *
     * @param   string  $before  The article text preceding the shortcode.
     *
     * @return  boolean
     *
     * @since   1.0.0
     */
    private function insideCodeOrPre(string $before): bool
    {
        foreach (['code', 'pre'] as $tag) {
            $open  = preg_match_all('#<' . $tag . '(?:\s[^>]*)?>#i', $before);
            $close = preg_match_all('#</' . $tag . '\s*>#i', $before);

            if ($open > $close) {
                return true;
            }
        }

        return false;
    }
}

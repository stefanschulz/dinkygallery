<?php

/**
 * @package     TheLoom.Plugin
 * @subpackage  Content.DinkyGallery
 *
 * @copyright   Copyright (C) 2026 The Loom / Stefan Schulz. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * @link        https://www.the-loom.de
 */

namespace TheLoom\Plugin\Content\DinkyGallery\Extension;

use Joomla\CMS\Event\Content\ContentPrepareEvent;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use TheLoom\Plugin\Content\DinkyGallery\Helper\Folder;
use TheLoom\Plugin\Content\DinkyGallery\Helper\Render;
use TheLoom\Plugin\Content\DinkyGallery\Helper\Shortcode;

\defined('_JEXEC') or die;

/**
 * Replaces {gallery ...} shortcodes in com_content articles with an in-article card
 * carousel and a click-through lightbox. Zero runtime dependencies: one CSS file and
 * one ES-module script, both under media/plg_content_dinkygallery/.
 *
 * Subscribes to onContentPrepare: the article text is still raw at that point, and a
 * replaced tag leaves no {gallery behind, so the pass is idempotent across the
 * intro-text and full-text invocations.
 *
 * @since  1.0.0
 */
final class DinkyGallery extends CMSPlugin implements SubscriberInterface
{
    /**
     * Load the plugin language files automatically.
     *
     * @var    boolean
     * @since  1.0.0
     */
    protected $autoloadLanguage = true;

    /**
     * com_content contexts the shortcode is processed in.
     *
     * @var    string[]
     * @since  1.0.0
     */
    private const CONTEXTS = [
        'com_content.article',
        'com_content.category',
        'com_content.featured',
        'com_content.archive',
        'com_content.feed',
    ];

    /**
     * Returns the events this subscriber listens to.
     *
     * @return  array<string, string>
     *
     * @since   1.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return ['onContentPrepare' => 'onContentPrepare'];
    }

    /**
     * Finds every {gallery ...} in the article body and swaps it for the rendered
     * carousel (or a plain list on non-HTML output), registering the CSS/JS once at
     * least one gallery was built.
     *
     * @param   ContentPrepareEvent  $event  The onContentPrepare event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onContentPrepare(ContentPrepareEvent $event): void
    {
        $context = $event->getContext();
        $item    = $event->getItem();

        if (
            !isset($item->text)
            || !\is_string($item->text)
            || stripos($item->text, '{gallery') === false
        ) {
            return;
        }

        // Smart Search: strip the tags, never inject markup into the index.
        if ($context === 'com_finder.indexer') {
            $item->text = preg_replace('/\{gallery\b[^}]*\}/i', '', $item->text) ?? $item->text;

            return;
        }

        if (!\in_array($context, self::CONTEXTS, true)) {
            return;
        }

        $original = $item->text;

        try {
            $item->text = $this->process($item->text);
        } catch (\Throwable $e) {
            $item->text = $original;

            if ($this->debugEnabled()) {
                $item->text .= "\n" . $this->debugComment(['aborted: ' . $e->getMessage()]);
            }
        }
    }

    /**
     * Replaces every shortcode in the text and loads the page assets if anything was
     * rendered as a carousel.
     *
     * @param   string  $text  The raw article text.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function process(string $text): string
    {
        $app   = $this->getApplication();
        $doc   = $app->getDocument();
        $input = $app->getInput();

        $isHtml = $doc->getType() === 'html'
            && $input->getCmd('tmpl') !== 'component'
            && strtolower((string) $input->getCmd('format', 'html')) === 'html'
            && $input->getInt('print') !== 1;

        $config   = $this->config();
        $log       = ['mode: ' . ($isHtml ? 'carousel' : 'plain list')];
        $folder    = new Folder($config['base_directory'], $config['extensions']);
        $matches   = (new Shortcode())->find($text);
        $labels    = [
            'carousel' => Text::_('PLG_CONTENT_DINKYGALLERY_ARIA_CAROUSEL'),
            'prev'     => Text::_('PLG_CONTENT_DINKYGALLERY_ARIA_PREV'),
            'next'     => Text::_('PLG_CONTENT_DINKYGALLERY_ARIA_NEXT'),
        ];
        $rendered = 0;

        // Work backwards so each replacement leaves the earlier byte offsets valid.
        foreach (array_reverse($matches) as $match) {
            if ($match['skip'] !== null) {
                $log[] = 'tag @' . $match['start'] . ': left raw (' . $match['skip'] . ')';

                continue;
            }

            $options    = $match['options'] + $config['options'];
            $folderName = trim((string) $options['folder']);

            if ($folderName === '') {
                $log[] = 'tag @' . $match['start'] . ': removed (no folder given)';
                $text  = substr_replace($text, '', $match['start'], $match['length']);

                continue;
            }

            $absPath = $folder->resolve($folderName);

            if ($absPath === null) {
                $log[] = 'tag @' . $match['start'] . ' "' . $folderName . '": removed (' . $folder->getError() . ')';
                $text  = substr_replace($text, '', $match['start'], $match['length']);

                continue;
            }

            $relBase = rtrim(str_replace('\\', '/', $config['base_directory']), '/')
                . '/' . trim(str_replace('\\', '/', $folderName), '/');

            $images = $folder->images($absPath, $relBase, (string) $options['sort']);

            if ($images === []) {
                $log[] = 'tag @' . $match['start'] . ' "' . $folderName . '": removed (0 images) [' . $absPath . ']';
                $text  = substr_replace($text, '', $match['start'], $match['length']);

                continue;
            }

            $markup = $isHtml
                ? Render::carousel(
                    $images,
                    [
                        'cards'    => $options['cards'],
                        'size'     => $options['size'],
                        'loop'     => $options['loop'],
                        'middle'   => $options['middle'],
                        'gap'      => $this->cssLength((string) $options['gap']),
                        'aspect'   => $config['card_aspect'],
                        'card_min' => $config['card_min'],
                        'backdrop' => $config['backdrop_opacity'],
                        'lb_color' => $config['lightbox_rgb'],
                        'lb_pad'   => $config['lightbox_padding'],
                    ],
                    $labels
                )
                : Render::plain($images);

            $text = substr_replace($text, $markup, $match['start'], $match['length']);
            $rendered++;
            $log[] = 'tag @' . $match['start'] . ' "' . $folderName . '": ' . \count($images) . ' images [' . $absPath . ']';
        }

        if ($rendered > 0 && $isHtml) {
            $log[] = 'assets: ' . $this->loadAssets($doc);
        }

        if ($this->debugEnabled()) {
            $text .= "\n" . $this->debugComment($log);
        }

        return $text;
    }

    /**
     * Reads the plugin parameters into resolved defaults.
     *
     * @return  array{base_directory:string, extensions:string[], card_aspect:string, card_min:string, backdrop_opacity:int, options:array<string,mixed>}
     *
     * @since   1.0.0
     */
    private function config(): array
    {
        $params = $this->params;

        $base = trim((string) $params->get('base_directory', 'images'));

        return [
            'base_directory'   => $base !== '' ? $base : 'images',
            'extensions'       => $this->extensionList((string) $params->get('image_extensions', 'jpg,jpeg,png,webp,gif,avif')),
            'card_aspect'      => (string) $params->get('card_aspect', '4/3'),
            'card_min'         => trim((string) $params->get('card_min', '13rem')) ?: '13rem',
            'backdrop_opacity' => (int) $params->get('backdrop_opacity', 60),
            'lightbox_rgb'     => $this->hexToRgb((string) $params->get('lightbox_color', '#000000')),
            'lightbox_padding' => $this->cssLength((string) $params->get('lightbox_padding', '10px'), '10px'),
            'options'          => [
                'folder' => '',
                'cards'  => (int) $params->get('visible_cards', 3),
                'size'   => (int) $params->get('lightbox_size', 100),
                'loop'   => (int) $params->get('lightbox_loop', 1),
                'sort'   => (string) $params->get('sort_order', 'asc'),
                'middle' => (string) $params->get('middle_zone_action', 'none'),
                'gap'    => (string) $params->get('card_gap', '0'),
            ],
        ];
    }

    /**
     * Sanitises a user-supplied CSS length before it goes into a style attribute.
     * Anything that is not "0" or a number with a length unit becomes "0", so the
     * value cannot break out of the custom-property declaration.
     *
     * @param   string  $raw  The raw parameter / shortcode value.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function cssLength(string $raw, string $fallback = '0px'): string
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
    private function hexToRgb(string $hex): string
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
     * Splits a comma list of extensions into a clean lower-case array.
     *
     * @param   string  $raw  The image_extensions parameter value.
     *
     * @return  string[]
     *
     * @since   1.0.0
     */
    private function extensionList(string $raw): array
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
     * Registers and uses the carousel/lightbox stylesheet and module script.
     *
     * @param   object  $doc  The current document.
     *
     * @return  string  A short status for the debug log.
     *
     * @since   1.0.0
     */
    private function loadAssets(object $doc): string
    {
        try {
            $wa = $doc->getWebAssetManager();

            // Extension asset files are not auto-discovered; register ours first.
            $wa->getRegistry()->addExtensionRegistryFile('plg_content_dinkygallery');

            $wa->useStyle('plg_content_dinkygallery');
            $wa->useScript('plg_content_dinkygallery');

            // Client-built lightbox labels.
            Text::script('PLG_CONTENT_DINKYGALLERY_ARIA_PREV');
            Text::script('PLG_CONTENT_DINKYGALLERY_ARIA_NEXT');
            Text::script('PLG_CONTENT_DINKYGALLERY_ARIA_CLOSE');
            Text::script('PLG_CONTENT_DINKYGALLERY_ARIA_DIALOG');

            return 'registered';
        } catch (\Throwable $e) {
            // No asset manager, or the joomla.asset.json entry is missing: the
            // carousel still degrades to a scrollable strip of linked images.
            return 'FAILED - ' . $e->getMessage();
        }
    }

    /**
     * Whether the debug parameter is on.
     *
     * @return  boolean
     *
     * @since   1.0.0
     */
    private function debugEnabled(): bool
    {
        return (int) $this->params->get('debug', 0) === 1;
    }

    /**
     * Wraps the decision log in a single HTML comment, neutralising "--" and ">".
     *
     * @param   string[]  $lines  The decision log.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function debugComment(array $lines): string
    {
        $body = str_replace(['--', '>'], ['- -', '&gt;'], implode("\n", $lines));

        return "<!-- DinkyGallery:\n" . $body . "\n-->";
    }
}

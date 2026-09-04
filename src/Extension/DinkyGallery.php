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
use TheLoom\Plugin\Content\DinkyGallery\Helper\Sanitize;
use TheLoom\Plugin\Content\DinkyGallery\Helper\Shortcode;
use TheLoom\Plugin\Content\DinkyGallery\Helper\Thumbnailer;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

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
     * The context of the current onContentPrepare run, for the debug comment.
     *
     * @var    string
     * @since  1.0.0
     */
    private string $currentContext = '';

    /**
     * Contexts the shortcode is processed in. The com_content article/category/
     * featured/archive views are the guaranteed targets; com_content.feed is
     * handled if ever dispatched (core builds feeds without it); mod_custom.content
     * lets a Custom HTML module carry a gallery when its "Prepare Content" is on.
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
        'mod_custom.content',
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

        // Smart Search: strip the tags (both forms), never touch the index.
        if ($context === 'com_finder.indexer') {
            $item->text = preg_replace('#\{gallery\b[^}]*\}(?:[^{}]*\{/gallery\})?#i', '', $item->text) ?? $item->text;

            return;
        }

        if (!\in_array($context, self::CONTEXTS, true)) {
            return;
        }

        $this->currentContext = $context;
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

        $config = $this->config();

        $thumbs = ($config['thumbs_enabled'] && \function_exists('imagecreatetruecolor'))
            ? new Thumbnailer($config['thumb_dir'], $config['thumb_quality'])
            : null;

        $folder = new Folder(
            $config['base_directory'],
            $config['extensions'],
            $thumbs,
            $config['thumb_widths'],
            $config['thumb_large'],
            $config['thumb_prune']
        );
        $matches = (new Shortcode())->find($text);
        $labels = [
            'carousel' => Text::_('PLG_CONTENT_DINKYGALLERY_ARIA_CAROUSEL'),
            'prev'     => Text::_('PLG_CONTENT_DINKYGALLERY_ARIA_PREV'),
            'next'     => Text::_('PLG_CONTENT_DINKYGALLERY_ARIA_NEXT'),
        ];

        $rendered = 0;
        $tagLog   = [];

        // Work backwards so each replacement leaves the earlier byte offsets valid.
        foreach (array_reverse($matches) as $match) {
            $at = 'tag @' . $match['start'];

            if ($match['skip'] !== null) {
                $tagLog[$match['start']] = $at . ': left raw (' . $match['skip'] . ')';

                continue;
            }

            $options    = $match['options'] + $config['options'];
            $folderName = trim((string) $options['folder']);

            if ($folderName === '') {
                $tagLog[$match['start']] = $at . ': removed (no folder given)';
                $text = substr_replace($text, '', $match['start'], $match['length']);

                continue;
            }

            $at .= ' "' . $folderName . '"';
            $absPath = $folder->resolve($folderName);

            if ($absPath === null) {
                $tagLog[$match['start']] = $at . ': removed (' . $folder->getError() . ')';
                $text = substr_replace($text, '', $match['start'], $match['length']);

                continue;
            }

            $relBase = rtrim(str_replace('\\', '/', $config['base_directory']), '/')
                . '/' . trim(str_replace('\\', '/', $folderName), '/');

            $images = is_file($absPath)
                ? $folder->single($absPath, $relBase)
                : $folder->images($absPath, $relBase, (string) $options['sort']);

            // sigplus "default title" -> alt text for every image in this gallery.
            $deftitle = trim((string) ($options['deftitle'] ?? ''));

            if ($deftitle !== '') {
                foreach ($images as &$image) {
                    $image['alt'] = $deftitle;
                }

                unset($image);
            }

            if ($images === []) {
                $tagLog[$match['start']] = $at . ': removed (0 images) [' . $absPath . ']';
                $text = substr_replace($text, '', $match['start'], $match['length']);

                continue;
            }

            $gap    = Sanitize::cssLength((string) $options['gap']);
            $aspect = Sanitize::cssAspect((string) $options['aspect']);
            $cards  = max(1, (int) $options['cards']);

            $markup = $isHtml
                ? Render::carousel(
                    $images,
                    [
                        'cards'    => $options['cards'],
                        'size'     => $options['size'],
                        'loop'     => $options['loop'],
                        'middle'   => $options['middle'],
                        'gap'      => $gap,
                        'aspect'   => $aspect,
                        'sizes'    => '(min-width: 1200px) ' . (int) round(1200 / $cards)
                            . 'px, ' . (int) round(100 / $cards) . 'vw',
                        'card_min' => $config['card_min'],
                        'backdrop' => $config['backdrop_opacity'],
                        'lb_color' => $config['lightbox_rgb'],
                        'lb_pad'   => $config['lightbox_padding'],
                        'lb_aspect' => $config['lightbox_aspect'],
                    ],
                    $labels
                )
                : Render::plain($images);

            $text = substr_replace($text, $markup, $match['start'], $match['length']);
            $rendered++;

            $count = \count($images);
            $tagLog[$match['start']] = $at . ': ' . $count . ' image' . ($count === 1 ? '' : 's')
                . ' (cards=' . (int) $options['cards'] . ' size=' . (int) $options['size']
                . ' loop=' . (int) $options['loop'] . ' sort=' . $options['sort']
                . ' middle=' . $options['middle'] . ' gap=' . $gap
                . ' aspect=' . $aspect . ') [' . $absPath . ']';
        }

        ksort($tagLog);

        $log = array_merge(
            ['context: ' . $this->currentContext, 'mode: ' . ($isHtml ? 'carousel' : 'plain list')],
            array_values($tagLog)
        );

        if ($rendered > 0 && $isHtml) {
            $log[] = 'assets: ' . $this->loadAssets($doc);
        }

        if ($thumbs !== null) {
            $s = $thumbs->stats();

            if ($s['made'] || $s['reused'] || $s['pruned'] || $s['skipped']) {
                $log[] = 'thumbs: ' . $s['made'] . ' made, ' . $s['reused'] . ' reused, '
                    . $s['pruned'] . ' pruned, ' . $s['skipped'] . ' skipped';
            }
        }

        if ($this->debugEnabled()) {
            $text .= "\n" . $this->debugComment($log);
        }

        return $text;
    }

    /**
     * Reads the plugin parameters into resolved defaults.
     *
     * @return  array{
     *     base_directory: string, extensions: string[], card_min: string, backdrop_opacity: int,
     *     lightbox_rgb: string, lightbox_padding: string, lightbox_aspect: string,
     *     thumbs_enabled: bool, thumb_dir: string, thumb_widths: list<int>, thumb_large: int,
     *     thumb_quality: int, thumb_prune: bool, options: array<string, mixed>
     * }
     *
     * @since   1.0.0
     */
    private function config(): array
    {
        $params = $this->params;

        $base = trim((string) $params->get('base_directory', 'images'));

        return [
            'base_directory'   => $base !== '' ? $base : 'images',
            'extensions'       => Sanitize::extensionList((string) $params->get('image_extensions', 'jpg,jpeg,png,webp,gif,avif')),
            'card_min'         => trim((string) $params->get('card_min', '13rem')) ?: '13rem',
            'backdrop_opacity' => (int) $params->get('backdrop_opacity', 60),
            'lightbox_rgb'     => Sanitize::hexToRgb((string) $params->get('lightbox_color', '#000000')),
            'lightbox_padding' => Sanitize::cssLength((string) $params->get('lightbox_padding', '10px'), '10px'),
            'lightbox_aspect'  => Sanitize::lightboxAspect((string) $params->get('lightbox_aspect', 'viewport')),
            'thumbs_enabled'   => (int) $params->get('thumbnails', 1) === 1,
            'thumb_dir'        => Sanitize::thumbDirName((string) $params->get('thumb_dir', '.thumbs')),
            'thumb_widths'     => Sanitize::widthList((string) $params->get('thumb_widths', '480,768,1024,1600')),
            'thumb_large'      => max(0, (int) $params->get('thumb_large', 1920)),
            'thumb_quality'    => min(100, max(1, (int) $params->get('thumb_quality', 82))),
            'thumb_prune'      => (int) $params->get('thumb_prune', 1) === 1,
            'options'          => [
                'folder' => '',
                'cards'  => (int) $params->get('visible_cards', 3),
                'size'   => (int) $params->get('lightbox_size', 100),
                'loop'   => (int) $params->get('lightbox_loop', 1),
                'sort'   => (string) $params->get('sort_order', 'asc'),
                'middle' => (string) $params->get('middle_zone_action', 'none'),
                'gap'    => (string) $params->get('card_gap', '0'),
                'aspect' => (string) $params->get('card_aspect', '4/3'),
            ],
        ];
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

            // Register inline (no joomla.asset.json to auto-discover). version=auto
            // stamps the media version, which Joomla refreshes on every extension
            // install/update, so a plugin update busts the browser cache. The
            // resolver inserts the css/ and js/ sub-folders itself.
            $wa->registerAndUseStyle(
                'plg_content_dinkygallery',
                'plg_content_dinkygallery/dinkygallery.css',
                ['version' => 'auto']
            );
            $wa->registerAndUseScript(
                'plg_content_dinkygallery',
                'plg_content_dinkygallery/dinkygallery.js',
                ['version' => 'auto'],
                ['type' => 'module']
            );

            // Client-built lightbox labels.
            Text::script('PLG_CONTENT_DINKYGALLERY_ARIA_PREV');
            Text::script('PLG_CONTENT_DINKYGALLERY_ARIA_NEXT');
            Text::script('PLG_CONTENT_DINKYGALLERY_ARIA_CLOSE');
            Text::script('PLG_CONTENT_DINKYGALLERY_ARIA_DIALOG');
            Text::script('PLG_CONTENT_DINKYGALLERY_ARIA_POSITION');

            return 'registered';
        } catch (\Throwable $e) {
            // No asset manager available: the carousel still degrades to a
            // scrollable strip of linked images.
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

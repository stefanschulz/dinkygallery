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
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;

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
     * carousel markup, registering the CSS/JS once at least one gallery was built.
     *
     * Slice 1: wired but inert — the shortcode scan, folder resolution and rendering
     * land in Slice 2.
     *
     * @param   ContentPrepareEvent  $event  The onContentPrepare event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onContentPrepare(ContentPrepareEvent $event): void
    {
        // Slice 2: context guard, shortcode replacement, asset registration, debug comment.
    }
}

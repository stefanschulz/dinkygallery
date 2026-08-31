# Changelog

All notable changes to `plg_content_dinkygallery` are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

First release. `{gallery …}` in a `com_content` article becomes an in-article card
carousel with a click-through lightbox; zero third-party JavaScript.

### Plugin

- Content plugin `plg_content_dinkygallery` (group `content`, `method="upgrade"`,
  namespace `TheLoom\Plugin\Content\DinkyGallery`), DI service provider, subscribes to
  `onContentPrepare`.
- Handled contexts: `com_content.article` / `.category` / `.featured` / `.archive` /
  `.feed`, and `mod_custom.content` (a Custom HTML module with *Prepare Content* on).
  `com_finder.indexer` strips the tags so they never reach the search index. Any other
  context is left untouched.
- Per-tag pipeline: resolve the folder, list the images, render, splice into the text —
  working from the last match backwards so byte offsets stay valid. Idempotent: the
  output contains no `{gallery`, so the intro/full passes and re-entry cannot double up.
  Wrapped in `try/catch (\Throwable)`; a failure restores the original text.
- Non-HTML document, `tmpl=component`, `format!=html` or `print=1` → a plain
  `<ul class="dg-plain">` of linked images, no carousel, no assets.
- `debug=1` appends one HTML comment per processed item: context, render mode, and one
  line per tag with folder, resolved path, image count, the options actually used, and
  the reason a tag was skipped or removed.

### Shortcode

- `{gallery my-folder}` and `{gallery folder="…" cards="…" size="…" loop="…" sort="…"
  middle="…" gap="…"}` (shell-style tokens; a leading bare word is the folder; unknown
  attributes ignored). Each attribute overrides the matching parameter.
- Best-effort skip of a `{gallery}` inside an unclosed `<code>` / `<pre>`.
- Folder safety: the resolved real path is rejected (tag removed, reason logged) if it
  contains `..`, starts with a slash, does not exist, is not a directory, or resolves
  outside `JPATH_ROOT/<base_directory>`.

### Carousel

- CSS scroll-snap flex strip: scrolls by touch / wheel / trackpad / scrollbar with no
  JavaScript. Card basis `max(--dg-card-min, (100% - gaps) / --dg-cards)` — one rule,
  no media queries: a card never falls below `card_min`, the strip shows fewer and
  scrolls. Fixed-aspect `object-fit: cover` boxes, `:focus-visible` outlines,
  `prefers-reduced-motion` handling, RTL arrow mirroring.
- JS (ES module): reveals the prev/next arrows only when the track overflows, scrolls
  one card per click, wraps at the ends when `data-loop="1"` else disables the end
  arrow. Intended position tracked in `target`, not read from `scrollLeft` (which lags
  during a smooth scroll); a 140 ms settle timer re-syncs the arrows; `resize`-aware.

### Lightbox

- One `.dg-lb` element, built on the first card click and reused. The dim sits on the
  **frame** (`rgba(var(--dg-lb-rgb), var(--dg-backdrop))`), not on a full-viewport
  backdrop, so a lightbox smaller than the viewport leaves the page around it clear; at
  `size=100` the frame covers everything. The backdrop stays a transparent
  click-catcher — a click outside the frame closes.
- Fixed-size frame (`X vw × X vh` from `data-size`, capped at the viewport); image
  `object-fit: contain`, inset from the edge by `lightbox_padding`.
- Left / right thirds are `<button>` nav zones with a chevron: dim by default, bright
  on hover, greyed while `disabled` — the dead end when `data-loop="0"` (also
  `aria-disabled`), mirroring the carousel. Zones hidden for a one-image gallery. The
  middle third does nothing unless `middle=close`, which reveals a zone that closes and
  shows a `zoom-out` cursor.
- Closes on the ×, a click outside the frame, or `Esc`. `←` / `→` navigate. Focus moves
  to × on open, is trapped in the dialog (`role="dialog"`, `aria-modal`), returns to
  the opening card on close. `<html>` scroll is locked with scrollbar-width
  compensation.
- Incoming image preloaded (previous stays visible until ready), spinner + opacity dip
  after 150 ms, both neighbours preloaded. 120 ms fade, none under
  `prefers-reduced-motion`. ARIA labels via `Text::script()` / `Joomla.Text` with
  English fallbacks.

### Parameters

`base_directory`, `image_extensions`, `sort_order`, `visible_cards`, `card_aspect`,
`card_min`, `card_gap`, `lightbox_size`, `lightbox_color`, `lightbox_padding`,
`lightbox_loop`, `middle_zone_action`, `backdrop_opacity` (fieldset `basic`), `debug`
(fieldset `advanced`). Complete `en-GB` and `de-DE` language files. `card_gap` and
`lightbox_padding` go through a CSS-length sanitiser (a bare `0` → `0px`, which a
unitless zero would otherwise break in the card `calc()`; non-lengths → the fallback).
`lightbox_color` is parsed from hex to an `r, g, b` triplet.

### Assets & build

- `media/plg_content_dinkygallery/{css,js}/` — Joomla's relative asset resolver only
  finds files in those sub-folders. `joomla.asset.json` declares the style and the
  module script; the plugin calls `getRegistry()->addExtensionRegistryFile()` before
  `useStyle`/`useScript` (extension asset files are not auto-discovered) and only when a
  carousel was actually emitted — exactly one CSS + one JS include per page.
- `build.xml` Phing target `package` → `.releases/plg_content_dinkygallery-<x.y.z>.zip`
  + `update.xml` with sha256/384/512, `<element>dinkygallery</element>` /
  `<folder>content</folder>`, targetplatform 5.1–5.5 / 6.0–6.3, `php_minimum` 8.2.
- GPLv3-or-later (`LICENSE` / `LICENSE.txt`, header in every PHP file).

### Known limitations

- `com_content` builds RSS feed items straight from the article text without firing
  `onContentPrepare`, so `{gallery …}` cannot be replaced in feeds. The
  `com_content.feed` context is handled if ever dispatched, but core does not dispatch
  it. Article / category / featured / archive views are unaffected.
- No server-side thumbnails in v1 — cards load the full images (with intrinsic
  `width`/`height`, so no layout shift). Thumbnail cache + `srcset` is a v1.1 candidate.

### Deliberate deviations from the spec

- Requirement 9 (frame transparent, whole viewport dimmed) → the dim sits on the frame;
  a small lightbox leaves the page around it clear. Client decision after review.
- `base_directory` is a `text` field, not `folder` — Joomla has no `folder` field type
  and `folderlist` does not fit the root-relative path semantics.
- `gap` added to the shortcode attributes for parity with the other visual settings.
- GPLv3-or-later rather than GPLv2 (matches the repo `LICENSE` and DinkyTags).

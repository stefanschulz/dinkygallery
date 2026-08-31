# Changelog

All notable changes to `plg_content_dinkygallery` are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [1.3.0] - 2026-08-31

Lighthouse (Chrome, Navigation / Desktop) on an article page: Performance **97**,
Accessibility **100**, Best Practices **100**, SEO **100**. The Performance gap is
"properly size images / next-gen formats" — the documented v1.1 `srcset` item.

### Added
- **`aspect=` shortcode attribute** — per-gallery override of `card_aspect`.
  Accepts `W/H`, `W:H` (normalised) or a bare number; anything else falls back
  to `4/3`.
- **`deftitle` → alt text.** sigplus's opening-tag `deftitle="…"` is now used as
  the alt text for every image in that gallery, so a migrated single-image
  gallery reads as its title instead of the file name.

### Changed
- **Lightbox: a click during a slide is queued**, not dropped — the last
  direction wins, opposite clicks cancel, and it is applied as one step when the
  slide finishes (`flushPendingNav()`).
- `PLG_CONTENT_DINKYGALLERY_ARIA_POSITION` uses Joomla's `%1$s` / `%2$s`
  placeholders instead of `{current}` / `{total}`.
- README: note that `.avif` card dimensions need GD/PHP AVIF support (the CSS
  aspect box prevents layout shift regardless; the lightbox is unaffected).

## [1.2.0] - 2026-08-31

### Added
- **sigplus compatibility.** `Shortcode::find()` now also matches the classic
  `{gallery …opening attrs…}path/or/file{/gallery}` form: the folder (or single
  image file) is taken from the text between the tags, and the opening
  attributes are parsed the same way — recognised DinkyGallery attributes still
  apply, sigplus-only ones (`width`, `height`, `alignment`, `deftitle`,
  `lightbox`, …) are ignored. `Folder::resolve()` strips a leading slash
  (`/x` ≡ `x`, both under the base; `..` / backslashes still rejected) and
  accepts a path that points straight at an image file, rendered as a one-image
  gallery via the new `Folder::single()`. The Smart Search tag-stripper covers
  the closing-tag form too.

  Motivated by the empulsiv site (the v1 replacement target): all 211 of its
  `{gallery}` shortcodes use the closing-tag form — 204 as bare `{gallery}`,
  the rest with legacy display attributes; three use a leading slash, four
  point at a single `.jpg`. Set `base_directory` to `images/stories` there.

## [1.1.0] - 2026-08-31

Lightbox and carousel polish. Verified on the Joomla 5 dev stack (Cassiopeia,
PHP 8.x); no PHP notices, no console errors.

### Added
- **Lightbox slide.** Prev / next now slides the image: a second `.dg-lb__img`
  is appended inside a new clipping `.dg-lb__stage`, the outgoing one leaves
  toward the opposite edge (280 ms). Falls back to the in-place swap under
  `prefers-reduced-motion: reduce`; a click during a slide is ignored; a close
  mid-slide collapses cleanly. The transition is kicked with a forced reflow,
  not `requestAnimationFrame` (which is paused while the tab is not painting).
- **Position indicator.** A centred `i / N` pill that rides the first visible
  carousel card and shows that card's position (server-rendered as `1 / N` on the
  first card for the no-JS baseline, then moved and updated by the script on
  scroll; only when the gallery has more than one image); a `3 / 12` pill centred
  on the lightbox frame's bottom edge, updated on every navigation; and a
  visually-hidden `role="status"` region that announces "Image 3 of 12"
  (`PLG_CONTENT_DINKYGALLERY_ARIA_POSITION`, en-GB + de-DE) to screen readers.
- **Full-size preload on intent.** Pointing at or focusing a card warms its
  full-size image (`new Image()`, once per URL), so the lightbox opens instantly.

### Changed
- The carousel strip's native horizontal scrollbar is hidden on all engines
  (`scrollbar-width` / `-ms-overflow-style` / `::-webkit-scrollbar`); scrolling
  by arrows, touch, wheel and trackpad is unchanged. It became visible in 1.0.0
  once the strip was actually made scrollable.
- Assets are registered inline via `registerAndUseStyle` / `registerAndUseScript`
  with `version=auto` instead of a `joomla.asset.json` — one less moving part (no
  `addExtensionRegistryFile` dance) and the media version, refreshed by Joomla on
  every extension install/update, busts the browser cache. `joomla.asset.json`
  removed; the `<media>` manifest entry updated.
- Repository: `.gitattributes` (LF, binary image/zip types) and `.editorconfig`.

## [1.0.0] - 2026-08-31

First release. `{gallery …}` in a `com_content` article becomes an in-article card
carousel with a click-through lightbox; zero third-party JavaScript.

Verified against the spec §9 matrix on **Joomla 6.x** (disposable stack, installed
from the built ZIP) and **Joomla 5.x** (dev stack; clean ZIP install also confirmed) —
PHP 8.x, Cassiopeia. No PHP notices, no console errors.

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
  `prefers-reduced-motion` handling, RTL arrow mirroring. The strip and card
  selectors are scoped under `.dg` (and `.dg-plain` doubled) to out-weigh site
  templates that reset `ul` / `li` — Cassiopeia's `.com-content-article ul {
  overflow: hidden }` would otherwise disable the strip's own scrolling.
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
  the opening card on close. Every other `<body>` child is set `inert` while the
  lightbox is open. `<html>` scroll is locked with scrollbar-width compensation.
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

# Changelog

All notable changes to `plg_content_dinkygallery` are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- `lightbox_color` (colour field, default `#000000`) and `lightbox_padding`
  (CSS length, default `10px`) parameters. The frame mat is now
  `rgba(var(--dg-lb-rgb), var(--dg-backdrop))` — the base colour is
  configurable, the backdrop opacity still applies on top. The image is inset
  from the frame edge by `--dg-lb-padding` (`inset` + `max-width/height:
  calc(100% - 2 * pad)`), so it never touches the edge. Both travel to the
  shared lightbox as `data-lb-color` / `data-lb-pad` on `.dg`, like the other
  lightbox settings. Hex is parsed to an `r, g, b` triplet; a non-length
  padding falls back to `10px`.
- Slice 4 — lightbox (CSS + JS):
  - one `.dg-lb` element, built on the first card click and reused. Fixed,
    full-viewport. The dim sits on the **frame** itself
    (`rgba(0,0,0,var(--dg-backdrop))` from `data-backdrop`), not on a
    full-viewport backdrop: a lightbox smaller than the viewport leaves the
    page around it clear, and at `size=100` the frame covers everything as
    before. `.dg-lb__backdrop` stays as a transparent full-viewport click
    catcher (`cursor: zoom-out`) — a click outside the frame closes.
  - fixed-size frame (`--dg-size` from `data-size`, `Xvw × Xvh`, capped at the
    viewport); the image is `object-fit: contain`, centred; empty frame area is
    a translucent dark mat, not a black bar.
  - left / right thirds are real `<button>` nav zones; the middle third is a
    no-op unless `data-middle="close"`, which reveals a `.dg-lb__zone--mid`
    that closes and shows a `zoom-out` cursor (`!important` — the site
    template's `[type="button"]` cursor rule loads later at equal specificity).
    Zones hidden when a gallery has one image; the end zone is `disabled` at
    the respective end when `data-loop="0"`, wraps otherwise.
  - close on the × (top-right of the frame), on a backdrop click outside the
    frame, or on `Esc`. `←` / `→` navigate. Focus moves to × on open, is
    trapped within the dialog while open (`role="dialog"`, `aria-modal`), and
    returns to the originating card link on close. Body scroll is locked with
    scrollbar-width compensation.
  - the incoming image is preloaded (the previous one stays visible until it
    is ready); a spinner + opacity dip appears after 150 ms; both neighbours
    are preloaded after each swap. Backdrop + frame fade in over 120 ms, none
    under `prefers-reduced-motion`.
  - lightbox ARIA labels come from `Text::script()` (`Joomla.Text`) with
    English fallbacks baked into the module.
- `card_gap` parameter (default `0`) plus a `gap=` shortcode attribute: a CSS
  length for the space between carousel cards, fed to `--dg-gap`. A bare `0` is
  emitted as `0px` — a unitless zero makes the card-basis `calc()` invalid
  (percentage minus number) and collapses the layout. Non-length values are
  rejected to `0px` so the value can't break out of the `style` attribute.
- Slice 3 — carousel styling and behaviour (no lightbox yet):
  - `css/dinkygallery.css`: scroll-snap flex track; card basis
    `max(--dg-card-min, (100% - gaps) / --dg-cards)` so a card never falls below
    `card_min` and the strip just shows fewer and scrolls — one rule, no media
    queries (the spec offered this or a media-query step-down; picked this).
    Fixed-aspect `object-fit: cover` card boxes, circular arrow buttons with a
    CSS chevron, `:focus-visible` outlines, RTL arrow mirroring, a light
    `.dg-plain` fallback grid, and a `prefers-reduced-motion` block.
  - `js/dinkygallery.js` (ES module): reveals `.dg-arrow`s only when the track
    overflows, scrolls one card per click, wraps at the ends when `data-loop=1`
    else disables the end arrow. The intended position is tracked in `target`
    (not read back from `scrollLeft`, which lags during a smooth scroll, so
    rapid clicks used to collapse onto one step); a 140 ms settle timer
    re-syncs the arrow state after the final scroll event. `resize`-aware.
- Slice 2 — server-side shortcode processing:
  - `Helper\Shortcode`: finds `{gallery ...}` (bare and attribute forms, the legacy gallery plugin
    grammar, leading bare token accepted as folder), best-effort skip of tags
    inside an unclosed `<code>` / `<pre>`.
  - `Helper\Folder`: folder-name validation (rejects `..`, leading slash,
    non-existent target, or a real path escaping `base_directory`), natural
    filename sort asc/desc, per-request `getimagesize()` cache, root-relative
    URLs with per-segment encoding.
  - `Helper\Render`: the `.dg` scroll-snap carousel markup (stable class /
    `data-*` contract) and the `.dg-plain` no-JS / non-HTML fallback list.
  - `DinkyGallery::onContentPrepare` wired: context guard
    (`com_content.article|category|featured|archive|feed`), Smart Search indexer
    strips the tags, per-tag resolve → list → render with byte-offset-safe
    reverse replacement, `debug=1` decision comment, one CSS + one module script
    registered via `WebAssetManager` only when a carousel was emitted.
  - ARIA label strings (`_ARIA_*`) in en-GB + de-DE.
- Media assets moved to the `css/` + `js/` subfolders Joomla's relative asset
  resolver requires (`media/plg_content_dinkygallery/{css,js}/`); manifest
  `<media>` updated.

### Known limitations
- com_content builds RSS feed items straight from introtext/fulltext without
  firing `onContentPrepare`, so `{gallery ...}` cannot be replaced in feeds
  through this event. The `com_content.feed` context is handled if ever invoked,
  but core does not invoke it. Article / category / featured / archive views
  (all of the empulsiv target) are unaffected.

### Added
- Repository skeleton (Slice 1 — installable but inert):
  - Extension manifest `dinkygallery.xml` (`type=plugin`, `group=content`,
    `method=upgrade`, `<namespace path="src">`, `<media>` for the CSS/JS/asset
    manifest, the parameter form in `basic` + `advanced` fieldsets, an
    update server entry).
  - DI service provider `services/provider.php` (registers `PluginInterface` as
    `new DinkyGallery(Dispatcher, PluginHelper::getPlugin('content','dinkygallery'))`).
  - `DinkyGallery` content-plugin class: subscribes to `onContentPrepare`
    (`SubscriberInterface`), `$autoloadLanguage = true`. Handler body lands in
    Slice 2.
  - `media/plg_content_dinkygallery/`: `joomla.asset.json` declaring the style
    (`plg_content_dinkygallery`) and module script; placeholder `dinkygallery.css`
    and `dinkygallery.js`.
  - Complete `en-GB` and `de-DE` language files (`.ini` + `.sys.ini`).
  - `build.xml` Phing target `package`: builds
    `.releases/plg_content_dinkygallery-<x.y.z>.zip` (version read from
    `dinkygallery.xml`) and `.releases/update.xml` with sha256/384/512, plugin
    update fields (`<element>dinkygallery</element>`, `<folder>content</folder>`),
    targetplatform `5.1-5.5 / 6.0-6.3`, `php_minimum` 8.2. README / CHANGELOG /
    Apache-named `LICENSE` excluded from the package; `LICENSE.txt` shipped.
  - `LICENSE.txt` (GPLv3 copy for packaging) and `.gitignore` entries for
    `LICENSE.txt` (un-ignore), `/.idea/`, `/.docker/`, `/.releases/`.
  - `.doc/WORKPLAN.md` (phased build plan) alongside the requirements spec.
  - `.docker/` local test stack (git-ignored): `joomla:5-apache` + `mariadb:11.4`
    behind the shared Traefik proxy, `setup.sh` / `reset.sh`, generated spec §9
    image matrix, five article fixtures (ids 101-105).

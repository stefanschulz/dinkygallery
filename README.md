# DinkyGallery

`plg_content_dinkygallery` — a small, dependency-free Joomla **content plugin** that
turns a `{gallery …}` shortcode in an article into an in-article **card carousel**
with a click-through **lightbox**.

Written for sites that only need "point the tag at a folder, show the pictures": a
`{gallery <folder>}` shortcode, a cleaner and opinionated presentation, and **zero
third-party JavaScript** — one hand-written stylesheet and one ES-module script, no
jQuery, Swiper, GLightbox or Fancybox.

- **Target:** Joomla 5.1+ and 6.x, PHP 8.2+, site frontend only.
- **License:** GNU General Public License v3 or later — see [LICENSE](LICENSE).
- **Architecture:** [.doc/ARCHITECTURE.md](.doc/ARCHITECTURE.md).

## Install

Install the package zip (`plg_content_dinkygallery-<x.y.z>.zip`) through **System →
Install → Extensions**, then enable **Content - DinkyGallery** under **System →
Plugins**. Set `base_directory` to the folder your gallery folders live under
(default `images`). That is the whole setup.

## Build & test

Development tooling only — the plugin ships with no runtime dependencies.

```bash
composer install               # phpcs + phpunit (dev only)
composer run lint               # PSR-12, the way the Joomla CMS lints itself
composer run test               # unit tests on this machine
.docker/test.sh                 # the same tests on a PHP that has GD + WebP — the run that counts
.docker/gallery.sh              # folder listing, thumbnail cache and a full render against the fixtures
node tests/parity/contract.mjs  # the dg-* class / --dg-* property / data-* names agree across PHP, CSS and JS
phing package                   # build .releases/plg_content_dinkygallery-<version>.zip + update.xml
```

`composer run test` on a host without GD skips the `Thumbnailer` cases; `.docker/test.sh`
runs them. The zip and a matching `update.xml` land in `.releases/`. A disposable Joomla
stack for testing lives in `.docker/` — see `.docker/README.md`.

## Shortcode

Put one of these in an article (intro text or full text). It may appear several times
per article — each becomes an independent gallery.

```
{gallery my-folder}
{gallery folder="my-folder" cards="4" size="80" loop="0" sort="desc" middle="close" gap="12px"}
{gallery}my-folder{/gallery}                 ← sigplus-style, folder between the tags
{gallery}my-folder/photo.jpg{/gallery}       ← a single image
```

- **Bare form** — everything after `gallery ` up to `}` is the folder name.
- **Attribute form** — space-separated `name="value"` pairs (quoted or bare). A leading
  bare word is still taken as the folder. Unknown attributes are ignored.
- **Closing-tag form** (sigplus compatibility) — the folder, or a single image file,
  is the text between `{gallery …}` and `{/gallery}`. Recognised attributes in the
  opening tag still apply; sigplus-only ones are ignored. A leading `/` on the path is
  fine.

| attribute | overrides parameter | values |
|---|---|---|
| `folder` | `base_directory` is the root; this is the sub-folder | folder name, may be nested (`events/2026`) |
| `cards` | `visible_cards` | integer ≥ 1 |
| `size` | `lightbox_size` | 10–100 (percent of the viewport) |
| `loop` | `lightbox_loop` | `0` / `1` |
| `sort` | `sort_order` | `asc` / `desc` |
| `middle` | `middle_zone_action` | `none` / `close` |
| `gap` | `card_gap` | a CSS length (`0`, `12px`, `1rem`) |
| `aspect` | `card_aspect` | `W/H`, `W:H` or a number (`4/3`, `16:9`, `1.5`) |
| `deftitle` | — | sigplus opening attribute; used as the images' alt text |

**Folder safety.** The path is resolved under `JPATH_ROOT/<base_directory>/`. A leading
slash is ignored; a tag renders as nothing (with a reason in the debug comment) if the
resolved real path contains `..`, does not exist, is neither a directory nor an image
file, or resolves outside `base_directory`. Files count as images when their
lower-cased extension is in `image_extensions`; dotfiles and everything else are
ignored. Images are sorted by filename, natural order.

A `{gallery …}` inside an unclosed `<code>` or `<pre>` (best effort) is left as
literal text.

## What renders

**In the article** — a horizontal strip of cards, `visible_cards` across on a wide
screen, dropping to fewer (never narrower than `card_min`) as the screen narrows. It is
a CSS scroll-snap strip: it scrolls by touch, trackpad, wheel and the scrollbar with no
JavaScript at all. With JavaScript, previous / next arrows appear and each scrolls the
strip by one card; at the ends they are disabled unless the gallery loops.

**The lightbox** — clicking a card opens one modal viewer (built once, reused). The
image is fitted by aspect ratio (`contain`) inside a fixed-size frame
(`lightbox_size` % of the viewport). Navigation: the **left third** of the frame is
"previous", the **right third** is "next"; the **middle third** does nothing unless
`middle_zone_action` / `middle=` is `close`. It closes on the **×** (top-right), on a
click **outside the frame**, and on **Esc**; `←` / `→` navigate (with a slide
transition — instant under *reduce motion*); focus is trapped in the dialog and
returns to the card on close.

Only the frame is dimmed (`lightbox_color` at `backdrop_opacity` %): a lightbox smaller
than the viewport leaves the page around it clear. A `3 / 12` position counter sits on
the frame's bottom edge (and is announced to screen readers). The incoming image is
preloaded (the previous one stays visible until it is ready), both neighbours are
preloaded, and a card's full image is warmed as soon as you point at it — navigation
and opening are instant.

A small `i / N` badge rides the first visible carousel card and shows its
position (`1 / N` on the first card without JavaScript).

**Images.** With the **Thumbnails** setting on (default), cards are served from a
cached `srcset` of width-scaled copies instead of the full-resolution originals, and
the lightbox loads a copy capped at `thumb_large` px on its long edge. Copies live in
a `.thumbs` subfolder of each gallery folder and regenerate when an image is replaced.
See [Parameters](#parameters).

**Without JavaScript** — the cards are plain links to the full images; the strip still
scrolls. On a feed, `tmpl=component`, `print=1` or any non-HTML document, the gallery
is a plain `<ul class="dg-plain">` list of linked images, no carousel, no assets.

## Parameters

The plugin edit screen groups these into four tabs — **Basic** (media source and
carousel), **Lightbox**, **Thumbnails**, and **Advanced**.

**Basic**

| parameter | default | what it does |
|---|---|---|
| `base_directory` | `images` | folder, relative to the site root, that gallery names resolve under |
| `image_extensions` | `jpg,jpeg,png,webp,gif,avif` | comma list, case-insensitive |
| `sort_order` | `asc` | natural filename sort direction |
| `visible_cards` | `3` | cards shown at once on a wide screen |
| `card_aspect` | `4/3` | card image box — `4/3` · `3/2` · `1/1` · `16/9`, `object-fit: cover` |
| `card_min` | `13rem` | a card never gets narrower than this; the strip shows fewer instead |
| `card_gap` | `0` | CSS length between cards (a bare `0` is emitted as `0px`) |
| `lightbox_loop` | `yes` | wrap previous / next at the ends — the carousel arrows *and* the lightbox |

**Lightbox**

| parameter | default | what it does |
|---|---|---|
| `lightbox_aspect` | `viewport` | frame shape: `viewport` (X vw × X vh), a ratio (`4/3`, `3:2`, `1.5`) for a fixed shape on any screen, or `image` to hug each image |
| `lightbox_size` | `100` | frame size as a percentage of the viewport |
| `lightbox_padding` | `10px` | CSS length keeping the image off the frame edge |
| `lightbox_color` | `#000000` | base colour of the dimmed lightbox mat |
| `backdrop_opacity` | `60` | opacity of the lightbox mat, percent |
| `middle_zone_action` | `none` | what the middle third of the lightbox does — `none` / `close` |

**Thumbnails**

| parameter | default | what it does |
|---|---|---|
| `thumbnails` | `yes` | serve cached width-scaled copies (with `srcset`) instead of the originals; needs GD |
| `thumb_dir` | `.thumbs` | cache subfolder made inside each gallery folder (one path segment) |
| `thumb_widths` | `480,768,1024,1600` | pixel widths generated for the card `srcset`; widths ≥ an image's own width are skipped |
| `thumb_large` | `1920` | long-edge cap, in px, of the copy the lightbox loads; `0` serves the untouched original |
| `thumb_quality` | `82` | JPEG / WebP encoder quality, 1–100 (PNG / GIF stay lossless) |
| `thumb_prune` | `yes` | after listing a folder, delete copies that are no longer current (replaced image, dropped width, removed original) |

**Advanced**

| parameter | default | what it does |
|---|---|---|
| `debug` | `no` | append an HTML comment near the processed shortcodes with the decision trail |

All labels and help texts ship in **en-GB** and **de-DE**.

## Debugging

Set **Debug comment** to *Yes*. Each processed article / module then carries a comment
listing the context, the render mode, and one line per `{gallery}` tag — folder,
resolved path, image count, the options actually used, and why a tag was skipped or
removed:

```html
<!-- DinkyGallery:
context: com_content.article
mode: carousel
tag @82 "events/grillfest": 14 images (cards=3 size=100 loop=1 sort=asc middle=none gap=0px aspect=4/3) [/var/www/html/images/stories/events/grillfest]
tag @530 "missing": removed (folder not found)
assets: registered
thumbs: 41 made, 0 reused, 0 pruned, 0 skipped
-->
```

Quick check without the browser:

```bash
curl -s https://example.com/some-article | grep -E 'dg-track|dg-card|DinkyGallery:'
```

## Notes & limitations

- **Feeds.** Joomla's `com_content` builds RSS items straight from the article text
  without firing `onContentPrepare`, so `{gallery …}` cannot be replaced in a feed
  through this plugin. The `com_content.feed` context is handled if it is ever
  dispatched, but core does not dispatch it. Article, category, featured and archive
  views are unaffected.
- **Modules.** A Custom HTML module (`mod_custom`) with *Prepare Content* enabled will
  render a `{gallery}` too. Other module types are not guaranteed.
- **Smart Search.** During indexing the shortcode is stripped so it never pollutes the
  search index.
- **Subfolder installs.** URLs are built with `Uri::root(true)` and work under a
  subdirectory.
- **Thumbnails need GD.** Generation uses the GD extension. Without it (or for a
  source format GD cannot read/write, typically AVIF) the plugin falls back to serving
  the original everywhere — no error, just no `srcset`. `.avif` sources are always
  served as-is.
- **Thumbnail writes.** The web server user must be able to create the `.thumbs`
  subfolder and write into it; on a read-only media tree, turn **Thumbnails** off.
  A copy is written on the first request that needs it (that visitor pays the cost),
  then reused.
- **AVIF dimensions.** `@getimagesize()` needs GD/PHP with AVIF support to read an
  `.avif` file's size; without it those cards render without `width`/`height` (the CSS
  aspect box still prevents layout shift, and the lightbox is unaffected).

## Scope

**v1.5** does everything above. Deferred to a later release: WebP/AVIF transcoding of
other formats, captions / EXIF, zoom & pan in the lightbox, slideshow / autoplay,
video, per-image links, a download button, fuller RTL polish, multilingual caption
files.

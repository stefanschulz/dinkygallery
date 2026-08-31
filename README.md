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

To build the zip from source you need [Phing](https://www.phing.info/):

```bash
phing package
```

The zip and a matching `update.xml` land in `.releases/`.

## Shortcode

Put one of these in an article (intro text or full text). It may appear several times
per article — each becomes an independent gallery.

```
{gallery my-folder}
{gallery folder="my-folder" cards="4" size="80" loop="0" sort="desc" middle="close" gap="12px"}
```

- **Bare form** — everything after `gallery ` up to `}` is the folder name.
- **Attribute form** — space-separated `name="value"` pairs (quoted or bare). A leading
  bare word is still taken as the folder. Unknown attributes are ignored.

| attribute | overrides parameter | values |
|---|---|---|
| `folder` | `base_directory` is the root; this is the sub-folder | folder name, may be nested (`events/2026`) |
| `cards` | `visible_cards` | integer ≥ 1 |
| `size` | `lightbox_size` | 10–100 (percent of the viewport) |
| `loop` | `lightbox_loop` | `0` / `1` |
| `sort` | `sort_order` | `asc` / `desc` |
| `middle` | `middle_zone_action` | `none` / `close` |
| `gap` | `card_gap` | a CSS length (`0`, `12px`, `1rem`) |

**Folder safety.** The folder is resolved under `JPATH_ROOT/<base_directory>/`. A tag is
rendered as nothing (with a reason in the debug comment) if the resolved real path
contains `..`, starts with a slash, does not exist, is not a directory, or resolves
outside `base_directory`. Files count as images when their lower-cased extension is in
`image_extensions`; dotfiles and everything else are ignored. Images are sorted by
filename, natural order.

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
click **outside the frame**, and on **Esc**; `←` / `→` navigate; focus is trapped in
the dialog and returns to the card on close.

Only the frame is dimmed (`lightbox_color` at `backdrop_opacity` %): a lightbox smaller
than the viewport leaves the page around it clear. The incoming image is preloaded (the
previous one stays visible until it is ready) and both neighbours are preloaded for
instant navigation.

**Without JavaScript** — the cards are plain links to the full images; the strip still
scrolls. On a feed, `tmpl=component`, `print=1` or any non-HTML document, the gallery
is a plain `<ul class="dg-plain">` list of linked images, no carousel, no assets.

## Parameters

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
| `lightbox_size` | `100` | frame size as a percentage of the viewport (`X vw × X vh`) |
| `lightbox_color` | `#000000` | base colour of the dimmed lightbox mat |
| `lightbox_padding` | `10px` | CSS length keeping the image off the frame edge |
| `lightbox_loop` | `yes` | wrap previous / next at the ends |
| `middle_zone_action` | `none` | what the middle third of the lightbox does — `none` / `close` |
| `backdrop_opacity` | `60` | opacity of the lightbox mat, percent |

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
tag @82 "events/grillfest": 14 images (cards=3 size=100 loop=1 sort=asc middle=none gap=0px) [/var/www/html/images/stories/events/grillfest]
tag @530 "missing": removed (folder not found)
assets: registered
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
- **No thumbnails in v1.** Cards load the full-size images (with intrinsic
  `width`/`height` so there is no layout shift). Server-side thumbnail generation and
  `srcset` are a v1.1 candidate.

## Scope

**v1.0** does everything above. Deferred to **v1.1+**: server-side thumbnail cache +
`srcset`, captions / EXIF, zoom & pan in the lightbox, slideshow / autoplay, video,
per-image links, a download button, fuller RTL polish, multilingual caption files.

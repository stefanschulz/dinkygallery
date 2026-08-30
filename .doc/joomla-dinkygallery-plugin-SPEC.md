# DinkyGallery — Requirements & Development Spec (v1)

Self-contained brief for a Joomla **content** plugin that renders image galleries
inside articles as a card carousel with a click-through lightbox. Written so a
developer — human or AI, in a fresh session — can build it from this document
alone. Companion to `joomla-opengraph-plugin-SPEC.md` (DinkyTags).

Working name: **`plg_content_dinkygallery`** (rename freely).
Purpose: replace **the legacy gallery plugin** (the legacy gallery plugin) on the empulsiv site — same
`{gallery}` shortcode, cleaner/opinionated presentation, zero third-party JS.

> Terminology: the request says "Lightroom" — read as **lightbox** (the modal
> large-image viewer). Used consistently below.

---

## 1. Scope

**v1 does exactly this:**

1. `{gallery …}` shortcode, processed in `com_content` article intro + full text.
2. A gallery renders **in-article as a horizontal carousel of image cards**.
3. Clicking a card opens a **lightbox** with the image shown large.
4. In the lightbox the image is **fitted by aspect ratio** (`contain`); the
   lightbox frame stays a **fixed size**.
5. Lightbox prev/next: the **left third** of the lightbox is a "previous" click
   zone, the **right third** is "next" (transparent overlay). Middle third: no-op.
6. Close: an **× button, top-right** of the lightbox.
7. Close: a **click outside the lightbox frame** (on the dimmed backdrop).
8. If the lightbox frame is smaller than the viewport it is **centered** both
   axes.
9. Lightbox frame has **no opaque background** — areas without image let the
   (dimmed) page show through; only the image is opaque. No black letterbox bars.
10. **Source media directory** for a gallery — plugin parameter (+ shortcode
    override).
11. **Number of visible cards** — parameter, **default 3**.
12. **Lightbox size** — parameter, **percent of the viewport**, **default 100 %**.

**Not in v1** (note as v1.1+): server-side thumbnail generation / `srcset`,
captions/EXIF, zoom/pan in the lightbox, slideshow/autoplay, video, per-image
links, download button, RTL layout polish, multilingual caption files.

---

## 2. Target platform

- Joomla **5.1+ and 6.x**. Namespaced plugin (`services/provider.php`,
  `Joomla\Event\SubscriberInterface`). No legacy `JPlugin`.
- PHP **8.2+**.
- **Zero runtime dependencies** — one hand-written CSS file, one ES-module JS
  file. No jQuery, Swiper, GLightbox, Fancybox, etc.
- Assets under `media/plg_content_dinkygallery/`.
- GPLv2-or-later header in every PHP file.
- Progressive enhancement: the carousel works without JS (scrollable strip of
  linked images); JS upgrades card clicks to the lightbox and adds the carousel
  arrows.

---

## 3. Shortcode

Two forms, both start with `{gallery`:

```
{gallery my-folder}
{gallery folder="my-folder" cards="4" size="80" loop="0"}
```

- Bare form: everything after `gallery ` up to `}` is the folder name (trimmed).
- Attribute form: space-separated `name="value"` / `name=value` pairs
  (shell-style tokens — `[A-Za-z_][\w:.\-]*` names, quoted or bare values).
- Recognised attributes (all optional, each overrides the matching param):
  `folder`, `cards`, `size`, `loop`, `sort` (`asc`|`desc`), `middle`
  (`none`|`close`).
- Unknown attributes: ignored.
- The tag may appear multiple times per article → independent galleries.
- Do **not** process a `{gallery}` that sits inside `<code>`/`<pre>` (best effort:
  skip matches whose preceding text has an unclosed `<code`/`<pre`). Note if
  skipped for v1 simplicity.

**Folder resolution & safety:**
`base_directory` (param) + `folder` (from shortcode). Reject the gallery (render
nothing, debug comment) if the resolved real path:
- contains `..` or a leading `/` after normalisation,
- does not resolve **inside** `JPATH_ROOT . '/' . base_directory`,
- does not exist or is not a directory.

Image files = entries whose extension (lowercased) is in `image_extensions`
(param, default `jpg,jpeg,png,webp,gif,avif`). Sort by filename, natural order,
`sort` asc/desc (default asc). Ignore dotfiles and non-images.

---

## 4. In-article carousel

Server-rendered markup (illustrative — keep classes stable, they are the CSS/JS
contract):

```html
<div class="dg" data-dg
     data-cards="3" data-size="100" data-loop="1" data-middle="none">
  <button type="button" class="dg-arrow dg-arrow--prev" aria-label="Vorheriges Bild" hidden></button>
  <ul class="dg-track" role="list">
    <li class="dg-card">
      <a class="dg-card__link" href="/images/…/01.jpg"
         data-full="/images/…/01.jpg" data-w="4032" data-h="3024">
        <img class="dg-card__img" src="/images/…/01.jpg" alt="01"
             loading="lazy" decoding="async" width="4032" height="3024">
      </a>
    </li>
    …
  </ul>
  <button type="button" class="dg-arrow dg-arrow--next" aria-label="Nächstes Bild" hidden></button>
</div>
```

- `data-w`/`data-h` from `@getimagesize` (cache per request) — used for the
  intrinsic `width`/`height` (no layout shift) and to pick the lightbox
  `object-fit` behaviour. If `getimagesize` fails, omit both and the `<img>`
  attrs.
- The carousel is a **CSS scroll-snap** strip: `.dg-track { display:flex;
  overflow-x:auto; scroll-snap-type:x mandatory; gap:… }`,
  `.dg-card { flex:0 0 calc((100% - (N-1)*gap) / N); scroll-snap-align:start }`
  where `N = data-cards`. No JS needed for scrolling (touch / trackpad / bar).
- Card image: fixed aspect box (`card_aspect` param, default `4/3`),
  `object-fit:cover`, `grayscale`→`color` on hover is a *nice-to-have*, not
  required.
- The `.dg-arrow` buttons are **revealed by JS** (`hidden` removed on init).
  Each scrolls the track by one card (`scrollBy({left: cardWidth+gap})`),
  disabled at the ends unless `loop` (loop just wraps `scrollLeft` to 0 / max).
- `< 700px` (param `card_min` breakpoint, default `13rem`): fall back to
  `data-cards` capped so a card is never narrower than `card_min` — simplest is
  `grid-auto-flow:column; grid-auto-columns: max(card_min, …)`. Keep it simple:
  one media query dropping to `cards = min(data-cards, 2)` then `1`.
- `prefers-reduced-motion: reduce` → `scroll-behavior:auto`, no transitions.

Without JS: cards are `<a href="{full}">` → clicking opens the image in the
browser. Acceptable baseline.

---

## 5. Lightbox

One lightbox element per page, appended to `<body>` by JS on first open, reused.

```html
<div class="dg-lb" role="dialog" aria-modal="true" aria-label="Bildansicht" hidden>
  <div class="dg-lb__backdrop" data-dg-close></div>
  <div class="dg-lb__frame" style="--dg-size: 100%">
    <img class="dg-lb__img" alt="">
    <button type="button" class="dg-lb__zone dg-lb__zone--prev" aria-label="Vorheriges Bild"></button>
    <button type="button" class="dg-lb__zone dg-lb__zone--next" aria-label="Nächstes Bild"></button>
    <button type="button" class="dg-lb__close" aria-label="Schließen" data-dg-close>×</button>
  </div>
</div>
```

### 5.1 Layout & sizing

- `.dg-lb` — `position:fixed; inset:0; z-index:<high>`; `display:flex;
  align-items:center; justify-content:center` → frame is **centered both axes**
  (requirement 8).
- `.dg-lb__backdrop` — `position:absolute; inset:0`; **dim** the page:
  `background: rgba(0,0,0, var(--dg-backdrop, .6))`. This is the *only* dimmed
  layer.
- `.dg-lb__frame` — the fixed-size box:
  `width: calc(var(--dg-size) * 100vw / 100)` … actually store `--dg-size` as a
  unitless percent and do `width: calc(var(--dg-size) * 1vw); height: calc(var(--dg-size) * 1vh)`
  (so `size=80` → 80vw × 80vh). `max-width:100vw; max-height:100vh`.
  **`background: transparent`** — requirement 9: no opaque bars, the dimmed page
  shows through the empty parts of the frame.
  `position:relative` (anchors the zones + close button).
- `.dg-lb__img` — `position:absolute; inset:0; margin:auto;
  max-width:100%; max-height:100%; width:auto; height:auto; object-fit:contain`
  → the image is **fitted by ratio inside the fixed frame** (requirement 4),
  centered, never overflows. It *will* upscale small images to the frame — that
  is the intended "eingepasst" behaviour; document it. `display:block`.
- The frame stays the same size regardless of image ratio (requirement 4).

> **Design check for the implementer:** requirement 9 as written = "frame
> transparent, backdrop dims the whole viewport, so the frame's empty area shows
> the *dimmed* page". If the client actually wants the frame area to show the
> page *undimmed* (a clear window), instead dim only *outside* the frame (e.g.
> a `box-shadow: 0 0 0 100vmax rgba(0,0,0,.6)` on the frame and no backdrop
> fill). Confirm which before building; default to the first (dimmed everywhere).

### 5.2 Interaction

- **Open:** click a `.dg-card__link` → `preventDefault`, read the gallery's image
  list (all `.dg-card__link` in that `.dg`, in DOM order) + the clicked index,
  set `--dg-size` from `data-size`, show `.dg-lb`, load the image, lock body
  scroll (`overflow:hidden` + compensate scrollbar), move focus to `.dg-lb__close`.
- **Prev / Next:** `.dg-lb__zone--prev` covers the **left third**
  (`left:0; width:33.333%; height:100%`), `--next` the **right third**
  (`right:0; width:33.333%`). Middle third is uncovered → clicks there do
  nothing (requirement 5) — unless `middle=close` (shortcode/param) then a
  transparent `.dg-lb__zone--mid` covering the middle closes.
  Zones are real `<button>`s (keyboard + SR reach them); they are visually
  transparent. Optional: a faint chevron fades in on hover of each zone
  (`::before`), purely cosmetic.
  Nav wraps if `loop` (default on): index `(i - 1 + n) % n` / `(i + 1) % n`;
  if `loop=0`, disable the zone at the respective end (`disabled` + `aria-disabled`).
  With `n === 1`: both zones `hidden`.
- **Close:**
  - `.dg-lb__close` (× top-right of the frame, `position:absolute; top; right`,
    z-index above the zones) — requirement 6.
  - click on `[data-dg-close]` where `event.target === event.currentTarget`
    i.e. the **backdrop** (requirement 7). A click that lands on the frame's
    transparent area is still inside a zone (left/right third) or the middle —
    it does **not** close; only the backdrop outside the frame closes.
  - `Esc` key (standard; add even though not requested).
  On close: hide `.dg-lb`, unlock body scroll, return focus to the originating
  `.dg-card__link`.
- **Keyboard:** `←`/`→` = prev/next, `Esc` = close, `Tab` focus-trapped within
  `.dg-lb` while open. `aria-modal="true"`.
- **Image swap:** update `.dg-lb__img` `src` + `alt`; while the new image loads,
  keep the old one visible (swap on `load`), show a lightweight spinner/opacity
  after ~150 ms. **Preload neighbours** (`new Image().src = next/prev full URL`)
  after each swap for instant nav.
- **Multiple galleries:** each `.dg` has its own image set; the lightbox is
  populated fresh on every open from the clicked gallery.

### 5.3 Motion / a11y

- Fade the backdrop + frame in over ~120 ms; none under
  `prefers-reduced-motion: reduce`.
- `role="dialog"`, `aria-modal`, `aria-label`. Focus in on open, trapped,
  restored on close. Zones and × are labelled buttons.
- The carousel arrows and lightbox zones must have visible `:focus-visible`
  outlines.

---

## 6. Parameters (`config.xml`)

| param | type | default | note |
|---|---|---|---|
| `base_directory` | `folder` (media) | `images` | gallery folder names resolve under this; used for the path-safety check |
| `visible_cards` | number | `3` | cards shown at once in the carousel |
| `card_aspect` | list | `4/3` | `4/3` · `3/2` · `1/1` · `16/9` — carousel card box, `object-fit:cover` |
| `card_min` | text | `13rem` | min card width before the carousel drops column count |
| `lightbox_size` | number | `100` | **percent of viewport** for the lightbox frame (w = Xvw, h = Xvh) |
| `lightbox_loop` | radio y/n | `yes` | wrap prev/next |
| `middle_zone_action` | list | `none` | `none` · `close` — what the lightbox's middle third does |
| `backdrop_opacity` | number | `60` | `--dg-backdrop`, percent |
| `image_extensions` | text | `jpg,jpeg,png,webp,gif,avif` | comma list, case-insensitive |
| `sort_order` | radio | `asc` | filename natural sort |
| `debug` | radio y/n | `no` | emit `<!-- DinkyGallery: … -->` (folder, file count, resolved path, per-tag decisions) |

All labels/descriptions via `PLG_CONTENT_DINKYGALLERY_*` keys; **en-GB + de-DE**
shipped.

---

## 7. Technical design

### 7.1 Events

- `type=content`, subscribe to **`onContentPrepare`**
  (`['onContentPrepare' => 'onContentPrepare']`).
- Guard: run only when `$context` is a `com_content` context
  (`com_content.article`, `com_content.category`, `com_content.featured`,
  `com_content.archive`) and `$article->text` (or introtext/fulltext) contains
  `{gallery`.
- Idempotency: after replacing a tag, the output contains no `{gallery` — safe on
  re-entry. Still, bail if `$context === 'com_finder.indexer'` (don't inject
  markup into the search index) — strip the tag to its folder name text instead,
  or just remove it.
- Non-HTML document (`$app->getDocument()->getType() !== 'html'`) or
  `tmpl=component` / `format` not html / `print=1`: replace each `{gallery}` with
  a plain `<ul class="dg-plain"><li><a href="…"><img …></a></li>…</ul>` (no
  carousel, no JS) and return.

### 7.2 Asset loading

Only when at least one gallery was rendered on the page:

```php
$wa = $app->getDocument()->getWebAssetManager();
$wa->registerAndUseStyle('plg_content_dinkygallery',
    'plg_content_dinkygallery/dinkygallery.css', [], ['version'=>'auto']);
$wa->registerAndUseScript('plg_content_dinkygallery',
    'plg_content_dinkygallery/dinkygallery.js', [], ['type'=>'module','version'=>'auto']);
```

`media/plg_content_dinkygallery/joomla.asset.json` may declare them instead —
either is fine; document the choice.

### 7.3 Skeleton

```
plg_content_dinkygallery/
├── dinkygallery.xml                     manifest, method="upgrade", <namespace path="src">
├── config.xml            (or params inside dinkygallery.xml)
├── services/provider.php
├── src/Extension/DinkyGallery.php        SubscriberInterface; onContentPrepare
├── src/Helper/Folder.php                 resolve+validate folder, list images (+getimagesize cache)
├── src/Helper/Shortcode.php              regex find + attribute parse
├── src/Helper/Render.php                 build carousel / plain markup
├── media/plg_content_dinkygallery/
│   ├── dinkygallery.css
│   └── dinkygallery.js                   ES module: carousel arrows + lightbox
├── language/en-GB/plg_content_dinkygallery.ini + .sys.ini
└── language/de-DE/…
```

`services/provider.php` — same pattern as DinkyTags: register `PluginInterface`,
`new DinkyGallery($dispatcher, (array) PluginHelper::getPlugin('content','dinkygallery'))`,
`->setApplication(Factory::getApplication())`.

### 7.4 Shortcode regex

```php
// bare or attribute form; non-greedy; tolerate newlines
'/\{gallery\b\s*(?<body>[^}]*)\}/i'
```
Then: if `body` has `=` → parse as attributes; else `folder = trim(body)`.
Attribute parse: reuse the pattern from a shell-style tokeniser
(`#(?<=\s|^)(?:([A-Za-z_][\w:.\-]*)=)?('…'|"…"|\-?\d+(?:\.\d+)?|[\w:.\/\-]+)(?=\s|$)#`).

### 7.5 URLs

`Uri::root()` for absolute base; store card `href` as **root-relative**
(`/images/…`) which works for subfolder installs when combined with `Uri::root(true)`.
Keep it simple: `Uri::root(true) . '/' . $relPath`.

### 7.6 getimagesize cache

`private array $sizeCache = []` keyed by absolute path; `@getimagesize`; store
`[w,h]` or `null`. Never fatal on unreadable files.

---

## 8. Edge cases

- Folder empty / missing / outside base → render nothing; `debug` comment says why.
- Exactly one image → carousel with one card; lightbox opens, both nav zones
  `hidden`.
- Non-image files in the folder → skipped.
- Broken/undersized image → `object-fit:contain` still centers it; small images
  upscale to the frame (intended).
- Portrait image in a wide frame (and vice-versa) → transparent margins, dimmed
  page shows through (requirement 9).
- `lightbox_size` 100 → frame == viewport → no backdrop area outside it → only ×
  (and Esc) close; left/right thirds still navigate; middle no-op.
- `lightbox_size` small (e.g. 40) → frame centered, wide dimmed margin = close on
  click.
- Multiple galleries, same folder, different `cards` → independent.
- `{gallery}` in a module / custom HTML: v1 only guarantees article contexts;
  note that `onContentPrepare` also fires for modules if *Prepare Content* is on —
  handle gracefully (same code path) or restrict to `com_content.*`.
- Body scroll lock must compensate for the scrollbar width to avoid layout jump.
- Two `onContentPrepare` passes (e.g. intro + full) → the full text replacement
  wins; ensure no double markup.
- RTL: mirror the prev/next zones (`[dir=rtl]` swaps left/right). Minimal in v1.

---

## 9. Test checklist

Fixtures: a folder with 1, 2, and 12 images; landscape + portrait + square + a
tiny 120×90 image + a huge 6000×4000; a `.txt` file in the folder; a missing
folder; a `../escape` folder name.

Verify:
- carousel shows `visible_cards` cards; scroll-snap works by touch, wheel, bar;
  arrows appear (JS) and scroll one card; disabled at ends unless `loop`.
- click card → lightbox opens with that image, centered, `contain`-fitted, frame
  size == `lightbox_size`% of viewport.
- left third → previous, right third → next, middle → nothing (`none`) / closes
  (`close`); wrap per `loop`.
- × closes; backdrop click outside frame closes; click on transparent frame area
  does **not** close; `Esc` closes; `←/→` navigate; focus trapped; focus returns
  to the card on close.
- empty frame area shows the dimmed page through it, not black bars.
- no-JS: card click opens the raw image; page still readable.
- `tmpl=component` / feed → plain `<ul>` list, no JS, no carousel.
- Smart Search reindex: `{gallery}` doesn't pollute the index.
- no PHP notices; valid HTML; `prefers-reduced-motion` respected; Lighthouse a11y
  ≥ 95 on an article page.
- subfolder install: URLs correct.

---

## 10. Packaging

- Installable ZIP `plg_content_dinkygallery-<x.y.z>.zip`, manifest
  `method="upgrade"`, `<media destination="plg_content_dinkygallery">`.
- Optional self-hosted `update.xml` + `<updateservers>`.
- `README.md` (install, every param, shortcode syntax, screenshots),
  `CHANGELOG.md`, GPLv2+ headers.
- Language complete for en-GB + de-DE.
- **v1.0.0 = §1 scope.** v1.1 candidates: GD/Imagick thumbnail cache under
  `media/plg_content_dinkygallery/cache/` (+ `srcset` on cards — the empulsiv
  container has **GD only**, no Imagick/rsvg); captions from a per-folder
  `captions.json` or prettified filenames; lightbox zoom/pan; slideshow;
  keyboard-accessible "open in new tab".

---

## 11. Acceptance: replace the legacy gallery plugin on empulsiv (w2026)

- **Same shortcode.** the legacy gallery plugin and DinkyGallery both own `{gallery …}` → they
  cannot run together. Migration = disable the legacy gallery plugin, enable
  `plg_content_dinkygallery`.
- **~209 published articles** use `{gallery <folder>}` today. the legacy gallery plugin's base was
  `images/stories` (`base_folder` param). Set DinkyGallery `base_directory` to
  **`images/stories`** so the existing `{gallery schallwende_grillfest}`-style
  tags resolve unchanged. Spot-check a range of those articles after switching.
- the legacy gallery plugin also emitted `og:image` (handled separately — it's set to
  `settings: open_graph=0`, see `DEPLOYMENT.md §H8`). DinkyGallery must **not**
  emit any social meta — DinkyTags owns that.
- the legacy gallery plugin extras NOT in v1 scope and to be checked per-article after migration:
  lightbox slideshow, captions from `labels.txt`, watermarks, per-gallery
  layout/rotator params, `{gallery}` inside modules. List which articles rely on
  them before flipping the switch.
- Rollout: install DinkyGallery (disabled) → set params (`base_directory =
  images/stories`, `visible_cards = 3`, `lightbox_size = 100`, `debug = 1` on
  dev) → disable the legacy gallery plugin, enable DinkyGallery → walk the §9 checklist + 10–15
  real articles → set `debug = 0` → keep the legacy gallery plugin installed-but-disabled until
  confident, then `extension:remove` it.
- Record in `DEPLOYMENT.md` (new section, e.g. §I) as a live delta.

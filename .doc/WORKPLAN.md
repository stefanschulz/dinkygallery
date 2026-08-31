# DinkyGallery — Arbeitsplan

Basis: [joomla-dinkygallery-plugin-SPEC.md](joomla-dinkygallery-plugin-SPEC.md).
Struktur-/Konventionsreferenz: `P:\dev\DinkyTags` (`plg_system_dinkytags`) — ein
**System**-Plugin; DinkyGallery wird ein **Content**-Plugin. Repo-Konventionen
(Manifest-Aufbau, `services/provider.php`-Muster, Helper-Schnitt, `build.xml`,
Sprachdatei-Disziplin, `.docker/`-Teststack, Doku im Stil von
`DinkyTags/.doc/ARCHITECTURE.md`) werden übernommen; das Code-Skelett nicht
(anderes Event, andere Aufgabe, Assets vorhanden).

## Festlegungen

| Aspekt | Wert | Begründung |
|---|---|---|
| Element / Paket | `plg_content_dinkygallery`, Gruppe `content`, Client `site` | Spec §2, Arbeitsname |
| Namespace | `TheLoom\Plugin\Content\DinkyGallery` (`<namespace path="src">`) | analog `TheLoom\Plugin\System\DinkyTags` |
| Sprach-Präfix | `PLG_CONTENT_DINKYGALLERY_*` | Joomla-Konvention Content-Plugin |
| Lizenz | **GPLv3-or-later** (Spec nennt GPLv2+; bewusst auf v3+ angehoben) | Repo enthält bereits `LICENSE` = GPLv3; deckungsgleich mit DinkyTags. GPLv3+-Header in jede PHP-Datei |
| Autor-Metadaten | The Loom / Stefan Schulz, `schulz@the-loom.de`, `https://www.the-loom.de` | analog DinkyTags |
| Ziel | Joomla 5.1+ / 6.x, PHP 8.2+, `method="upgrade"` | Spec §2 |
| Laufzeit-Abhängigkeiten | **keine** — 1 handgeschriebene CSS-Datei, 1 ES-Modul-JS-Datei, kein jQuery/Swiper/GLightbox | Spec §2 |
| Assets | `media/plg_content_dinkygallery/` mit `dinkygallery.css`, `dinkygallery.js`, **`joomla.asset.json`** (deklariert beide Assets; Extension ruft `useStyle`/`useScript` per Name) | Spec §7.2 lässt beide Wege zu; `joomla.asset.json` ist der sauberere |
| Params | **inline im `dinkygallery.xml`** (`<config><fields name="params">`), kein separates `config.xml` | analog DinkyTags |
| Update-Server | `https://www.the-loom.de/extensions/dinkygallery/update.xml` | Muster aus DinkyTags |
| Repo-Layout | Plugin-Dateien im Repo-**Root**; die vorhandene `.gitignore` ist eine Joomla-Root-Ignore-Liste → Entwicklung in echter Joomla-Installation, nur Plugin-Dateien getrackt | wie DinkyTags |
| Lightbox-Optik (Anf. 9) | **„nur die Lightbox abgedunkelt"** — die Dimmung sitzt auf dem Frame (`background: rgba(0,0,0,var(--dg-backdrop))`), der Backdrop ist nur transparenter Klickfänger. Ist der Frame kleiner als der Viewport, bleibt die Seite ringsum klar; bei `size=100` deckt der Frame alles → wie vorher. Klick außerhalb schließt (`cursor: zoom-out`). | Spec §5.1 Design-Check. Erst „ganzer Viewport" (2026-08-30), auf Nutzerwunsch umgekehrt (2026-08-30, nach Slice 4) |
| `{gallery}` in `<code>`/`<pre>` | Best-Effort-Skip wird **umgesetzt** (nicht auf v1.1 verschoben) | Spec §3 erlaubt Verschiebung, aber der Aufwand ist gering; entschieden 2026-08-30 |
| `base_directory`-Feldtyp | **`type="text"`** mit `hint="images"`, Default `images` | Joomla hat keinen `folder`-Feldtyp (nur `folderlist`, das rekursiv die *gesamte* Installation listet bzw. bei `directory="images"` die Pfad-Semantik bricht). Einmalige Einstellung, Validierung in `Helper/Folder`. Entschieden 2026-08-30 |

---

## Phase 0 — Repo-Gerüst

Verzeichnisbaum anlegen (Spec §7.3):

```
dinkygallery/
├── dinkygallery.xml                     Manifest (method="upgrade", <namespace path="src">, <media>, <config>)
├── services/provider.php                DI-Registrierung
├── src/Extension/DinkyGallery.php        SubscriberInterface, onContentPrepare
├── src/Helper/Shortcode.php             {gallery …} finden + Attribut-Parser (Shell-Stil)
├── src/Helper/Folder.php                Ordner auflösen+validieren, Bilder listen, getimagesize-Cache
├── src/Helper/Render.php                Carousel-Markup / Plain-<ul>-Markup bauen
├── media/plg_content_dinkygallery/
│   ├── dinkygallery.css
│   ├── dinkygallery.js                  ES-Modul: Carousel-Pfeile + Lightbox
│   └── joomla.asset.json
├── language/en-GB/plg_content_dinkygallery.ini + .sys.ini
├── language/de-DE/plg_content_dinkygallery.ini + .sys.ini
├── build.xml                            Phing-Target „package" (aus DinkyTags adaptiert)
├── README.md   CHANGELOG.md
├── .doc/joomla-dinkygallery-plugin-SPEC.md · WORKPLAN.md · ARCHITECTURE.md
└── .releases/                           Build-Output (generiert)
```

- `README.md` von Einzeiler auf vollständige Fassung erweitern (Phase 11).
- `CHANGELOG.md` mit `## [Unreleased]` anlegen (Stil DinkyTags).
- `LICENSE` (GPLv3) ist vorhanden — zusätzlich `LICENSE.txt` als versandte Kopie
  ablegen und in `.gitignore` mit `!/LICENSE.txt` freistellen (wie DinkyTags).

## Phase 1 — Manifest & Bootstrap

1. **`dinkygallery.xml`**: `<extension type="plugin" group="content" method="upgrade">`,
   Metadaten, `<namespace path="src">`, `<files>` (`<folder plugin="dinkygallery">services</folder>`,
   `src`, `language`, `dinkygallery.xml`), `<media destination="plg_content_dinkygallery"
   folder="media/plg_content_dinkygallery">` (css/js/json), `<config>` (Phase 2),
   `<updateservers>`.
2. **`services/provider.php`**: DI-Muster exakt wie DinkyTags — `PluginInterface` →
   `new DinkyGallery($container->get(DispatcherInterface::class), (array) PluginHelper::getPlugin('content','dinkygallery'))`,
   `->setApplication(Factory::getApplication())`.
3. **`src/Extension/DinkyGallery.php`**: `final class DinkyGallery extends CMSPlugin
   implements SubscriberInterface`, `$autoloadLanguage = true`,
   `getSubscribedEvents(): ['onContentPrepare' => 'onContentPrepare']`. Handler-Rumpf
   nimmt das `ContentPrepareEvent` (J5/6), holt `context`, `subject` (Artikel-Objekt),
   `params`, `page`; schreibt Ergebnis nach `$article->text`.

## Phase 2 — `config.xml`-Params + Sprachdateien (zusammen mit Phase 1, damit installierbar)

Params aus Spec §6 als `<fields name="params">` (plus `card_gap`, auf Wunsch
nachgezogen — Default `0`):

| param | Feldtyp | default |
|---|---|---|
| `base_directory` | `text` (`hint="images"`) | `images` |
| `visible_cards` | `number` (min 1) | `3` |
| `card_aspect` | `list` (`4/3`·`3/2`·`1/1`·`16/9`) | `4/3` |
| `card_min` | `text` | `13rem` |
| `card_gap` | `text` (CSS-Länge; Override `gap=`) | `0` |
| `lightbox_size` | `number` (1–100) | `100` |
| `lightbox_color` | `color` (hex) | `#000000` |
| `lightbox_padding` | `text` (CSS-Länge) | `10px` |
| `lightbox_loop` | `radio` switcher (0/1) | `1` (yes) |
| `middle_zone_action` | `list` (`none`·`close`) | `none` |
| `backdrop_opacity` | `number` (0–100) | `60` |
| `image_extensions` | `text` | `jpg,jpeg,png,webp,gif,avif` |
| `sort_order` | `radio` (`asc`·`desc`) | `asc` |
| `debug` | `radio` switcher (0/1) | `0` |

- Alle Labels/Beschreibungen als `PLG_CONTENT_DINKYGALLERY_PARAM_*`, **vollständig in
  en-GB und de-DE** (`.ini`), plus `.sys.ini` (Plugin-Name + `_XML_DESCRIPTION`).
  Jeder XML-referenzierte Key muss in beiden Sprachen existieren, sonst zeigt das
  Admin-Formular den rohen Key.
- Für „nie gespeichert vs. leer geräumt" bei Listen-Params (`image_extensions`) das
  Raw-Params-Array lesen statt `Registry::get()` (DinkyTags-`listParam()`-Muster),
  falls Leersetzen sinnvoll sein soll — hier eher unkritisch, Default genügt.

## Phase 3 — Shortcode-Erkennung & -Parser (`Helper/Shortcode.php`)

- **Finden** (Spec §7.4): `/\{gallery\b\s*(?<body>[^}]*)\}/i`, tolerant gegenüber
  Zeilenumbrüchen, mehrfach pro Artikel.
- **`<code>`/`<pre>`-Schutz**: für jeden Treffer prüfen, ob im vorangehenden Text ein
  unabgeschlossenes `<code`/`<pre` steht → dann Treffer überspringen (Best Effort).
- **Body-Auswertung**: enthält `body` ein `=` → Attribut-Form, sonst
  `folder = trim(body)`.
- **Attribut-Parser** (Shell-Stil-Tokens):
  `#(?<=\s|^)(?:([A-Za-z_][\w:.\-]*)=)?('…'|"…"|\-?\d+(?:\.\d+)?|[\w:.\/\-]+)(?=\s|$)#`.
  Erkannte Attribute (alle optional, überschreiben den jeweiligen Param):
  `folder`, `cards`, `size`, `loop`, `sort` (`asc|desc`), `middle` (`none|close`).
  Unbekannte Attribute ignorieren.
- Rückgabe: normalisiertes Options-Array pro Tag (`folder`, `cards`, `size`, `loop`,
  `sort`, `middle`) mit Param-Werten als Fallback.

## Phase 4 — Ordner & Bildliste (`Helper/Folder.php`)

- **Auflösung & Sicherheit** (Spec §3): Basis = `JPATH_ROOT . '/' . base_directory`,
  Ziel = Basis + `folder`. `realpath()`; ablehnen (nichts rendern + Debug-Grund),
  wenn der aufgelöste Pfad
  - `..` oder führenden `/` nach Normalisierung enthält,
  - **nicht innerhalb** von `realpath(Basis)` liegt (`str_starts_with`),
  - nicht existiert / kein Verzeichnis ist.
- **Bilder listen**: Einträge, deren kleingeschriebene Endung in `image_extensions`
  steht. Dotfiles und Nicht-Bilder ignorieren. Sortierung: Dateiname, natürliche
  Ordnung (`strnatcasecmp`), `sort` asc/desc.
- **Maße**: `private array $sizeCache` keyed by Absolutpfad; `@getimagesize`; `[w,h]`
  oder `null` speichern; nie fatal bei unlesbaren Dateien (Spec §7.6).
- Rückgabe: Liste von `['rel' => root-relativer Pfad, 'url' => Uri::root(true).'/'.rel,
  'alt' => Dateiname ohne Endung, 'w' => ?int, 'h' => ?int]`.

## Phase 5 — Markup-Erzeugung (`Helper/Render.php`)

- **Carousel** (Spec §4): exakte Klassen-/`data-`-Struktur aus §4 —
  `<div class="dg" data-dg data-cards data-size data-loop data-middle>`,
  `.dg-arrow--prev/--next` (mit `hidden`), `<ul class="dg-track">` mit
  `.dg-card > a.dg-card__link[data-full,data-w,data-h] > img.dg-card__img`.
  `data-w`/`data-h` + `width`/`height` nur wenn `getimagesize` lieferte; sonst
  komplett weglassen. `href` = root-relativ (`Uri::root(true) . '/' . rel`).
  Alle Ausgaben durch `htmlspecialchars`.
- **Plain-Fallback** (Spec §7.1): `<ul class="dg-plain"><li><a href><img></a></li>…</ul>`
  ohne Carousel/JS — für Nicht-HTML-Dokument, `tmpl=component`, `format != html`,
  `print=1`.
- **Leerer/ungültiger Ordner**: leerer String zurück (Tag verschwindet), Grund geht
  in den Debug-Log.

## Phase 6 — Orchestrierung (`src/Extension/DinkyGallery.php`)

`onContentPrepare(ContentPrepareEvent $event)` — Ablauf, früh raus:

1. **Kontext-Guard**: `context` in
   `com_content.article|category|featured|archive` (Spec §7.1). Andernfalls return
   (Module etc. laufen nur mit, wenn *Prepare Content* an ist — sauber ignorieren).
2. **Finder-Guard**: `context === 'com_finder.indexer'` → jeden `{gallery …}` durch
   den reinen Ordnernamen-Text ersetzen bzw. entfernen; kein Markup in den Index
   (Spec §7.1).
3. **Schnelltest**: `str_contains($article->text, '{gallery')` — sonst return.
4. **Dokumenttyp**: `$app->getDocument()->getType() !== 'html'` **oder**
   `tmpl=component` / `format != html` / `print=1` → Plain-Modus (Phase 5), return.
5. **Pro Tag** (via `Shortcode::find`): Optionen auflösen → `Folder::resolve` +
   `Folder::images` → `Render::carousel` → Treffer im Text ersetzen. Zähler
   `renderedCount++`.
6. **Assets**: nur wenn `renderedCount > 0` **und** HTML-Modus —
   `$wa = $doc->getWebAssetManager(); $wa->useStyle('plg_content_dinkygallery');
   $wa->useScript('plg_content_dinkygallery');` (in `joomla.asset.json` deklariert,
   Script als `type="module"`).
7. **Doppel-Pass** (Intro + Volltext): nach der Ersetzung enthält der Text kein
   `{gallery` mehr → bei Wiedereintritt idempotent; keine Doppel-Markup-Gefahr.
8. **Debug**: `debug=1` → je Tag/Seite einen `<!-- DinkyGallery: … -->`-Kommentar an
   `$article->text` anhängen (Ordner, aufgelöster Realpfad, Bildanzahl, Entscheidung
   je Tag: gerendert / abgelehnt + Grund). `--` und `>` im Kommentar neutralisieren
   (DinkyTags-`emitDebugComment()`-Muster).

Alles in `try/catch (\Throwable)` — ein Bild­katalog darf nie den Artikel-Render
sprengen; Fehler wird (bei `debug`) als Kommentar vermerkt, sonst geschluckt.

## Phase 7 — CSS (`media/plg_content_dinkygallery/dinkygallery.css`)

- **Carousel** (Spec §4): `.dg-track { display:flex; overflow-x:auto;
  scroll-snap-type:x mandatory; gap:… }`, `.dg-card { flex:0 0 calc((100% -
  (N-1)*gap)/N); scroll-snap-align:start }` mit `N` aus `--dg-cards` (per `style`
  aus `data-cards` gesetzt). Kartenbild: festes Aspect-Box (`--dg-aspect` aus
  `card_aspect`), `object-fit:cover`. Graustufen→Farbe bei Hover = *nice-to-have*.
- **Breakpoints**: eine Media-Query, die `cards` auf `min(data-cards, 2)` und dann
  `1` reduziert, damit eine Karte nie schmaler als `card_min` wird.
- **Pfeile**: `.dg-arrow` sichtbar erst nach JS-`init` (`hidden` entfernt);
  `:focus-visible`-Outline.
- **Lightbox** (Spec §5.1): `.dg-lb{position:fixed;inset:0;display:flex;
  align-items:center;justify-content:center;z-index:<hoch>}`;
  `.dg-lb__backdrop{position:absolute;inset:0;background:rgba(0,0,0,var(--dg-backdrop,.6))}`
  (einzige abdunkelnde Schicht); `.dg-lb__frame{width:calc(var(--dg-size)*1vw);
  height:calc(var(--dg-size)*1vh);max-width:100vw;max-height:100vh;
  background:transparent;position:relative}`; `.dg-lb__img{position:absolute;inset:0;
  margin:auto;max-width:100%;max-height:100%;width:auto;height:auto;
  object-fit:contain;display:block}` (Bild nach Ratio eingepasst, zentriert,
  skaliert kleine Bilder hoch — gewolltes „eingepasst"-Verhalten, dokumentieren).
- **Zonen**: `.dg-lb__zone--prev{left:0;width:33.333%;height:100%}`,
  `--next{right:0;width:33.333%}`, optional `--mid` (mittleres Drittel) nur bei
  `middle=close`. Echte `<button>`, visuell transparent, `:focus-visible`-Outline,
  optionaler `::before`-Chevron beim Hover.
- **× Schließen**: `.dg-lb__close{position:absolute;top;right;z-index über Zonen}`.
- **Motion/a11y**: Backdrop+Frame ~120 ms einblenden; unter
  `prefers-reduced-motion:reduce` keine Transitions, `scroll-behavior:auto`.
- **RTL**: `[dir=rtl]` spiegelt prev/next-Zonen (minimal in v1).

## Phase 8 — JS (`media/plg_content_dinkygallery/dinkygallery.js`, ES-Modul)

- **Carousel-Init** je `.dg[data-dg]`: `hidden` von `.dg-arrow` entfernen;
  Klick = `track.scrollBy({left: ±(cardWidth+gap)})`; an den Enden `disabled`, außer
  `data-loop="1"` (dann `scrollLeft` auf 0 bzw. max wrappen).
- **Lightbox**: ein Element pro Seite, beim ersten Öffnen an `<body>` gehängt,
  wiederverwendet. Struktur exakt aus Spec §5.
  - **Öffnen**: Klick auf `.dg-card__link` → `preventDefault`; Bildliste = alle
    `.dg-card__link` dieser `.dg` in DOM-Reihenfolge + geklickter Index; `--dg-size`
    aus `data-size`; `.dg-lb` zeigen; Bild laden; Body-Scroll sperren
    (`overflow:hidden` + Scrollbar-Breite kompensieren); Fokus auf `.dg-lb__close`.
  - **Prev/Next**: linkes/rechtes Drittel; Wrap bei `loop`, sonst Zone am jeweiligen
    Ende `disabled`+`aria-disabled`; bei `n === 1` beide Zonen `hidden`.
  - **Mittleres Drittel**: unbelegt (no-op) bei `middle="none"`; `.dg-lb__zone--mid`
    schließt bei `middle="close"`.
  - **Schließen**: `.dg-lb__close`; Klick auf `[data-dg-close]` mit
    `event.target === event.currentTarget` (nur Backdrop außerhalb des Frames);
    `Esc`. Danach `.dg-lb` verstecken, Scroll entsperren, Fokus zurück auf den
    auslösenden `.dg-card__link`.
  - **Tastatur**: `←/→` = prev/next, `Esc` = close, `Tab` in `.dg-lb` gefangen
    (Focus-Trap), `aria-modal="true"`.
  - **Bildwechsel**: `src`+`alt` tauschen, altes Bild bis `load` sichtbar lassen,
    nach ~150 ms leichter Spinner/Opacity; nach jedem Wechsel Nachbarn vorladen
    (`new Image().src = …`).
  - Mehrere Galerien: Lightbox bei jedem Öffnen frisch aus der geklickten `.dg`
    befüllt.
- **Motion**: Fade nur ohne `prefers-reduced-motion: reduce`.
- Kein globaler Namespace-Zwang; sauberes `export`/IIFE, ein `DOMContentLoaded`- bzw.
  Modul-Toplevel-Init.

## Phase 9 — Debug-Modus

`debug=1` → am Ende von `onContentPrepare` ein HTML-Kommentar je Seite mit: erkanntem
Kontext, Dokumentmodus (html/plain), pro Tag {Ordner-Eingabe, aufgelöster Realpfad,
in-Basis? ja/nein, Bildanzahl, gerendert/abgelehnt + Grund}, ob Assets geladen wurden.
`--`/`>` neutralisieren.

## Phase 10 — Build-Tooling (`build.xml`)

Aus DinkyTags adaptieren:
- `package`-Property `plg_content_dinkygallery`.
- Zip enthält `media/` (**nicht** ausschließen), `services/`, `src/`, `language/`,
  `dinkygallery.xml`, `LICENSE.txt`; ausgeschlossen: Dotfiles, `build.xml`,
  `README.md`, `CHANGELOG.md`, `LICENSE` (GPL-Volltext ohne `.txt`).
- `update.xml`: `<element>dinkygallery</element>`, `<type>plugin</type>`,
  `<folder>content</folder>`, `<client>site</client>`, sha256/384/512,
  `targetplatform name="joomla" version="(5.(1|2|3|4|5)|6.(0|1|2|3))"`,
  `<php_minimum>8.2</php_minimum>`. `targetplatform`-Regex ist die einzige Quelle der
  Wahrheit für den Update-Server; von Hand nachziehen.
- Ausgabe nach `.releases/`.

## Phase 11 — Dokumentation

- **`README.md`**: Installation, Shortcode-Syntax (beide Formen + alle Attribute),
  jeder Param erklärt, Screenshots, No-JS-Verhalten, FAQ.
- **`CHANGELOG.md`**: `1.0.0` Initial.
- **`.doc/ARCHITECTURE.md`** im Stil von `DinkyTags/.doc/ARCHITECTURE.md`: Dateibaum,
  Request-Flow (`onContentPrepare` → find/resolve/render → Asset-Registrierung),
  Begründung Event-Wahl, Markup-/CSS-/JS-Kontrakt (stabile Klassennamen!),
  Params-Tabelle mit „consumed in", Edge Cases (Spec §8), Docker-Testverfahren.

## Phase 12 — Test & Verifikation (Spec §9)

- **`.docker/`**-Wegwerf-/Dev-Stack analog DinkyTags: `docker-compose.yml`
  (`joomla:5-apache` + `mariadb:11.4`, `dev-proxy`-Netz, Traefik, erreichbar unter
  `http://dinkygallery.localhost/`), `setup.sh`/`reset.sh` (idempotent), Working-Tree
  read-only nach `/repo`, `src/`+`services/`+`language/`+`media/`+`dinkygallery.xml`
  in `plugins/content/dinkygallery/` **symlinken**, dann
  `extension:discover` + `extension:discover:install`. `.docker/` bleibt
  git-ignoriert.
- **Fixtures** (Spec §9): Bildordner mit 1, 2 und 12 Bildern; Landscape + Portrait +
  Square + winziges 120×90 + riesiges 6000×4000; eine `.txt` im Ordner; fehlender
  Ordner; Ordnername `../escape`. Je ein Artikel mit passendem `{gallery …}`-Tag
  (bare + Attribut-Form, Mehrfach-Tag, `size=40`, `size=100`, `middle=close`,
  `loop=0`).
- **Checkliste** exakt nach Spec §9 abarbeiten: Carousel zeigt `visible_cards`,
  Scroll-Snap per Touch/Wheel/Bar, Pfeile via JS + ein-Karten-Scroll, an den Enden
  disabled außer `loop`; Klick → Lightbox mit richtigem Bild, zentriert,
  `contain`-eingepasst, Frame = `lightbox_size`% des Viewports; linkes/rechtes
  Drittel = prev/next, Mitte = nichts / schließt; ×, Backdrop-Klick außerhalb, `Esc`
  schließen, Klick auf transparente Frame-Fläche **nicht**; `←/→`; Focus-Trap +
  Fokus-Rückgabe; leere Frame-Fläche zeigt abgedunkelte Seite (keine schwarzen
  Balken); No-JS → rohes Bild; `tmpl=component`/Feed → Plain-`<ul>`;
  Smart-Search-Reindex sauber; keine PHP-Notices; valides HTML;
  `prefers-reduced-motion`; Lighthouse-a11y ≥ 95; Subfolder-Install → URLs korrekt.
- Gegenprobe auf **Joomla 6.x** (disposable `joomla:6-apache`).

## Reihenfolge der Umsetzung

Phase 0 → 1 + 2 (Manifest/Params/Sprache zusammen → installierbar) → 3 → 4 → 5 → 6
(Verdrahtung, ab hier serverseitig sichtbar) → 7 → 8 (ab hier Carousel+Lightbox
funktionsfähig) → 9 → 10 → 11 → 12.

---

## Entschieden am 2026-08-30

- **Lizenz:** GPLv3-or-later (Header + `LICENSE`/`LICENSE.txt`).
- **`base_directory`-Feldtyp:** `type="text"` (Joomla hat keinen `folder`-Typ; `folderlist`
  passt semantisch nicht). Default `images`, Empulsiv `images/stories`.
- **Lightbox-Optik:** nur der Frame ist abgedunkelt (Dimm-Hintergrund auf dem Frame,
  Backdrop transparenter Klickfänger); Seite ringsum klar, Klick außerhalb schließt.
  (Erst „ganzer Viewport", auf Nutzerwunsch nach Slice 4 umgekehrt.)
- **`<code>`/`<pre>`-Skip:** wird umgesetzt (Best Effort).
- **Autor-Metadaten:** The Loom / Stefan Schulz, `schulz@the-loom.de`, `https://www.the-loom.de`.
- **Test-Umgebung:** eigener `.docker/`-Stack analog DinkyTags.

## Während Slice 2 gelernt

- **Asset-Ablage:** Joomlas relativer Asset-Resolver (`HTMLHelper::includeRelativeFiles`)
  findet `uri: "plg_content_dinkygallery/x.css"` **nur** unter
  `media/plg_content_dinkygallery/css/x.css` (bzw. `js/` für Skripte) — flache
  Ablage wird still verworfen. Dateien liegen jetzt in `css/` + `js/`.
- **`joomla.asset.json`** von Erweiterungen wird **nicht** automatisch geladen:
  `->getRegistry()->addExtensionRegistryFile('plg_content_dinkygallery')` vor
  `useStyle()`/`useScript()` nötig.
- **Feeds:** `com_content` baut RSS-Items direkt aus introtext/fulltext, **ohne**
  `onContentPrepare` — `{gallery}` bleibt im Feed roh. Kein Hook dafür in v1;
  als bekannte Einschränkung dokumentiert. Betrifft die empulsiv-Artikelseiten nicht.

## Während Slice 6 (Abnahme) gefunden & behoben

- **Carousel nicht per Touch/Wheel/Scrollbar scrollbar:** Cassiopeia (J5 **und** J6)
  hat `.com-content-article ul{overflow:hidden}` — Spezifität (0,1,1) schlägt
  `.dg-track`. Nur die JS-Pfeile (programmatisch) bewegten den Strip. Fix: Selektoren
  unter `.dg` scopen bzw. `.dg-plain` verdoppeln.
- **Hintergrund nicht `inert`** bei offener Lightbox → AT/Tab erreichten die Seite
  dahinter. Fix: alle `<body>`-Kinder außer `.dg-lb` bekommen `inert`.
- **Carousel-Schritt driftete** auf einer Seite mit vielen Galerien (ein Klick sprang
  mehrere Karten). Fix: `go()` synchronisiert `target` im Leerlauf an die echte
  Scrollposition.
- **`build.xml`** legte `.releases/` nicht an → `phing package` scheiterte bei
  frischem Checkout. Fix: `<mkdir>`.
- **ZIP-Install** verifiziert auf J5 (frisch) und J6 (Wegwerf-Stack, primäre Abnahme).

## Offene Punkte

1. **Empulsiv-Migration (Spec §11)** ist **nicht Teil dieses Repos** — nur Abnahme:
   das bisherige Galerie-Plugin deaktivieren, DinkyGallery aktivieren,
   `base_directory = images/stories`, §9-Checkliste + 10–15 Realartikel, `debug` aus,
   das Alt-Plugin installiert-aber-deaktiviert lassen. Ablauf in `DEPLOYMENT.md` (neuer
   Abschnitt) dokumentieren. Alt-Plugin-Extras (Slideshow, `labels.txt`-Captions,
   Wasserzeichen, Rotator-Params, `{gallery}` in Modulen) vorab pro Artikel auflisten.

## v1.1+ (nicht jetzt, Spec §1/§10)

Server-seitige Thumbnails / `srcset` (Cache unter `media/plg_content_dinkygallery/cache/`,
Empulsiv-Container hat **nur GD**); Captions/EXIF (`captions.json` oder aufgehübschte
Dateinamen); Zoom/Pan in der Lightbox; Slideshow/Autoplay; Video; per-Bild-Links;
Download-Button; RTL-Feinschliff; mehrsprachige Caption-Dateien;
tastaturzugängliches „in neuem Tab öffnen".

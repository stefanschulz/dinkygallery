# DinkyGallery — Testplan

Angleichung der Testabdeckung an das Schwesterprojekt **DinkyMetrics**
(`P:\dev\dinkymetrics`). Vorlage sind dessen `composer.json`, `phpunit.xml.dist`,
`phpcs.xml.dist`, `tests/` und `.docker/*.sh`.

**Status**: in Umsetzung — begonnen 2026-09-04
**Bezug**: [WORKPLAN.md](WORKPLAN.md) · [ARCHITECTURE.md](ARCHITECTURE.md)

---

## Ausgangslage

DinkyGallery hat bisher **keine Testinfrastruktur** (kein `composer.json`, kein
`tests/`, keine `*.xml.dist`), nur `build.xml` und den `.docker/`-Stack.

DinkyMetrics fährt ein dreistufiges Modell:

| Stufe | Inhalt | Ausführung |
|---|---|---|
| `tests/Unit/` | reine Logik | PHPUnit, Mini-`bootstrap.php` (nur `_JEXEC` + Autoload), Data-Provider |
| `tests/Integration/` | was echt sein muss (dort: Zähl-SQL) | einfaches PHP-Skript **im Docker-Stack**, echte Umgebung, **keine Mocks** |
| `tests/parity/` | JS ↔ PHP (dort: Zahlenformat) | geteilte Fixture, beide Seiten prüfen dagegen |
| Infra | `composer.json` nur `require-dev`; `phpcs` PSR-12 (LineLength 160 = Warnung); `.docker/*.sh` | **kein CI** in v1 |

**Übertragbarkeit:** `Shortcode`, `Render` und `Thumbnailer` haben **keine
Joomla-Abhängigkeit** (nur der `_JEXEC`-Wächter) und sind vollständig unit-testbar.
Nur `Folder` braucht die Laufzeit (`Uri::root()`).

---

## Phasen

### Phase 1 — Infrastruktur

- [ ] `composer.json` — `require-dev`: `phpunit/phpunit ^11.5 || ^12.0`,
  `squizlabs/php_codesniffer ^3.10`,
  `dealerdirect/phpcodesniffer-composer-installer ^1.0`.
  `autoload-dev` PSR-4: `TheLoom\Plugin\Content\DinkyGallery\` → `src/`,
  `TheLoom\Plugin\Content\DinkyGallery\Tests\` → `tests/Unit/`.
  `scripts`: `lint` = `phpcs`, `test` = `phpunit`.
- [ ] `phpunit.xml.dist` — Bootstrap `tests/bootstrap.php`,
  `cacheDirectory=".phpunit.cache"`, `failOnWarning`/`failOnRisky` = true,
  Suite `Unit` → `tests/Unit`.
- [ ] `phpcs.xml.dist` — von DinkyMetrics übernommen, `name="DinkyGallery"`,
  Excludes `vendor` / `.docker` / `.releases` / `.idea`.
- [ ] `tests/bootstrap.php` — `\defined('_JEXEC') or \define('_JEXEC', 1);` +
  `require vendor/autoload.php` (mit `phpcs:disable PSR1.Files.SideEffects`).
- [ ] `.gitignore` — `/vendor`, `/composer.lock`, `/.phpunit.cache` ergänzen
  (fehlen hier, anders als bei DinkyMetrics).
- [ ] `build.xml` — Excludes für `composer.json`, `composer.lock`, `vendor/**`,
  `tests/**`, `phpunit.xml.dist`, `phpcs.xml.dist`; danach ZIP-Inhalt prüfen
  (null Treffer auf `composer|phpunit|phpcs|tests/|vendor`).
- [ ] `.docker/test.sh` — PHPUnit **im J5-Container** (`dinkygallery-test-joomla-1`),
  Cache nach `/tmp`. Grund: die `Thumbnailer`-Tests brauchen **GD/WebP**, die das
  Host-PHP oft nicht hat (bei DinkyMetrics ist es `intl`).
- [ ] `.docker/gallery.sh` — `tests/Integration/gallery.php` im Container.

**Ende der Phase:** `composer install`, `composer run lint` (grün oder nur
Warnungen), `composer run test` (leer/grün), `phing package` unverändert, ZIP sauber.

### Phase 2 — Refactor für Testbarkeit

- [ ] Die reinen Sanitizer aus `DinkyGallery` nach `src/Helper/Sanitize.php`
  auslagern, als `public static` (analog `Formatter::normaliseLocale`):
  `cssAspect`, `lightboxAspect`, `cssLength`, `hexToRgb`, `extensionList`,
  `thumbDirName`, `widthList`. `DinkyGallery::config()` ruft `Sanitize::*` auf.
  Begründung: Sicherheitslast (Style-Attribut-Breakout, Pfad-Traversal) und
  genau die „reine Logik", die DinkyMetrics unit-testet.
- [ ] Rauchtest im Stack, dass Rendern unverändert funktioniert.

### Phase 3 — `tests/Unit/`

| Datei | Deckt | Kernfälle |
|---|---|---|
| `ShortcodeTest` | `Shortcode::find()` + `parseBody()` | bare / attribute / sigplus-Closing-Form; führendes bare-Token = Ordner; unbekannte Attrs ignoriert, `deftitle` behalten; mehrere Tags → Offsets + Reihenfolge; `<code>`/`<pre>` → `skip`; kein Treffer → `[]` |
| `RenderTest` | `Render::carousel()` + `plain()` | `data-*` + `--dg-*` aus Optionen; `dg-count` nur bei `total>1`; `srcset`/`sizes` nur bei nicht-leerem `srcset`; `href`/`data-full` = `full`; **kein `data-w`/`data-h`** (Regressionswächter); HTML-Escaping `alt`/`url` (XSS); `cards`≥1, `size` 10–100 |
| `SanitizeTest` | die 7 ausgelagerten Funktionen | `cssLength('0')→'0px'`, `'red;}'`→Fallback, `'10'`→Fallback; `hexToRgb('#abc')`, `'#gg..'`→`0, 0, 0`; `thumbDirName('../x')`/`'a/b'`→`.thumbs`; `widthList('1600,480,480')→[480,1600]`, `'12000'`/`'0'` verworfen, `''`→Default; `cssAspect`/`lightboxAspect`-Normalisierung inkl. `W:H`→`W/H` |
| `ThumbnailerTest` (`@requires extension gd`) | `Thumbnailer::ensure()` + `prune()`, Temp-Verzeichnis + GD-erzeugte Testbilder | Breiten ≥ Quellbreite übersprungen; `large-` nur über Cap, sonst nicht; Dateiname-Hash aus mtime+size+quality stabil; **mtime-Bump → neuer Hash + alte Derivate gepruned**; Fremddatei (`notes.txt`) in `.thumbs` bleibt; leerer `.thumbs` per `rmdir` entfernt; WebP-Skip wenn `gd_info()['WebP Support']` fehlt (bedingte Assertion) |
| `FolderResolveTest` | `Folder::resolve()` mit `\define('JPATH_ROOT', $tmp)` + Temp-Baum | akzeptiert Unterordner **und** Einzelbild; weist `..`, Backslash, außerhalb-Basis, nicht-Bild-nicht-Ordner ab; führender Slash gestrippt (`/x` ≡ `x`) |

### Phase 4 — `tests/Integration/gallery.php` (+ `.docker/gallery.sh`)

Analog zu DinkyMetrics' `counts.php`: einfaches Skript, **ohne Mocks**, im Stack,
eigener `spl_autoload_register` für den `src/`-Prefix.

- [ ] Teil A — `Folder::images()`/`single()` end-to-end gegen einen Fixture-Bildbaum:
  Anzahl, Natursortierung asc/desc, `notes.txt` + Dotfiles übersprungen,
  URL-Encoding, `.thumbs` erzeugt und (nur `images()`) gepruned.
- [ ] Teil B — HTTP-Render-Check: `curl` des Fixture-Artikels aus
  `.docker/fixtures.sql` im Container, Assertions auf das HTML — N × `.dg`,
  `srcset` korrekt, `thumbs: N made`-Zeile, Debug-Kommentar vorhanden, keine
  PHP-Notice. Formalisiert den bisher manuellen Rauchtest zu pass/fail.
- [ ] `ok/FAIL`-Ausgabe, Exit ≠ 0 bei Fehler (wie `counts.php`).

### Phase 5 — optional: Markup-Vertrag

- [ ] `tests/parity/contract.mjs` (oder PHP-Test): durchsucht `Render.php`,
  `dinkygallery.css`, `dinkygallery.js` nach dem vereinbarten Token-Satz
  (Klassennamen + `data-*`) und meldet, wenn eine Datei aus der Reihe tanzt.
  Pendant zu DinkyMetrics' Zahlenformat-Parität; nice-to-have.

### Phase 6 — Abschluss

- [ ] `phpcs` gegen `src/` grün (erwartet gering — Code ist im Joomla-Stil).
- [ ] README „Build & Test"-Block wie DinkyMetrics; `ARCHITECTURE.md` →
  `## Testing`; Entscheidungszeile in `WORKPLAN.md`; `CHANGELOG`-Eintrag.
- [ ] Schrittweise Commits. **Push durch den Maintainer.**

---

## Bewusst nicht

- **Kein CI** in v1 — wie DinkyMetrics: `phpcs` / `phpunit` / `phing` lokal.
- **Keine DB- oder Filesystem-Mocks** — was echt sein muss, läuft im `.docker`-Stack.
- **Kein Test des Browser-Verhaltens** (Karussell-Scroll, Lightbox-Fokusfalle) —
  bleibt QA-Checkliste + manuelle Sichtung wie bisher.

## Risiken

- `phpcs` kann Kleinkram in bestehenden Dateien finden (LineLength ist nur
  Warnung) — in Phase 6 eingeplant.
- Host-PHP ohne GD/WebP: `composer test` lokal eingeschränkt, `.docker/test.sh`
  ist „der Lauf, der zählt".
- Sanitizer-Auslagerung berührt `DinkyGallery.php` — klein, aber ein echter
  Eingriff; nach Phase 2 Rauchtest.

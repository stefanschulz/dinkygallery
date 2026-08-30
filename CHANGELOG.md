# Changelog

All notable changes to `plg_content_dinkygallery` are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Repository skeleton (Slice 1 — installable but inert):
  - Extension manifest `dinkygallery.xml` (`type=plugin`, `group=content`,
    `method=upgrade`, `<namespace path="src">`, `<media>` for the CSS/JS/asset
    manifest, the full 11-parameter form in `basic` + `advanced` fieldsets, an
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

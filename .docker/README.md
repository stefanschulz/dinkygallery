# Local test / dev stack

Disposable Joomla instance for `plg_content_dinkygallery`, routed by the machine's
shared Traefik proxy. Tracked in git (throwaway local credentials only), like the
sister projects; the named volumes it writes to are not.

- **Site:** http://dinkygallery.localhost/
- **Admin:** http://dinkygallery.localhost/administrator/ — `admin` / `admin1234secure`
- **DB:** db `joomla`, user `joomla` / `joomlapw`, root `rootpw`, prefix `jos_`
  (no host port — `docker compose exec db mariadb -ujoomla -pjoomlapw joomla`)

Throwaway credentials for a local container only.

## Requirements

- Docker Desktop with drive `P:` shared.
- The shared proxy running (creates/serves the external `dev-proxy` network;
  `docker network create dev-proxy` once).
- No host ports are published by this stack — Traefik handles routing.

## Usage

```bash
cd .docker

./setup.sh      # up + install Joomla + register plugin + gen images + seed fixtures (idempotent)
./reset.sh      # down -v, then setup.sh from scratch
./test.sh       # the unit tests, on a PHP that has GD + WebP
./gallery.sh    # tests/Integration/gallery.php — folder listing, thumbnail cache, full render

docker compose -f docker-compose.yml stop     # pause (data kept in named volumes)
docker compose -f docker-compose.yml start    # resume
docker compose -f docker-compose.yml down     # remove containers, keep volumes
docker compose -f docker-compose.yml down -v  # remove everything
```

`db-data` and `html-data` are named volumes, so a plain `stop` / `start` / `down`
keeps the install and fixtures; only `down -v` (or `reset.sh`) wipes them.

## How the plugin gets in

The working tree is mounted read-only at `/repo`; `setup.sh` symlinks
`dinkygallery.xml`, `src/`, `services/`, `language/` into
`plugins/content/dinkygallery/` and `media/plg_content_dinkygallery/` into
`media/`. Joomla registers it via `extension:discover` — no file copy.

- **PHP edits** (`src/**`, `services/**`) are live on the next request (opcache
  revalidates within ~2s).
- **CSS / JS edits** (`media/**`) are served straight from the symlink — reload,
  bypass the browser cache (`version=auto` in the asset registration bumps the
  query string on file mtime change).
- **`dinkygallery.xml` edits**: the params form re-reads the manifest on each
  load, so new fields show up immediately. Refresh the stored manifest cache
  (version/author) with:
  ```bash
  docker compose -f docker-compose.yml exec joomla \
    php /var/www/html/cli/joomla.php extension:discover
  ```
- **Language `.ini` edits**: clear cache —
  `docker compose -f docker-compose.yml exec joomla php /var/www/html/cli/joomla.php cache:clean --all`.

## Fixtures

`setup.sh` generates the spec section 9 image matrix under `images/`:

| folder | contents |
|---|---|
| `gallery-01` | 1 landscape image |
| `gallery-02` | 1 landscape + 1 portrait |
| `gallery-12` | landscape / portrait / square / 120×90 tiny / 6000×4000 huge / assorted + a `notes.txt` (must be skipped) |

`fixtures.sql` seeds five articles (ids 101–105) and enables the plugin with
`debug=1`, `base_directory=images`. Re-runnable (`INSERT IGNORE` / idempotent `UPDATE`).

| id | shortcode(s) | checks |
|---|---|---|
| 101 | `{gallery gallery-12}` | bare form, 12 cards, default params |
| 102 | `{gallery folder="gallery-02" cards="2" size="80"}` | attribute form |
| 103 | `{gallery gallery-01}` + `{gallery folder="gallery-12" size="40" middle="close" loop="0"}` | two independent galleries |
| 104 | `{gallery missing-folder}` + `{gallery ../escape}` | both render nothing + debug reason |
| 105 | `{gallery gallery-12}` inside `<pre>` + `{gallery gallery-02}` | code-block skip |

Quick check:

```bash
curl -s -H 'Host: dinkygallery.localhost' \
  'http://127.0.0.1/index.php?option=com_content&view=article&id=101' \
  | grep -E 'dg-track|dg-card|DinkyGallery:'
```

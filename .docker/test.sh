#!/usr/bin/env bash
# Run the unit tests inside the Joomla container.
#
# Why not just `composer run test` on the host: the Thumbnailer tests need GD, and a
# Windows PHP CLI often has no GD at all — or GD without WebP — so those tests would
# only ever exercise the fallback path. The joomla:5-apache image ships GD with JPEG /
# PNG / WebP, which is also far closer to what the plugin will run on.
#
# The working tree is mounted read-only, so PHPUnit's cache goes to /tmp.
set -euo pipefail
cd "$(dirname "$0")"

# Git Bash on Windows rewrites arguments that look like Unix paths, turning /repo into
# C:/Program Files/Git/repo before Docker ever sees it. Harmless everywhere else.
export MSYS_NO_PATHCONV=1

docker compose -f docker-compose.yml exec -T joomla \
  php /repo/vendor/bin/phpunit \
  --configuration /repo/phpunit.xml.dist \
  --cache-directory /tmp/phpunit \
  "$@"

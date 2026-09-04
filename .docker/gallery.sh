#!/usr/bin/env bash
# Check the folder listing, thumbnail cache and full render against the fixtures,
# inside the test stack. See tests/Integration/gallery.php for what is asserted and why.
set -euo pipefail
cd "$(dirname "$0")"

# Git Bash on Windows would rewrite /repo into a Windows path before Docker sees it.
export MSYS_NO_PATHCONV=1

docker compose -f docker-compose.yml exec -T joomla php /repo/tests/Integration/gallery.php "$@"

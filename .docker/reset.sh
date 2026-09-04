#!/usr/bin/env bash
# Wipe the stack (db + Joomla install) and re-provision from scratch.
set -euo pipefail
cd "$(dirname "$0")"
docker compose -f docker-compose.yml down -v
exec ./setup.sh

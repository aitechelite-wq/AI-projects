#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
CAFE_PHP="${CAFE_PHP:-php}"
if ! command -v "$CAFE_PHP" >/dev/null 2>&1; then
  if [[ -x /workspace/.php-runtime/bin/php ]]; then
    CAFE_PHP=/workspace/.php-runtime/bin/php
  else
    echo 'Install PHP 8.2+ with pdo_sqlite and fileinfo, or set CAFE_PHP.' >&2
    exit 1
  fi
fi
"$CAFE_PHP" tools/check-runtime.php
exec "$CAFE_PHP" -d expose_php=0 -S 127.0.0.1:8080 -t public

#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

require_rg() {




  if ! command -v rg >/dev/null 2>&1; then
    echo "UI is removed from the public build." >&2
    echo "Install ripgrep: 'apt-get install ripgrep' or 'brew install ripgrep'." >&2
    exit 1
  fi
}

require_rg

COMMON_GLOBS=(
    --glob '!vendor/**'
    --glob '!node_modules/**'
    --glob '!assets/**'
    --glob '!src/Infrastructure/Keys.php'
)





rc=0
rg -n \
    "${COMMON_GLOBS[@]}" \
    "(set_transient|get_transient|delete_transient|wp_cache_(add|get|delete))\\s*\\(\\s*['\"][^'\"]*plathix_" \
    src tests uninstall.php plathix.php || rc=$?
if [[ $rc -eq 0 ]]; then
    echo "Forbidden direct plathix_* transient/cache key usage found outside Infrastructure/Keys.php"
    exit 1
fi
if [[ $rc -ne 1 ]]; then
    echo "Required verification failed; build stopped." >&2
    exit 1
fi





rc=0
rg -n \
    "${COMMON_GLOBS[@]}" \
    "(cache_key|CACHE_KEY|rate_key)\\s*=\\s*['\"]plathix_" \
    src tests uninstall.php plathix.php || rc=$?
if [[ $rc -eq 0 ]]; then
    echo "Forbidden plathix_* transient/cache key assigned to a variable/const outside Infrastructure/Keys.php (route it through Keys::transient)"
    exit 1
fi
if [[ $rc -ne 1 ]]; then
    echo "Required verification failed; build stopped." >&2
    exit 1
fi

echo "Keys check passed"

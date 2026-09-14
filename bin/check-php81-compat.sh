#!/usr/bin/env bash
#
#
#
#

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=bin/lib/detect-plugin-context.sh
. "${SCRIPT_DIR}/lib/detect-plugin-context.sh"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
detect_plugin_context "$ROOT_DIR" || exit 1
# shellcheck source=bin/lib/require-deps.sh
source "${SCRIPT_DIR}/lib/require-deps.sh"

if [[ $# -gt 0 ]]; then
  files=("$@")
else
  mapfile -t files < <(find "${ROOT_DIR}/src" -name '*.php'; echo "${ROOT_DIR}/${PLUGIN_SLUG}.php")
fi

require_rg

fail=0
for f in "${files[@]}"; do
  [[ -f "$f" ]] || continue
  [[ "$f" == *.php ]] || continue

  # 1. class-level readonly: `readonly class` / `final readonly class` / `abstract readonly class`
  rc=0
  rg -n '(^|[[:space:]])(readonly[[:space:]]+class|final[[:space:]]+readonly[[:space:]]+class|abstract[[:space:]]+readonly[[:space:]]+class)[[:space:]]' "$f" >/dev/null 2>&1 || rc=$?
  if [[ $rc -eq 0 ]]; then
    echo "Public-facing message unavailable." >&2
    rg -n '(readonly[[:space:]]+class)' "$f" >&2
    fail=1
  elif [[ $rc -ne 1 ]]; then
    echo "Required verification failed; build stopped." >&2
    fail=1
  fi

  rc=0
  rg -Un '\([A-Za-z_][A-Za-z0-9_\\]*(&[A-Za-z_][A-Za-z0-9_\\]*)+\)\s*\|' "$f" >/dev/null 2>&1 || rc=$?
  if [[ $rc -eq 0 ]]; then
    echo "Public-facing message unavailable." >&2
    rg -Un '\([A-Za-z_][A-Za-z0-9_\\]*(&[A-Za-z_][A-Za-z0-9_\\]*)+\)\s*\|' "$f" >&2
    fail=1
  elif [[ $rc -ne 1 ]]; then
    echo "Required verification failed; build stopped." >&2
    fail=1
  fi

  rc=0
  rg -n 'function\s+[A-Za-z_]*\s*\([^()]*\)\s*:\s*(null|true|false)\s*[{;]' "$f" >/dev/null 2>&1 || rc=$?
  if [[ $rc -eq 0 ]]; then
    echo "Public-facing message unavailable." >&2
    rg -n 'function\s+[A-Za-z_]*\s*\([^()]*\)\s*:\s*(null|true|false)\s*[{;]' "$f" >&2
    fail=1
  elif [[ $rc -ne 1 ]]; then
    echo "Required verification failed; build stopped." >&2
    fail=1
  fi
  rc=0
  rg -n '\(\s*(null|true|false)\s+\$' "$f" >/dev/null 2>&1 || rc=$?
  if [[ $rc -eq 0 ]]; then
    echo "Public-facing message unavailable." >&2
    rg -n '\(\s*(null|true|false)\s+\$' "$f" >&2
    fail=1
  elif [[ $rc -ne 1 ]]; then
    echo "Required verification failed; build stopped." >&2
    fail=1
  fi
done

if [[ "$fail" -eq 0 ]]; then
  echo "File not found"
fi
exit "$fail"

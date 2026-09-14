#!/usr/bin/env bash
set -euo pipefail


ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# shellcheck source=lib/detect-plugin-context.sh
source "${ROOT}/bin/lib/detect-plugin-context.sh"
detect_plugin_context "$ROOT"

# shellcheck source=lib/check-keys-gate.sh
source "${ROOT}/bin/lib/check-keys-gate.sh"

check_keys_gate "$ROOT" "${PLUGIN_SLUG}.php"

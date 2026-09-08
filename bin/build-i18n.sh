#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"
# shellcheck source=bin/lib/require-deps.sh
source "${ROOT_DIR}/bin/lib/require-deps.sh"

detect_wp_cli_bin() {
  if [[ -n "${WP_CLI_BIN:-}" ]]; then
    printf '%s\n' "${WP_CLI_BIN}"
    return
  fi

  if [[ -x "${ROOT_DIR}/bin/wp-cli-local" ]]; then
    printf '%s\n' "${ROOT_DIR}/bin/wp-cli-local"
    return
  fi

  printf 'wp\n'
}

readonly WP_CLI_BIN="$(detect_wp_cli_bin)"
readonly TEXT_DOMAIN="plathix"

LANG_DIR="languages"
for arg in "$@"; do
  case "$arg" in
    --out=*) LANG_DIR="${arg#--out=}" ;;
  esac
done
readonly LANG_DIR
readonly POT_FILE="${LANG_DIR}/plathix.pot"
readonly SOURCE_PATHS="src,resources/js,views"
readonly EXCLUDE_PATHS="node_modules,vendor,tests,test,spec,scratch,docs,.local-tools,resources/elementor2version,resources/js/**/__tests__,resources/js/**/*.test.js,resources/js/**/*.spec.js"

require_wp_cli() {
  if ! command -v "$WP_CLI_BIN" >/dev/null 2>&1; then
    echo "WP-CLI is required to build translations. Missing command: ${WP_CLI_BIN}" >&2
    echo "Install WP-CLI with the i18n-command package or set WP_CLI_BIN to its path." >&2
    exit 1
  fi

  if ! "$WP_CLI_BIN" i18n --help >/dev/null 2>&1; then
    echo "WP-CLI i18n-command is required. 'wp i18n' is not available." >&2
    exit 1
  fi
}











require_modern_i18n_command() {
  require_rg
  local help_output
  help_output="$(WP_CLI_PAGER=cat "$WP_CLI_BIN" help i18n make-json 2>/dev/null || true)"
  if printf '%s' "$help_output" | rg -q -- '--purge'; then
    echo "i18n-command is older than v2.7: 'wp i18n make-json' still has --purge (default true)" >&2
    echo "and would DELETE JS translations from .po files." >&2
    echo "Use vendor/bin/wp (run 'composer install') or upgrade the i18n-command package." >&2
    exit 1
  fi
}

require_gettext() {
  local missing=0
  if ! command -v msgattrib >/dev/null 2>&1; then
    echo "GNU gettext (msgattrib) is required." >&2
    missing=1
  fi
  if ! command -v msgmerge >/dev/null 2>&1; then
    echo "GNU gettext (msgmerge) is required." >&2
    missing=1
  fi
  if [[ "$missing" -ne 0 ]]; then
    echo "Install gettext: 'apt-get install gettext' or 'brew install gettext'." >&2
    exit 1
  fi



  echo "DEBUG: $(msgmerge --version | head -1)" >&2
  echo "DEBUG: PHP $("$WP_CLI_BIN" cli version --allow-root 2>&1 | head -1)" >&2
}

build_pot() {


  "$WP_CLI_BIN" i18n make-pot . "$POT_FILE" \
    --slug="$TEXT_DOMAIN" \
    --domain="$TEXT_DOMAIN" \
    --include="$SOURCE_PATHS" \
    --exclude="$EXCLUDE_PATHS"
}








build_mo() {
  "$WP_CLI_BIN" i18n make-mo "$LANG_DIR/"
}

update_po() {

  local po
  for po in "$LANG_DIR"/*.po; do
    [[ -f "$po" ]] || continue













    msgmerge --update --backup=off --no-fuzzy-matching --quiet --no-wrap "$po" "$POT_FILE"
  done
}

build_json() {
  find "$LANG_DIR" -maxdepth 1 -type f -name 'plathix-*.json' -delete





  #







  local snapshot_dir po live mutated=0
  snapshot_dir="$(mktemp -d)"
  trap 'rm -rf "$snapshot_dir"' EXIT
  for po in "$LANG_DIR"/*.po; do
    [[ -f "$po" ]] || continue
    cp "$po" "$snapshot_dir/$(basename "$po")"
  done
  "$WP_CLI_BIN" i18n make-json "$LANG_DIR/"
  for po in "$snapshot_dir"/*.po; do
    [[ -f "$po" ]] || continue
    live="$LANG_DIR/$(basename "$po")"
    if ! cmp -s "$po" "$live"; then
      echo "build_json: wp i18n make-json changed a PO file; build stopped:" >&2
      { diff -u "$po" "$live" 2>&1 | head -40 >&2; } || true
      mutated=1
    fi
  done
  rm -rf "$snapshot_dir"
  trap - EXIT
  if [[ "$mutated" -ne 0 ]]; then
    exit 1
  fi
}










merge_bundle_json() {
  python3 - "$LANG_DIR" "$TEXT_DOMAIN" <<'PY'
import glob
import hashlib
import json
import os
import re
import sys

lang_dir, domain = sys.argv[1], sys.argv[2]






BUNDLE_MAP = {
    'assets/js/admin-ui.js': ('resources/js/admin-ui/', 'resources/js/admin-ui.js'),
    'assets/js/admin-ui/settings.js': ('src/Modules/Settings/assets/',),
    'assets/js/admin-ui/preset.js': ('src/Modules/Preset/assets/',),
    'assets/js/admin-ui/dashboard.js': ('src/Modules/Dashboard/assets/',),
}

locales = sorted(
    re.sub(rf'^{re.escape(domain)}-|\.po$', '', os.path.basename(p))
    for p in glob.glob(f'{lang_dir}/{domain}-*.po')
)

failed = False
for locale in locales:
    per_source = []
    for j in sorted(glob.glob(f'{lang_dir}/{domain}-{locale}-*.json')):
        with open(j, encoding='utf-8') as fh:
            d = json.load(fh)
        src = d.get('source', '')
        if src in BUNDLE_MAP:
            continue 
        per_source.append((j, src, d))

    for bundle_rel, prefixes in sorted(BUNDLE_MAP.items()):
        matched = [(j, src, d) for (j, src, d) in per_source if src.startswith(prefixes)]
        if not matched:
            continue 
        merged, header = {}, None
        for j, src, d in matched:
            msgs = d.get('locale_data', {}).get('messages', {})
            if header is None and msgs.get(''):
                header = msgs['']
            merged.update({k: v for k, v in msgs.items() if k})
        if header is None:


            print(
                f'merge_bundle_json: no bundle source matched {bundle_rel} ({locale}) '
                'Build failed.',
                file=sys.stderr,
            )
            failed = True
            continue
        merged[''] = header
        target_hash = hashlib.md5(bundle_rel.encode()).hexdigest()
        target = f'{lang_dir}/{domain}-{locale}-{target_hash}.json'
        out = {
            'translation-revision-date': '',
            'generator': 'plathix build-i18n.sh (bundle merge)',
            'source': bundle_rel,
            'domain': 'messages',
            'locale_data': {'messages': merged},
        }
        with open(target, 'w', encoding='utf-8') as fh:
            json.dump(out, fh, ensure_ascii=False, sort_keys=True, separators=(',', ':'))
        for j, src, d in matched:
            if os.path.basename(j) != os.path.basename(target):
                os.remove(j)
        print(f'merge_bundle_json: {target} <- {len(matched)} sources, {len(merged) - 1} strings')

    covered = tuple(p for prefixes in BUNDLE_MAP.values() for p in prefixes)
    for j, src, d in per_source:
        if not src.startswith(covered):
            print(
                f'merge_bundle_json: {j} (source={src}) is not covered by BUNDLE_MAP - '
                'Public-facing message unavailable.',
                file=sys.stderr,
            )
            failed = True

sys.exit(1 if failed else 0)
PY
}


#





#











#





strip_volatile_metadata() {
  local tmp po
  if [[ -f "$POT_FILE" ]]; then
    tmp="${POT_FILE}.strip.tmp"


    msgattrib --no-wrap --no-location "$POT_FILE" -o "$tmp"
    mv "$tmp" "$POT_FILE"
    sed -i 's/^"POT-Creation-Date:[^\\]*\\n"$/"POT-Creation-Date: \\n"/' "$POT_FILE"
  fi
  for po in "$LANG_DIR"/*.po; do
    [[ -f "$po" ]] || continue
    sed -i 's/^"POT-Creation-Date:[^\\]*\\n"$/"POT-Creation-Date: \\n"/' "$po"
  done
}









purge_obsolete() {
  local po tmp
  for po in "$LANG_DIR"/*.po; do
    [[ -f "$po" ]] || continue
    tmp="${po}.purge.tmp"


    msgattrib --no-wrap --no-obsolete "$po" -o "$tmp"
    mv "$tmp" "$po"
  done
}



#




#




#





#

strip_php_locations() {
  local po tmp
  for po in "$LANG_DIR"/*.po; do
    [[ -f "$po" ]] || continue
    tmp="${po}.phploc.tmp"
    awk '/^#: / { if ($0 !~ /\.js(:|$| )/) next } { print }' "$po" > "$tmp"
    mv "$tmp" "$po"
  done
}

print_report() {
  printf 'I18N_FILES\n'
  find "$LANG_DIR" -maxdepth 1 -type f | sort
}




seed_out_dir_if_needed() {
  [[ "$LANG_DIR" == "languages" ]] && return
  mkdir -p "$LANG_DIR"
  cp languages/*.po "$LANG_DIR/" 2>/dev/null || true
}

require_wp_cli
require_modern_i18n_command
require_gettext
seed_out_dir_if_needed
build_pot
update_po
build_json
merge_bundle_json
purge_obsolete
strip_volatile_metadata
strip_php_locations
build_mo
print_report

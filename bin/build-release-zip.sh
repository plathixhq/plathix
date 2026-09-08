#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

readonly BUILD_KIND="${1:-test}"

# shellcheck source=bin/lib/verify-artifact-authenticity.sh
source "${ROOT_DIR}/bin/lib/verify-artifact-authenticity.sh"



#














readonly LOCK_FILE="${ROOT_DIR}/.build.lock"
readonly LOCK_TIMEOUT_SEC=900





record_lock_holder() {
  printf '%s %s\n' "$$" "$(date -Is)" > "$LOCK_FILE" || true
}

require_rg() {




  if ! command -v rg >/dev/null 2>&1; then
    echo "UI is removed from the public build." >&2
    echo "Install ripgrep: 'apt-get install ripgrep' or 'brew install ripgrep'." >&2
    exit 1
  fi
}

acquire_build_lock() {
  exec 9>>"$LOCK_FILE"
  if flock -n 9; then
    record_lock_holder
    return 0
  fi
  local holder
  holder="$(head -1 "$LOCK_FILE" 2>/dev/null || true)"
  echo "UI is removed from the public build." >&2
  if ! flock -w "$LOCK_TIMEOUT_SEC" 9; then
    echo "UI is removed from the public build." >&2
    exit 1
  fi
  record_lock_holder
}



purge_stale_stages() {
  find /tmp -maxdepth 1 -name 'plathix-release.*' -type d -mtime +1 \
    -exec rm -rf {} + 2>/dev/null || true
}

require_rg
acquire_build_lock
purge_stale_stages

STAGE_ROOT="$(mktemp -d /tmp/plathix-release.XXXXXX)"
readonly STAGE_ROOT
readonly SRC_STAGE_DIR="${STAGE_ROOT}/src"
readonly STAGE_DIR="${STAGE_ROOT}/plathix"


readonly BUILD_LOG="${STAGE_ROOT}/build.log"
readonly COMPOSER_LOG="${STAGE_ROOT}/composer.log"



cleanup_stage_root() {
  local rc=$?
  if [[ $rc -eq 0 ]]; then
    rm -rf "$STAGE_ROOT"
  else
    printf 'STAGE=%s\n' "$STAGE_ROOT"
  fi
  return $rc
}
trap cleanup_stage_root EXIT


#




#





#





#


ROLE_DEV_ONLY_DIRS=(


  "src/PhpstanRules"




  "src/DevContracts"



  ".local-tools"
  "local-tools"
)



ROLE_BUILD_ONLY_PATHS=(






  "composer.json"
)


role_dev_only_patterns() {
  local dir
  for dir in "${ROLE_DEV_ONLY_DIRS[@]}"; do
    printf '%s/*\n' "$dir"
  done
}





#



COMMON_EXCLUDES=(
  .git
  .github


  .ci
  .claude
  .claudeignore
  node_modules
  dist




  builds
  tests
  scratch
  docs
  spec



  graphify-out
  resources
  playwright-report
  test-results
  .phpunit.result.cache

  .build.lock
  .gitignore
  .gitignore.public-template
  Makefile
  scoper.inc.php
  folder.svg
  plathix-spec.md
  "*.zip"
  wp.md
  CLAUDE.md
  readme.md
  CHANGELOG.md
  CONTRIBUTING.md
  "*.po"
  "*.pot"





  "languages/*.mo"
  "languages/*.json"




  "*/__tests__/*"
  "*.test.js"
  "*.bak"
  "*.log"
  "*.diff"
  "*.patch"
  "*.orig"
  "*.rej"
  "*~"
  plugin.bak
  AGENTS.md
  README.md
  .wp-env.release.json
)



while IFS= read -r _role_pattern; do
  [[ -n "$_role_pattern" ]] && COMMON_EXCLUDES+=("$_role_pattern")
done < <(role_dev_only_patterns)
unset _role_pattern

DEV_RELEASE_EXCLUDES=(
  pnpm-lock.yaml
  pnpm-workspace.yaml
  package.json
  phpunit.xml.dist
  phpcs.xml.dist



  jest.config.js
  phpstan.neon
  phpstan-baseline.neon
  .wp-env.json
  .eslintignore
  jsconfig.json
  bin
  composer.lock
  webpack.config.js
  vite.config.js
  playwright.config.js
  .eslintrc.js
)

DEV_VENDOR_EXCLUDES=(
  vendor/brain
  vendor/phpunit
  vendor/phpstan
  vendor/vimeo
  vendor/mockery
  vendor/antecedent
  vendor/jetbrains
  vendor/squizlabs
  vendor/wp-coding-standards
  vendor/phpcsstandards
  vendor/dealerdirect
  vendor/humbug
)

required_js_assets=(
  sidebar.js
  sidebar.asset.php
  admin-ui.js
  admin-ui.asset.php
  import.js
  import.asset.php

)

build_assets() {
  bash bin/build-assets.sh >"$BUILD_LOG" 2>&1 || {
    echo "UI is removed from the public build." >&2
    cat "$BUILD_LOG" >&2
    exit 1
  }
}

build_i18n() {
  if [[ "$BUILD_KIND" == "release" ]]; then
    bash bin/build-i18n.sh
    assert_i18n_git_clean
  else



    I18N_TEST_STAGE_DIR="$(mktemp -d)"
    bash bin/build-i18n.sh --out="$I18N_TEST_STAGE_DIR"
  fi
}

assert_i18n_git_clean() {



  if [[ -n "$(git status --porcelain -- languages/ 2>/dev/null)" ]]; then
    echo "" >&2
    echo "BUILD BLOCKED: languages/ regenerated with uncommitted changes. Commit translations before cutting a release." >&2
    git status --short -- languages/ >&2
    git diff -- languages/ >&2
    exit 1
  fi
}

detect_versions() {
  if [[ "$BUILD_KIND" != "test" && "$BUILD_KIND" != "release" ]]; then
    echo "Usage: bash bin/build-test-zip.sh [test|release]" >&2
    exit 1
  fi



  local raw
  raw="$(sed -n 's/^ \* Version:\s*//p' plathix.php | head -n 1 | tr -d '[:space:]')"
  BASE_VERSION="$(rg -o '^[0-9]+\.[0-9]+\.[0-9]+' <<<"$raw")"
  if [[ -z "${BASE_VERSION}" ]]; then
    echo "Could not detect base version from plathix.php (got: '$raw')" >&2
    exit 1
  fi

  STAMP="$(date +%Y%m%d-%H%M%S)"


  BUILD_VERSION="${BASE_VERSION}.$(date +%Y%m%d%H%M%S)"
  mkdir -p "${ROOT_DIR}/builds"
  if [[ "$BUILD_KIND" == "release" ]]; then
    OUT="${ROOT_DIR}/builds/plathix-${BUILD_VERSION}.zip"
  else
    OUT="${ROOT_DIR}/builds/plathix-${BUILD_VERSION}-test.zip"
  fi
}

reset_stage() {



  mkdir -p "$SRC_STAGE_DIR" "$STAGE_DIR"
}





#





purge_unwired_rtl_css() {
  if [[ -d "$STAGE_DIR/assets" ]]; then
    find "$STAGE_DIR/assets" -maxdepth 1 -name '*-rtl.css' -delete
    rm -rf "$STAGE_DIR/assets/admin-ui"
  fi
}

append_excludes() {
  local -n _target=$1
  shift
  local value
  for value in "$@"; do
    _target+=(--exclude="$value")
  done
}

stage_source_tree() {
  local args=(-a --delete)
  append_excludes args "${COMMON_EXCLUDES[@]}" vendor







  #





  #








  local tracked_list filtered_list
  tracked_list="$(mktemp)"
  if ! git ls-files -z > "$tracked_list" 2>/dev/null || [[ ! -s "$tracked_list" ]]; then
    rm -f "$tracked_list"
    echo "" >&2
    echo "UI is removed from the public build." >&2
    echo "Build failed." >&2
    exit 1
  fi


  filtered_list="$(mktemp)"
  python3 - "$tracked_list" "$filtered_list" "${COMMON_EXCLUDES[@]}" vendor <<'FILTER'
import fnmatch, sys

src, dst, patterns = sys.argv[1], sys.argv[2], sys.argv[3:]
kept = []
with open(src, 'rb') as fh:
    entries = [e.decode('utf-8') for e in fh.read().split(b'\0') if e]
for path in entries:
    head = path.split('/', 1)[0]
    name = path.rsplit('/', 1)[-1]
    if any(fnmatch.fnmatch(head, p) or fnmatch.fnmatch(name, p) or fnmatch.fnmatch(path, p)
           for p in patterns):
        continue
    kept.append(path)
with open(dst, 'wb') as fh:
    fh.write(b'\0'.join(k.encode('utf-8') for k in kept))
    if kept:
        fh.write(b'\0')
FILTER
  rm -f "$tracked_list"
  args+=(--files-from="$filtered_list" --from0)

  rsync "${args[@]}" ./ "$SRC_STAGE_DIR/"
  rm -f "$filtered_list"








  strip_internal_comment_refs "$SRC_STAGE_DIR"
}















if ! declare -F log >/dev/null; then log() { echo "[build] $*"; }; fi

strip_internal_comment_refs() {
  local target_dir="$1"
  echo "Stripping internal process metadata from PHP/JS/CSS comments in ${target_dir}..."
  export MARKER_PATTERN='PLX-[A-Z0-9]+(-[A-Za-z0-9]+)*|DEBT-[0-9]+|issue #[0-9]+|Skeptic Record|open-questions|root cause|non-goals'
  local f
  while IFS= read -r -d '' f; do

    # block comments often wrap continuation lines without a leading `*` (unlike PHP
    # docblocks), so a per-line anchor misses them. Non-greedy `.*?` stops at the first
    # literal `*/` inside the comment text if one exists — rare, and no worse than status quo.
    perl -CSD -0777 -i -pe '
      my $mp = $ENV{MARKER_PATTERN};
      s{(/\*.*?\*/)}{ my $b = $1; $b =~ s/($mp)[A-Za-z0-9#_-]*/[internal]/g; $b }gse;
    ' "$f"
    perl -CSD -i -pe '
      my $mp = $ENV{MARKER_PATTERN};
      if (m{^\s*(<\?php\s+)?(//|\*)}) {
        s/($mp)[A-Za-z0-9#_-]*/[internal]/g;
      }

      # literal + trailing punctuation is the exact shape of multi-line
      # RuleErrorBuilder::message(sprintf(...)) text in src/PhpstanRules/*.php.
      if (m{^\s*'"'"'.*($mp).*'"'"'\s*[.,)]\s*$}) {
        s/($mp)[A-Za-z0-9#_-]*/[internal]/g;
      }

      # including local wrapper aliases (describeOrSkip) and common modifiers (.skip/.only) —

      # Anchored on the known Jest/Mocha API names, not arbitrary identifiers, so a random
      # function call with a marker-like first string arg is never touched.
      if (m{^\s*(describe|it|test)(OrSkip|\.skip|\.only|\.each\([^)]*\))?\(\s*['"'"'"]}) {
        s/($mp)[A-Za-z0-9#_-]*/[internal]/g;
      }

      # anchored on `; //` (not bare `//`) so a URL literal containing `//` is never touched.
      if (m{^(.*;\s*//\s+)(.*($mp).*)$}) {
        my ($pre, $rest) = ($1, $2);
        $rest =~ s/($mp)[A-Za-z0-9#_-]*/[internal]/g;
        $_ = $pre . $rest . "\n";
      }
    ' "$f"
  done < <(find "$target_dir" -type f \( -name '*.php' -o -name '*.js' -o -name '*.css' \) -print0)
}






verify_no_private_content() {
  local target_dir="$1"
  echo "Verifying no private/AI-tooling content in ${target_dir}..."

  local terms_file="${ROOT_DIR}/bin/private-content-terms.txt"







  if [[ ! -f "$terms_file" ]]; then
    echo "File not found" >&2
    exit 1
  fi
  if [[ ! -r "$terms_file" ]]; then
    echo "Cannot read file" >&2
    exit 1
  fi

  local declared_off
  declared_off="$(rg -c '^# gate: not-applicable reason=' "$terms_file")" || declared_off=0
  if [[ "$declared_off" != "0" ]]; then
    echo "Public-facing message unavailable." >&2
    rg -n '^# gate: not-applicable reason=' "$terms_file" >&2
    return 0
  fi

  local pattern


  pattern="$(rg -v '^\s*(#|$)' "$terms_file" | paste -sd'|' -)" || pattern=""
  if [[ -z "$pattern" ]]; then
    echo "Required verification failed; build stopped." >&2
    exit 1
  fi




  local hits rc=0
  hits="$(rg -ni "$pattern" "$target_dir" --glob '!**/vendor/**' --glob '!**/*.mo' --glob '!**/*.min.js' --glob '!**/*.map')" || rc=$?
  if [[ $rc -eq 0 ]]; then
    echo "BLOCKED: private content found in staged artifact:" >&2
    echo "$hits" >&2
    exit 1
  fi
  if [[ $rc -ne 1 ]]; then
    echo "Required verification failed; build stopped." >&2
    exit 1
  fi

  local ai_hits ai_rc=0
  ai_hits="$(rg -ni 'claude|anthropic|chatgpt|copilot|codex' "$target_dir" --glob '!**/vendor/**' --glob '!**/*.mo' --glob '!**/*.min.js' --glob '!**/*.map')" || ai_rc=$?
  if [[ $ai_rc -eq 0 ]]; then
    echo "BLOCKED: AI-tooling mentions found in staged artifact:" >&2
    echo "$ai_hits" >&2
    exit 1
  fi
  if [[ $ai_rc -ne 1 ]]; then
    echo "Required verification failed; build stopped." >&2
    exit 1
  fi
}

verify_no_internal_refs() {
  local target_dir="$1"
  echo "Verifying no internal process metadata remains in ${target_dir}..."
  local hits





  local rc=0





  local build_info_glob=()
  [[ "$BUILD_KIND" != "release" ]] && build_info_glob=(--glob '!BUILD_INFO')




  hits="$(rg -n 'PLX-[A-Z0-9]+(-[A-Za-z0-9]+)*|DEBT-[0-9]+|issue #[0-9]+|Skeptic Record|open-questions|root cause|non-goals' "$target_dir" --glob '!**/vendor/**' --glob '!**/*.mo' --glob '!**/*.min.js' --glob '!**/*.map' "${build_info_glob[@]}")" || rc=$?
  if [[ $rc -eq 0 ]]; then
    echo "BLOCKED: internal process metadata survived export sweep:" >&2
    echo "$hits" >&2
    exit 1
  fi
  if [[ $rc -ne 1 ]]; then
    echo "Required verification failed; build stopped." >&2
    exit 1
  fi
}




#



purge_build_only_role() {





  if [[ -n "${I18N_TEST_STAGE_DIR:-}" ]]; then
    rm -rf "$I18N_TEST_STAGE_DIR"
  fi

  (( ${#ROLE_BUILD_ONLY_PATHS[@]} )) || return 0
  local relative
  for relative in "${ROLE_BUILD_ONLY_PATHS[@]}"; do



    rm -rf "${SRC_STAGE_DIR:?}/${relative}" "${STAGE_DIR:?}/${relative}"
  done
}

sync_runtime_vendor() {










  #







  composer install --working-dir="$SRC_STAGE_DIR" --no-dev --no-scripts --no-plugins -o >"$COMPOSER_LOG" 2>&1 || {
    echo "Install development dependencies: composer install" >&2
    cat "$COMPOSER_LOG" >&2
    exit 1
  }
}

stage_generated_assets() {

  # output (bin/build-assets.sh runs webpack with output.clean, wiping and rebuilding
  # them on every run). Neither directory is ever committed to git (no .gitignore entry
  # either — they simply live untracked between builds), so stage_source_tree()'s

  # problem as vendor/ (see sync_runtime_vendor() above) — same fix shape: copy straight
  # from the live working tree into $SRC_STAGE_DIR, bypassing the git filter entirely,
  # for this one explicitly named generated contour only.
  #
  # assets/img and assets/presets are NOT touched here: both are actually committed to
  # git (webpack's output.clean keep-regex spares assets/img, see purge_unwired_rtl_css()
  # comment above) — stage_source_tree()'s git-based copy already stages them correctly.
  #
  # Runs after build_assets() (already regenerated $ROOT_DIR/assets/{css,js} earlier in
  # the main sequence) and before assemble_release_tree() (otherwise this would never
  # reach $STAGE_DIR) — same ordering constraint documented on scope_runtime_vendor()
  # above.
  local src
  for src in css js; do
    if [[ ! -d "$ROOT_DIR/assets/$src" ]] || [[ -z "$(find "$ROOT_DIR/assets/$src" -type f -print -quit)" ]]; then
      echo "BUILD BLOCKED: \$ROOT_DIR/assets/$src is missing or empty — run build_assets() first." >&2
      exit 1
    fi
  done
  mkdir -p "$SRC_STAGE_DIR/assets"
  rsync -a --delete "$ROOT_DIR/assets/css/" "$SRC_STAGE_DIR/assets/css/"
  rsync -a --delete "$ROOT_DIR/assets/js/" "$SRC_STAGE_DIR/assets/js/"
}

scope_runtime_vendor() {





  #


  #


  # bin/build-pro-zip.sh.
  local scoper="$ROOT_DIR/vendor/bin/php-scoper"
  if [[ ! -x "$scoper" ]]; then
    echo "File not found" >&2
    echo "Install development dependencies: composer install" >&2
    exit 1
  fi

  local scoped_dir="${STAGE_ROOT}/scoped"
  rm -rf "$scoped_dir"




  "$scoper" add-prefix \
    --config="$ROOT_DIR/scoper.inc.php" \
    --working-dir="$SRC_STAGE_DIR" \
    --output-dir="$scoped_dir" \
    --force --quiet >>"$COMPOSER_LOG" 2>&1 || {
    echo "php-scoper is required; build stopped." >&2
    cat "$COMPOSER_LOG" >&2
    exit 1
  }


  local sub
  for sub in src vendor; do
    if [[ -d "$scoped_dir/$sub" ]]; then
      rm -rf "${SRC_STAGE_DIR:?}/$sub.pre-scope"
      mv "$SRC_STAGE_DIR/$sub" "$SRC_STAGE_DIR/$sub.pre-scope"


      mv "$SRC_STAGE_DIR/$sub.pre-scope" "$SRC_STAGE_DIR/$sub"
      cp -a "$scoped_dir/$sub/." "$SRC_STAGE_DIR/$sub/"
    fi
  done
  rm -rf "$scoped_dir"

  sync_scoped_autoload_registry
}

sync_scoped_autoload_registry() {





  #


  php -r '
    $stage = $argv[1];
    $registry = $stage . "/vendor/composer/installed.json";
    if (!is_file($registry)) { fwrite(STDERR, "installed.json is missing or unreadable"); exit(1); }
    $data = json_decode(file_get_contents($registry), true);
    if (!is_array($data)) { fwrite(STDERR, "installed.json is missing or unreadable"); exit(1); }
    $patched = 0;
    foreach (($data["packages"] ?? []) as $i => $pkg) {
        $manifest = $stage . "/vendor/" . $pkg["name"] . "/composer.json";
        if (!is_file($manifest)) { continue; }
        $actual = json_decode(file_get_contents($manifest), true);
        $actualPsr4 = $actual["autoload"]["psr-4"] ?? null;
        $registryPsr4 = $pkg["autoload"]["psr-4"] ?? null;
        if ($actualPsr4 && $registryPsr4 && $actualPsr4 !== $registryPsr4) {
            $data["packages"][$i]["autoload"]["psr-4"] = $actualPsr4;
            $patched++;
        }
    }
    file_put_contents($registry, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fwrite(STDERR, "Registry is not available in Free.");
  ' "$SRC_STAGE_DIR" 2>>"$COMPOSER_LOG" || {
    echo "installed.json is missing or unreadable" >&2
    cat "$COMPOSER_LOG" >&2
    exit 1
  }


  composer dump-autoload --working-dir="$SRC_STAGE_DIR" --no-dev -o >>"$COMPOSER_LOG" 2>&1 || {
    echo "Public-facing message unavailable." >&2
    cat "$COMPOSER_LOG" >&2
    exit 1
  }
}

assemble_release_tree() {
  local args=(-a --delete)
  append_excludes args "${COMMON_EXCLUDES[@]}"
  append_excludes args "${DEV_RELEASE_EXCLUDES[@]}"
  append_excludes args "${DEV_VENDOR_EXCLUDES[@]}"
  rsync "${args[@]}" "$SRC_STAGE_DIR/" "$STAGE_DIR/"
  purge_unwired_rtl_css
}

assert_i18n_complete() {











  local po_source="languages/plathix-ru_RU.po"
  if [[ "$BUILD_KIND" != "release" && -n "${I18N_TEST_STAGE_DIR:-}" ]]; then
    po_source="${I18N_TEST_STAGE_DIR}/plathix-ru_RU.po"
  fi
  bash bin/check-i18n-completeness.sh "$po_source" bin/i18n-baseline-plathix.txt
}

stamp_release_version() {






  export BASE_VERSION
  sed -i -E "s#^( \\* Version:[[:space:]]*).*\$#\\1${BASE_VERSION}#" "$STAGE_DIR/plathix.php"
  sed -i -E "s#define\\([[:space:]]*'PLATHIX_VERSION'[[:space:]]*,[[:space:]]*'[^']+'[[:space:]]*\\);#define('PLATHIX_VERSION', '${BASE_VERSION}');#" "$STAGE_DIR/includes/bootstrap.php"
  perl -0pi -e 's/^(Stable tag:\s*).*$/$1$ENV{BASE_VERSION}/m' "$STAGE_DIR/readme.txt"
}

write_build_info() {



  local commit dirty
  commit="$(git rev-parse HEAD)"




  if [[ -n "$(git status --porcelain -uno)" ]]; then
    dirty=true
  else
    dirty=false
  fi





  if [[ "$BUILD_KIND" == "release" && "$dirty" == "true" ]]; then
    echo "" >&2
    echo "BUILD BLOCKED: working tree is dirty. Commit changes before cutting a release." >&2
    git status --short -uno >&2
    exit 1
  fi
  {
    printf 'commit=%s\n' "$commit"
    printf 'dirty=%s\n' "$dirty"
    printf 'built_at=%s\n' "$(date +%Y%m%d-%H%M%S)"













    if [[ "$BUILD_KIND" != "release" ]]; then
      src_name="$(basename "$ROOT_DIR")"
      [[ "$src_name" == .* ]] && src_name="$(basename "$(dirname "$ROOT_DIR")")/${src_name}"
      printf 'source_root=%s\n' "$src_name"
      printf 'branch=%s\n' "$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
    fi
  } > "$STAGE_DIR/BUILD_INFO"
}







#





#



#


normalize_stage_permissions() {
  find "$STAGE_ROOT" -type d -exec chmod 755 {} +
  find "$STAGE_ROOT" -type f -exec chmod 644 {} +
}

create_archive() {
  rm -f "$OUT"
  (
    cd "$STAGE_ROOT"
    zip -rq "$OUT" plathix
  )
}




ZIP_ENTRIES=""
load_zip_entries() {
  ZIP_ENTRIES=$( zipinfo -1 "$OUT" )
}





assert_zip_not_contains() {
  local pattern=$1 rc=0
  rg -q "$pattern" <<<"$ZIP_ENTRIES" || rc=$?
  if [[ $rc -eq 0 ]]; then
    echo "Archive contains blocked path pattern: $pattern" >&2
    exit 1
  fi
  if [[ $rc -ne 1 ]]; then
    echo "Required verification failed; build stopped." >&2
    exit 1
  fi
}

assert_zip_contains_path() {
  local path=$1 rc=0
  rg -qx "$path" <<<"$ZIP_ENTRIES" || rc=$?
  if [[ $rc -eq 1 ]]; then
    echo "Archive is missing required path: $path" >&2
    exit 1
  fi
  if [[ $rc -ne 0 ]]; then
    echo "UI is removed from the public build." >&2
    exit 1
  fi
}

assert_zip_contains_pattern() {
  local pattern=$1 rc=0
  rg -q "$pattern" <<<"$ZIP_ENTRIES" || rc=$?
  if [[ $rc -eq 1 ]]; then
    echo "Archive is missing required path pattern: $pattern" >&2
    exit 1
  fi
  if [[ $rc -ne 0 ]]; then
    echo "UI is removed from the public build." >&2
    exit 1
  fi
}





require_stage_present() {
  local at="$1"
  if [[ ! -d "$STAGE_DIR" ]] || [[ -z "$(find "$STAGE_DIR" -mindepth 1 -print -quit 2>/dev/null)" ]]; then
    echo "UI is removed from the public build." >&2
    exit 1
  fi
}

assert_scoper_autoload_safe() {





  #



  local scoper_autoload="$STAGE_DIR/vendor/scoper-autoload.php"
  [[ -f "$scoper_autoload" ]] || return 0

  local forbidden
  forbidden="$(rg -o "function_exists\('(wp_[a-z_]*|apply_filters|add_query_arg|home_url|trailingslashit|dbDelta|get_current_[a-z_]*|as_[a-z_]*)'\)" \
    "$scoper_autoload" -r '$1' 2>/dev/null | sort -u || true)"

  if [[ -n "$forbidden" ]]; then
    echo "Public-facing message unavailable." >&2
    echo "$forbidden" | sed 's/^/  - /' >&2
    echo "Public-facing message unavailable." >&2
    exit 1
  fi
}

verify_archive() {
  require_stage_present "verify_archive"
  assert_scoper_autoload_safe

  local blocked_paths=(
    '^plathix/docs/'
    '^plathix/tests/'
    '^plathix/spec/'
    '^plathix/plathix-spec\.md$'
    '^plathix/resources/'
    '^plathix/playwright-report/'
    '^plathix/test-results/'
    '^plathix/CLAUDE\.md$'


    '^plathix/wp\.md$'
    '^plathix/Makefile$'
    '^plathix/scoper\.inc\.php$'
    '^plathix/webpack\.config\.js$'
    '^plathix/vite\.config\.js$'
    '^plathix/playwright\.config\.js$'
    '^plathix/composer\.lock$'
    '^plathix/jsconfig\.json$'
    '^plathix/phpstan-baseline\.neon$'
  )








  local _role_dir
  for _role_dir in "${ROLE_DEV_ONLY_DIRS[@]}" "${ROLE_BUILD_ONLY_PATHS[@]}"; do
    blocked_paths+=( "^plathix/$(printf '%s' "$_role_dir" | sed 's/[.[\*^$\/]/\\&/g')$" )
    blocked_paths+=( "^plathix/$(printf '%s' "$_role_dir" | sed 's/[.[\*^$\/]/\\&/g')/" )
  done

  load_zip_entries

  local pattern
  for pattern in "${blocked_paths[@]}"; do
    assert_zip_not_contains "$pattern"
  done

  assert_zip_contains_path 'plathix/plathix.php'
  assert_zip_contains_path 'plathix/readme.txt'
  assert_zip_contains_path 'plathix/uninstall.php'










  assert_stage_clean() {
    local pattern=$1 hint=$2 rc=0
    rg -q "$pattern" "$STAGE_DIR" --glob '!*.mo' --glob '!*.min.js' || rc=$?
    if [[ $rc -eq 0 ]]; then
      echo "Guideline-5 gate: staged Free tree contains forbidden pattern: $pattern ($hint)" >&2
      rg -n "$pattern" "$STAGE_DIR" --glob '!*.mo' | head -5 >&2
      exit 1
    fi
    if [[ $rc -ne 1 ]]; then
      echo "line" >&2
      exit 1
    fi
  }
  assert_stage_clean 'content_types/enabled' 'Content type filter is removed from Free.'
  assert_stage_clean 'ContentTypeRegistry' 'Content type filter is removed from Free.'
  assert_stage_clean 'plathix_post_types' 'Option belongs to Pro.'
  assert_stage_clean 'render_post_list_fragments' 'Public-facing message unavailable.'
  assert_stage_clean 'PostListAdapter' 'Adapter is provided by the Pro bundle.'
  assert_stage_clean 'render_enabled_sections' 'UI is removed from the public build.'






  assert_stage_clean '"edit"[!=]==?[a-zA-Z_$.]*screenBase' 'Public-facing message unavailable.'
  assert_stage_clean "screenBase [!=]==? 'edit'" 'Public-facing message unavailable.'
  assert_stage_clean "screen_context === 'edit'" 'Free resolver supports the media library only.'







  local css_file
  while IFS= read -r css_file; do
    [[ -z "$css_file" ]] && continue
    assert_zip_contains_path "plathix/assets/css/${css_file}"
  done < <(find resources/css -maxdepth 1 -type f -printf '%f\n' | sort)










  if [[ -d "$STAGE_DIR/assets/img" ]]; then
    local img_file
    while IFS= read -r img_file; do
      [[ -z "$img_file" ]] && continue
      assert_zip_contains_path "plathix/${img_file}"
    done < <(cd "$STAGE_DIR" && find assets/img -type f | sort)
  fi

  local js_asset
  for js_asset in "${required_js_assets[@]}"; do
    assert_zip_contains_path "plathix/assets/js/${js_asset}"
  done







  local empty_js
  empty_js="$(unzip -l "$OUT" 'plathix/assets/js/*.js' 2>/dev/null | awk '$1 == "0" && $NF ~ /\.js$/ {print $NF}' || true)"
  if [[ -n "$empty_js" ]]; then
    echo "CSS lint zone is empty; check webpack.config.js and graph traversal." >&2
    sed 's/^/  - /' <<<"$empty_js" >&2
    exit 1
  fi

  assert_zip_contains_pattern '^plathix/assets/presets/[^/]+/preset\.plx\.md$'
  assert_zip_contains_pattern '^plathix/assets/presets/[^/]+/preview\.(png|jpg|jpeg|webp)$'





  local pv bs rt
  pv="$(unzip -p "$OUT" plathix/plathix.php)"
  bs="$(unzip -p "$OUT" plathix/includes/bootstrap.php)"
  rt="$(unzip -p "$OUT" plathix/readme.txt)"
  rg -q "^ \* Version:[[:space:]]*${BASE_VERSION}[[:space:]]*\$" <<<"$pv" || { echo "Version in plathix.php does not match clean ${BASE_VERSION}" >&2; exit 1; }
  rg -qF "'PLATHIX_VERSION', '${BASE_VERSION}'" <<<"$bs" || { echo "PLATHIX_VERSION does not match clean ${BASE_VERSION}" >&2; exit 1; }
  rg -q "^Stable tag:[[:space:]]*${BASE_VERSION}[[:space:]]*\$" <<<"$rt" || { echo "Stable tag in readme.txt does not match clean ${BASE_VERSION}" >&2; exit 1; }





  #


  #



  local allowed_exact=(
    'plathix/plathix\.php'
    'plathix/readme\.txt'
    'plathix/uninstall\.php'
    'plathix/license\.txt'
    'plathix/THIRD-PARTY-NOTICES\.txt'
    'plathix/BUILD_INFO'




    'plathix/composer\.json'
  )

  local allowed_dir_prefixes=(
    'plathix/assets/'
    'plathix/src/'
    'plathix/views/'
    'plathix/includes/'
  )









  local vendor_allow=( 'plathix/vendor/composer/' 'plathix/vendor/autoload\.php' 'plathix/vendor/scoper-autoload\.php' )
  local pkg
  while IFS= read -r pkg; do
    [[ -z "$pkg" ]] && continue
    vendor_allow+=( "plathix/vendor/${pkg}/" )
  done < <(php -r '$lock = json_decode(file_get_contents("'"$ROOT_DIR"'/composer.lock"), true); foreach (($lock["packages"] ?? []) as $p) { echo preg_quote($p["name"], "/"), PHP_EOL; }')

  local entry matched
  while IFS= read -r entry; do
    [[ -z "$entry" ]] && continue
    [[ "$entry" == */ ]] && continue         
    matched=0
    local re
    for re in "${allowed_exact[@]}"; do
      [[ "$entry" =~ ^${re}$ ]] && { matched=1; break; }
    done
    if [[ "$matched" -eq 0 ]]; then
      for re in "${allowed_dir_prefixes[@]}" "${vendor_allow[@]}"; do
        [[ "$entry" =~ ^${re} ]] && { matched=1; break; }
      done
    fi
    if [[ "$matched" -eq 0 ]]; then
      echo "Public-facing message unavailable." >&2
      exit 1
    fi
  done <<<"$ZIP_ENTRIES"







  #








  local artauth_generated_allowlist=(
    "assets"
    "BUILD_INFO"
  )
  local vp
  for vp in "${vendor_allow[@]}"; do
    artauth_generated_allowlist+=( "${vp#plathix/}" )
  done


  artauth_generated_allowlist=( "${artauth_generated_allowlist[@]%/}" )
  artauth_generated_allowlist=( "${artauth_generated_allowlist[@]//\\/}" )





  local artauth_transform_rules=(
    "plathix.php|^( \\* Version:[[:space:]]*).*\$|\\1${BASE_VERSION}"
    "includes/bootstrap.php|define\\('PLATHIX_VERSION', '[^']+'\\);|define('PLATHIX_VERSION', '${BASE_VERSION}');"
    "readme.txt|^(Stable tag:[[:space:]]*).*\$|\\1${BASE_VERSION}"
  )






  #







  verify_artifact_content_authenticity "$ROOT_DIR" "$STAGE_DIR" \
    artauth_transform_rules artauth_generated_allowlist "${MARKER_PATTERN:-}" \
    "$ROOT_DIR/vendor/bin/php-scoper" "$ROOT_DIR/scoper.inc.php" "src"
}




publish_latest() {
  if [[ "$BUILD_KIND" == "release" ]]; then
    LATEST="${ROOT_DIR}/builds/latest-release.zip"
  else
    LATEST="${ROOT_DIR}/builds/latest-test.zip"
  fi
  cp -f "$OUT" "$LATEST"
}

print_report() {
  printf 'OUT=%s\n' "$OUT"
  printf 'LATEST=%s\n' "${LATEST:-}"
  printf 'PLUGIN_VERSION=%s\n' "$BASE_VERSION"
  printf 'STAMP=%s\n' "$STAMP"
  printf 'BUILD_VERSION=%s\n' "$BUILD_VERSION"
  printf '\nCSS_ASSETS\n'
  find resources/css -maxdepth 1 -type f -printf 'plathix/assets/css/%f\n' | sort
  printf '\nJS_ASSETS\n'
  local js_asset
  for js_asset in "${required_js_assets[@]}"; do
    printf 'plathix/assets/js/%s\n' "$js_asset"
  done
  if [[ -d assets/img ]]; then
    printf '\nIMG_ASSETS\n'
    find assets/img -type f -printf 'plathix/%p\n' | sort
  fi
  printf '\nVERSION_CHECK\n'
  unzip -p "$OUT" plathix/plathix.php | sed -n '1,25p' | rg 'Version:|PLATHIX_VERSION'
  unzip -p "$OUT" plathix/readme.txt | sed -n '1,10p' | rg '^Stable tag:'
  printf '\nSIZE\n'
  ls -lh "$OUT"
}

detect_versions
build_i18n
build_assets
reset_stage
stage_source_tree
sync_runtime_vendor
scope_runtime_vendor
stage_generated_assets
assemble_release_tree


write_build_info
verify_no_internal_refs "$STAGE_DIR"
verify_no_private_content "$STAGE_DIR"
assert_i18n_complete
purge_build_only_role
require_stage_present "stamp_release_version"
stamp_release_version
normalize_stage_permissions
create_archive
verify_archive
publish_latest
print_report

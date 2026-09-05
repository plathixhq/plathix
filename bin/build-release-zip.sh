#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

readonly BUILD_KIND="${1:-test}"

# shellcheck source=bin/lib/verify-artifact-authenticity.sh
source "${ROOT_DIR}/bin/lib/verify-artifact-authenticity.sh"

# ── Координация параллельных сборок ([internal]) ────────────────────────────────
# Две независимые гонки, лечатся двумя разными механизмами.
#
# 1. Гонка за РАБОЧЕЕ ДЕРЕВО. `bin/build-assets.sh` физически сносит и пересоздаёт
#    assets/ прямо в $ROOT_DIR (webpack output.clean), а publish_latest пишет
#    builds/latest-*.zip. Два билда в ОДНОМ дереве неизбежно топчут друг друга — это
#    дало плавающий сбой [internal]. Лечится замком, и замок висит на самом дереве,
#    НЕ на пути в /tmp: сборки разных веток/worktree-копий друг другу не мешают и
#    ждать не должны (на self-hosted раннере джобы одного репо делят $GITHUB_WORKSPACE —
#    их этот замок как раз упорядочит, а чужие ветки не тронет).
#    flock, а не самодельный lock-файл: блокировку снимает ядро при смерти процесса,
#    поэтому зависших замков после kill/падения раннера не бывает.
# 2. Гонка за СТЕЙДЖИНГ. Раньше путь был фиксированным (/tmp/plathix-release) и билд
#    первым делом сносил его целиком — параллельный запуск затирал чужую сборку и
#    подменял содержимое артефакта (реальный инцидент 2026-08-24). Лечится изоляцией:
#    свой каталог на каждый запуск. Это закрывает и процесс, который замок не взял
#    (старая копия скрипта, другой чекаут, publish-public-repo.sh со своим путём).
readonly LOCK_FILE="${ROOT_DIR}/.build.lock"
readonly LOCK_TIMEOUT_SEC=900

# Запись держателя: `>>`, а не `>`. Открытие на усечение обнуляло файл ещё до попытки
# взять замок — ждущая сборка стирала запись первой и печатала «pid неизвестен» вместо
# того, ради чего эта запись и существует. Замок живёт на дескрипторе и inode, поэтому
# перезапись содержимого под удержанным замком его не теряет.
record_lock_holder() {
  printf '%s %s\n' "$$" "$(date -Is)" > "$LOCK_FILE" || true
}

require_rg() {
  # Гейты ниже сканируют дерево через rg. Без этой проверки отсутствие rg не остановило бы
  # скрипт: `|| true` и `if rg …` не отличают «нарушений нет» (exit 1) от «искать нечем»
  # (exit 127/2), и гейт печатал бы PASS ровно тогда, когда ничего не проверил ([internal]).
  # Форма — та же, что require_gettext()/require_wp_cli() в bin/build-i18n.sh.
  if ! command -v rg >/dev/null 2>&1; then
    echo "ripgrep (rg) is required: гейты этого скрипта сканируют дерево через rg." >&2
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
  echo "[build] в этом дереве уже идёт сборка (${holder:-держатель неизвестен}); жду до ${LOCK_TIMEOUT_SEC}s…" >&2
  if ! flock -w "$LOCK_TIMEOUT_SEC" 9; then
    echo "[build] не дождался освобождения ${LOCK_FILE} за ${LOCK_TIMEOUT_SEC}s (держит ${holder:-держатель неизвестен}) — сборка отменена." >&2
    exit 1
  fi
  record_lock_holder
}

# Снос собственных осиротевших стейджей прошлых упавших сборок (сохраняются намеренно,
# см. trap ниже) — чтобы /tmp не рос бесконечно на долгоживущем раннере.
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
# Логи живут ВНУТРИ стейджа, но вне $STAGE_DIR (который упаковывается) — иначе
# параллельный запуск перетирал бы лог, по которому разбирают падение.
readonly BUILD_LOG="${STAGE_ROOT}/build.log"
readonly COMPOSER_LOG="${STAGE_ROOT}/composer.log"

# Успех — каталог убираем. Провал — сохраняем и печатаем путь той же формой, что OUT=,
# чтобы было куда зайти и посмотреть, что осталось в стейдже.
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

# --- Роли файлов в поставке ([internal], [internal]) ------------------------------
#
# Роль файла объявляется ЕГО РАСПОЛОЖЕНИЕМ, а не записью в списке имён. Это owner
# правила «едет ли файл в поставку»; сборщик — потребитель декларации, не источник
# решения. До этого состав архива задавали шесть несогласованных списков, и каждый новый
# dev-файл требовал ручной дописки — десять накопленных заплаток (#597).
#
# Три роли:
#   runtime    — по умолчанию всё, что не объявлено ниже; едет в архив;
#   dev-only   — не копируется вовсе (исключается на стейджинге, ДО composer install:
#                иначе classmap успел бы получить висячие записи, см. #562/#563);
#   build-only — копируется в стейдж (нужен во время сборки), удаляется до упаковки.
#
# Почему каталог, а не маркер внутри файла: третье условие фильтра stage_source_tree()
# (fnmatch на полный путь) уже умеет матчить каталог-паттерны — механизм существует,
# новый парсер PHP в bash не нужен. Каталог переживает переименование файла внутри себя,
# поимённая строка — нет (она молча мертвеет, и verify_archive этого не замечает:
# whitelist разрешает plathix/src/ по префиксу).
#
# Имя каталога — PSR-4 сегмент namespace, PascalCase (code-naming-standard), как уже
# сложившийся прецедент src/PhpstanRules/.
ROLE_DEV_ONLY_DIRS=(
  # dev-only PHPStan-правила: наследуют PHPStan\Rules\Rule, которого нет в поставке —
  # автозагрузка дала бы Fatal даже под @class_exists() (#562/#563).
  "src/PhpstanRules"
  # Каталоги контрактов, существующих только для статического анализа: реестр точек
  # публичного контракта Free читают PHPStan-правила PRO во время `composer stan`, но
  # ни один рантайм-путь плагина к ним не обращается ([internal]). В поставке они
  # безвредны, но бессмысленны — и раскрывают координацию с закрытым PRO-репозиторием.
  "src/DevContracts"
  # Локальный инструментарий разработчика (wp-cli phar и его пакеты). Сборке не нужен
  # вовсе, поэтому dev-only, а не build-only: не копируется изначально, а не удаляется
  # постфактум из двух стейджей, как было в purge_local_tooling().
  ".local-tools"
  "local-tools"
)

# build-only: нужны во время сборки, не нужны в архиве. Удаляются в purge_build_only_role()
# ПОСЛЕ sync_runtime_vendor() — раньше нельзя, composer install читает composer.json.
ROLE_BUILD_ONLY_PATHS=(
  # composer.json нужен sync_runtime_vendor() — установка prod-зависимостей идёт всегда
  # с нуля по lock ([internal]), поэтому файл обязан быть в стейдже. Но рантайму он не
  # нужен: автозагрузка идёт через собранный vendor/, а в src/ нет ни одного обращения.
  # В публичной поставке это список dev-инструментария (11 пакетов require-dev во Free,
  # 10 в PRO) — ровно тот класс dev-артефактов, который вычищали в #139 ([internal]).
  # Дописать в EXCLUDES нельзя — сломает composer install; поэтому роль, а не exclude.
  "composer.json"
)

# Раскрытие ролей в exclude-паттерны: один источник правды, ручных дублей ниже быть не должно.
role_dev_only_patterns() {
  local dir
  for dir in "${ROLE_DEV_ONLY_DIRS[@]}"; do
    printf '%s/*\n' "$dir"
  done
}

# Файлы/каталоги, которые НЕ едут в артефакт (dev-инструментарий и метаданные).
# Это blacklist (fail-open): whitelist-gate в verify_archive() (строка 631) ниже —
# fail-safe страховка, любой забытый здесь файл роняет билд, а не утекает в публичный
# ZIP ([internal], [internal]). Зеркало PRO bin/build-pro-zip.sh.
#
# Ручной хвост (осознанное решение, [internal]): здесь остаётся то, что расположением
# не выражается — корневые дот-файлы, служебные каталоги, расширения (*.log, *.zip, *.bak).
# Роли (см. выше) добавляются к этому списку автоматически ниже.
COMMON_EXCLUDES=(
  .git
  .github
  # [internal]: состав CI-образа (Dockerfile) живёт в репозитории, но это инфраструктура
  # раннера, не часть плагина для конечного пользователя — не должен ехать в shipped-ZIP.
  .ci
  .claude
  .claudeignore
  node_modules
  dist
  # builds/ — каталог для OUT-zip (detect_versions делает mkdir builds). Без этого exclude rsync
  # тащил пустой plathix/builds/ в staged-дерево → пустая dir-запись ехала в shipped-ZIP (#142).
  # Симметрия с PRO (build-pro-zip.sh EXCLUDES). OUT пишется zip'ом в $ROOT_DIR/builds напрямую,
  # не через rsync, поэтому exclude staged-дерева записи финального артефакта не мешает.
  builds
  tests
  scratch
  docs
  spec
  # локальный вывод /graphify — не рантайм и не dev-tooling репозитория, каталог создаётся
  # инструментом вне сборки; без исключения утекал бы в публичный ZIP (симметрия с PRO
  # build-pro-zip.sh EXCLUDES, [internal]).
  graphify-out
  resources
  playwright-report
  test-results
  .phpunit.result.cache
  # [internal]: файл-замок параллельных сборок живёт в рабочем дереве — в артефакт не едет.
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
  # РОЛЬ dev-only (см. ROLE_DEV_ONLY_DIRS ниже) — раскрывается в паттерны автоматически.
  # Поимённых строк для отдельных dev-файлов здесь быть не должно: роль объявляется
  # расположением файла, а не записью в этом списке.
  # __tests__/*.test.js — Jest-тесты, не должны попадать в release-поставку.
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

# Роль dev-only добавляется к чёрному списку из ОДНОГО источника (ROLE_DEV_ONLY_DIRS).
# Поимённые дописки dev-файлов выше запрещены: объявляй роль расположением файла.
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
  # jest.config.js — dev-конфиг прогона JS-тестов, рантайму не нужен ([internal]
  # добавил его в репозиторий, в исключения сборки не внесли — whitelist-гейт валил
  # релизную сборку обеих реп, [internal]).
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
  # lightbox.js убран — Gallery уехал в PRO ([internal]), Free его больше не собирает.
)

build_assets() {
  bash bin/build-assets.sh >"$BUILD_LOG" 2>&1 || {
    echo "build-assets провалился — лог ниже:" >&2
    cat "$BUILD_LOG" >&2
    exit 1
  }
}

build_i18n() {
  if [[ "$BUILD_KIND" == "release" ]]; then
    bash bin/build-i18n.sh
    assert_i18n_git_clean
  else
    # Test-сборка (стенд/дев-проверка, не публикуемый релиз) не должна грязнить
    # languages/ в рабочем git-дереве — regen'ится во временную папку, стейджится
    # в артефакт отдельным шагом после stage_source_tree() ([internal]).
    I18N_TEST_STAGE_DIR="$(mktemp -d)"
    bash bin/build-i18n.sh --out="$I18N_TEST_STAGE_DIR"
  fi
}

assert_i18n_git_clean() {
  # Release-сборка регенерирует languages/ прямо в рабочем дереве (как раньше), но
  # теперь явно требует, чтобы это не оставило незакоммиченный diff — иначе релиз
  # уезжает с переводами, которых нет в git HEAD ([internal]).
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

  # Берём ЧИСТУЮ базу (3 semver-сегмента). Даже если git-версия вдруг снова с хвостом
  # (1.1.8.<ts>) — извлекаем '1.1.8', чтобы штамп не накапливал '1.1.8.<ts>.<ts>' ([internal]).
  local raw
  raw="$(sed -n 's/^ \* Version:\s*//p' plathix.php | head -n 1 | tr -d '[:space:]')"
  BASE_VERSION="$(rg -o '^[0-9]+\.[0-9]+\.[0-9]+' <<<"$raw")"
  if [[ -z "${BASE_VERSION}" ]]; then
    echo "Could not detect base version from plathix.php (got: '$raw')" >&2
    exit 1
  fi

  STAMP="$(date +%Y%m%d-%H%M%S)"
  # BUILD_VERSION (база + timestamp) — ТОЛЬКО для имени артефакта, чтобы различать сборки.
  # В поле Version:/Stable tag/PLATHIX_VERSION уедет ЧИСТЫЙ BASE_VERSION (stamp_release_version).
  BUILD_VERSION="${BASE_VERSION}.$(date +%Y%m%d%H%M%S)"
  mkdir -p "${ROOT_DIR}/builds"
  if [[ "$BUILD_KIND" == "release" ]]; then
    OUT="${ROOT_DIR}/builds/plathix-${BUILD_VERSION}.zip"
  else
    OUT="${ROOT_DIR}/builds/plathix-${BUILD_VERSION}-test.zip"
  fi
}

reset_stage() {
  # [internal]: `rm -rf "$STAGE_ROOT"` здесь больше нет — каталог создан mktemp'ом лично
  # для этого запуска и пуст по построению. Прежний снос был именно тем действием, которое
  # затирало параллельную сборку.
  mkdir -p "$SRC_STAGE_DIR" "$STAGE_DIR"
}

# RTL CSS ([internal], [internal]) сгенерирован для всех entry, но
# pipeline/enqueue-wiring ещё не сделан — `is_rtl()` не встречается вообще нигде в
# src/ (grep пуст), ни один *-rtl.css не подключается. Мёртвый вес в публичном ZIP
# до закрытия #88, поэтому не шипуется.
#
# Это НЕ роль файла: причина здесь не «dev-инструментарий», а незавершённая фича, и
# исчезнет она вместе с закрытием #88 — а не с изменением правил поставки. Поэтому
# отдельная функция, а не запись в ROLE_*. Раньше эта логика жила внутри
# purge_local_tooling() вместе с удалением .local-tools — два несвязанных повода в
# одной функции; .local-tools переехал в роль dev-only ([internal]).
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

  # [internal] ([internal]): источник стейджинга — только файлы под контролем версий.
  # Раньше копировалось ВСЁ рабочее дерево минус COMMON_EXCLUDES, поэтому любой посторонний
  # файл ехал в артефакт. В корне его ловил whitelist-гейт, а ВНУТРИ разрешённых каталогов
  # (src/, includes/, languages/) — нет: гейт проверяет их по префиксу. Воспроизведено:
  # src/plx-probe-inside.php уезжал в ZIP при зелёной сборке. Тем же путём в проекте уже
  # утекали креды (_done/[internal]).
  #
  # Три механизма ловят РАЗНЫЕ классы и сосуществуют — ослаблять ни один нельзя:
  #   git-фильтр      → чужой/временный файл в дереве;
  #   COMMON_EXCLUDES → трекнутое, чему не место в дистрибутиве (tests/, spec/, docs/…);
  #   whitelist-гейт  → последний рубеж на выходе ([internal]).
  # Аргумент «источник теперь чистый, гейт можно расслабить» ЗАПРЕЩЁН (wp-security-skeptic).
  #
  # Генерируемое в стейджинге (vendor/ от composer, BUILD_INFO, словари test-режима)
  # добавляется ПОСЛЕ этого шага и фильтром не затрагивается.
  # ВАЖНО: --files-from ОТМЕНЯЕТ действие --exclude для перечисленных путей (проверено
  # экспериментом: файл из tests/ копировался, несмотря на --exclude=tests). Поэтому
  # фильтр применяется не как список файлов rsync'у, а как ЗАЩИТА ИСТОЧНИКА: сначала
  # получаем перечень трекнутых файлов, затем отдаём его rsync через --files-from ВМЕСТЕ
  # с явным вторым проходом excludes ниже. Порядок: git решает «что вообще существует
  # для сборки», excludes решают «что из этого не едет в дистрибутив».
  local tracked_list filtered_list
  tracked_list="$(mktemp)"
  if ! git ls-files -z > "$tracked_list" 2>/dev/null || [[ ! -s "$tracked_list" ]]; then
    rm -f "$tracked_list"
    echo "" >&2
    echo "BUILD BLOCKED: не удалось получить список файлов под контролем версий (git ls-files)." >&2
    echo "Сборка из дерева без git невозможна: иначе в артефакт молча уедет посторонний файл." >&2
    exit 1
  fi
  # Отсеиваем из списка всё, что попадает под COMMON_EXCLUDES: rsync их к --files-from
  # не применит, поэтому делаем это сами — по первому сегменту пути и по имени файла.
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
  # .local-tools здесь больше не чистится: он объявлен ролью dev-only и отсекается
  # фильтром выше, до копирования. RTL-CSS на этом этапе ещё не существует —
  # STAGE_DIR наполняется позже, в assemble_release_tree().
  stage_test_i18n
  strip_internal_comment_refs "$SRC_STAGE_DIR"
}

# [internal]: staged PHP-комментарии не должны нести internal
# process metadata (тикет-коды/skeptic-протокол/spec-упоминания) наружу — команда системно
# документирует найденные баги реальным кодом задачи ("[internal]", "issue #N"), что полезно в
# приватном дереве, но раскрывает внутренний журнал разработки любому стороннему читателю
# релизного ZIP. Заменяет каждый маркер на нейтральный плейсхолдер `[internal]` (НЕ пытается
# хирургически вырезать токен + окружающую пунктуацию — практическая проверка на реальных
# примерах из кодовой базы показала, что вырезание даёт непредсказуемо грязный результат
# ("см.." с двойной точкой, несбалансированные скобки); замена безопасна и предсказуема на
# любом тексте, WHY-часть комментария полностью сохраняется). Только внутри `//`/`/* */`/`*`
# комментарийных префиксов (regex anchored) — не трогает строковые литералы кода. Verify-шаг
# ниже — fail-safe: если маркер пережил sweep, сборка падает явно, не публикует молча.
# CTAN-402 shim: log() нигде не определён, а strip_internal_comment_refs (параллельный
# пакет [internal], уже в main) его зовёт — pre-existing поломка сборки на свежем
# main; зафиксировано в [internal] пакета [internal] как чужой долг.
if ! declare -F log >/dev/null; then log() { echo "[build] $*"; }; fi

strip_internal_comment_refs() {
  local target_dir="$1"
  echo "Stripping internal process metadata from PHP/JS/CSS comments in ${target_dir}..."
  export MARKER_PATTERN='PLX-[A-Z0-9]+(-[A-Za-z0-9]+)*|DEBT-[0-9]+|issue #[0-9]+|Skeptic Record|open-questions|root cause|non-goals'
  local f
  while IFS= read -r -d '' f; do
    # [internal]: multi-line /* */ block (slurp mode) FIRST — CSS/JS
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
      # [internal]: a line that is ENTIRELY a single string
      # literal + trailing punctuation is the exact shape of multi-line
      # RuleErrorBuilder::message(sprintf(...)) text in src/PhpstanRules/*.php.
      if (m{^\s*'"'"'.*($mp).*'"'"'\s*[.,)]\s*$}) {
        s/($mp)[A-Za-z0-9#_-]*/[internal]/g;
      }
      # [internal]: Jest/Mocha describe(/it(/test( first string arg,
      # including local wrapper aliases (describeOrSkip) and common modifiers (.skip/.only) —
      # test descriptions, not comments, but same leak shape (issue #N / [internal] in prose).
      # Anchored on the known Jest/Mocha API names, not arbitrary identifiers, so a random
      # function call with a marker-like first string arg is never touched.
      if (m{^\s*(describe|it|test)(OrSkip|\.skip|\.only|\.each\([^)]*\))?\(\s*['"'"'"]}) {
        s/($mp)[A-Za-z0-9#_-]*/[internal]/g;
      }
      # [internal]: trailing `; // comment` after code on the same line —
      # anchored on `; //` (not bare `//`) so a URL literal containing `//` is never touched.
      if (m{^(.*;\s*//\s+)(.*($mp).*)$}) {
        my ($pre, $rest) = ($1, $2);
        $rest =~ s/($mp)[A-Za-z0-9#_-]*/[internal]/g;
        $_ = $pre . $rest . "\n";
      }
    ' "$f"
  done < <(find "$target_dir" -type f \( -name '*.php' -o -name '*.js' -o -name '*.css' \) -print0)
}

# [internal]: у билдера был только verify_no_internal_refs — релизный ZIP, который скачивает
# пользователь, не проверялся ни на приватные термины, ни на AI-tooling. Список терминов уже
# существует (bin/private-content-terms.txt) и до сих пор был известен ТОЛЬКО pre-commit хуку:
# два конвейера публикации жили с разными наборами гейтов. Форма fail-closed — как у соседей.
# Термины читаются из файла, а не хардкодятся: у Free и PRO намеренно независимые копии.
verify_no_private_content() {
  local target_dir="$1"
  echo "Verifying no private/AI-tooling content in ${target_dir}..."

  local terms_file="${ROOT_DIR}/bin/private-content-terms.txt"
  # Три состояния различаются явно, и ни одно из них не молчит ([internal]).
  # Отсутствие или нечитаемость файла — ОТКАЗ гейта, а не «нечего искать»: пустота никогда
  # не несёт намерения. Осознанное отключение выражается ПОЛОЖИТЕЛЬНЫМ утверждением внутри
  # файла (директива ниже) — так «выключено намеренно» отличимо от «файл потеряли».
  # Публичный репозиторий получает файл именно с такой директивой: приватного содержимого
  # там нет по построению (его вычистил bin/publish-public-repo.sh), охранять нечего,
  # а список запрещённых слов сам является приватными данными и наружу не едет.
  if [[ ! -f "$terms_file" ]]; then
    echo "BLOCKED: список приватных терминов не найден: ${terms_file} — проверка не выполнена." >&2
    exit 1
  fi
  if [[ ! -r "$terms_file" ]]; then
    echo "BLOCKED: список приватных терминов нечитаем: ${terms_file} — проверка не выполнена." >&2
    exit 1
  fi

  local declared_off
  declared_off="$(rg -c '^# gate: not-applicable reason=' "$terms_file")" || declared_off=0
  if [[ "$declared_off" != "0" ]]; then
    echo "NOTE: private-content gate отключён объявлением в ${terms_file}:" >&2
    rg -n '^# gate: not-applicable reason=' "$terms_file" >&2
    return 0
  fi

  local pattern
  # Значащие термины: без комментариев и пустых строк. Файл из одних комментариев или
  # переводов строк — это НЕ «нечего искать», а отсутствие списка, то есть отказ.
  pattern="$(rg -v '^\s*(#|$)' "$terms_file" | paste -sd'|' -)" || pattern=""
  if [[ -z "$pattern" ]]; then
    echo "BLOCKED: в ${terms_file} нет ни одного значащего термина и нет объявления об отключении — проверка не выполнена." >&2
    exit 1
  fi
  # Никаких приватных паттернов в самом скрипте: он публикуется, и захардкоженный список
  # был бы утечкой ровно того, что охраняет ([internal] — первая версия этой функции этим
  # и грешила). Всё приватное живёт в одном месте — в файле терминов, который наружу не едет.

  local hits rc=0
  hits="$(rg -ni "$pattern" "$target_dir" --glob '!**/vendor/**' --glob '!**/*.mo' --glob '!**/*.min.js' --glob '!**/*.map')" || rc=$?
  if [[ $rc -eq 0 ]]; then
    echo "BLOCKED: private content found in staged artifact:" >&2
    echo "$hits" >&2
    exit 1
  fi
  if [[ $rc -ne 1 ]]; then
    echo "BLOCKED: private-content sweep не выполнен (rg exit ${rc}) над ${target_dir} — сборка остановлена." >&2
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
    echo "BLOCKED: AI-tooling sweep не выполнен (rg exit ${ai_rc}) над ${target_dir} — сборка остановлена." >&2
    exit 1
  fi
}

verify_no_internal_refs() {
  local target_dir="$1"
  echo "Verifying no internal process metadata remains in ${target_dir}..."
  local hits
  # merge-resolve: обе стороны исключали vendor; '!**/vendor/**' проверен на абсолютном
  # \$target_dir (glob матчится по полному пути), '!vendor/' на нём не срабатывал.
  # fail-closed ([internal]): rc захватывается явно — `|| true` не различал «совпадений нет»
  # (rg exit 1) и «искать нечем» (exit 2), из-за чего отказ сканера печатал PASS. Та же
  # форма, что у assert_stage_clean ниже ([internal]).
  local rc=0
  # BUILD_INFO исключается ТОЛЬКО в test-сборке: там он намеренно несёт source_root и branch
  # (диагностика «из какого дерева собрано», [internal]), а имя package-ветки содержит
  # тикет-код. Test-артефакт едет на стенд, а не наружу. В release-сборке этих двух полей
  # в файле нет (см. write_build_info), поэтому BUILD_INFO проверяется наравне со всем —
  # исключение не переносится на публикуемый артефакт ([internal]).
  local build_info_glob=()
  [[ "$BUILD_KIND" != "release" ]] && build_info_glob=(--glob '!BUILD_INFO')
  # [internal]: сужение по --type php/js/css оставляло вне проверки .txt/.neon/.xml/.json/.md —
  # публикуемые файлы, в которых внутренние маркеры реально лежали (bin/i18n-baseline-plathix.txt,
  # phpstan-baseline.neon, phpcs.xml.dist). Охват теперь определяется составом стейджа, а не
  # набором типов; исключения — только бинарные/производные артефакты, каждое названо явно.
  hits="$(rg -n 'PLX-[A-Z0-9]+(-[A-Za-z0-9]+)*|DEBT-[0-9]+|issue #[0-9]+|[internal]|[internal]|[internal]|[internal]' "$target_dir" --glob '!**/vendor/**' --glob '!**/*.mo' --glob '!**/*.min.js' --glob '!**/*.map' "${build_info_glob[@]}")" || rc=$?
  if [[ $rc -eq 0 ]]; then
    echo "BLOCKED: internal process metadata survived export sweep:" >&2
    echo "$hits" >&2
    exit 1
  fi
  if [[ $rc -ne 1 ]]; then
    echo "BLOCKED: export sweep не выполнен (rg exit ${rc}) над ${target_dir} — сборка остановлена." >&2
    exit 1
  fi
}

stage_test_i18n() {
  # rsync выше скопировал СТАРУЮ languages/ из рабочего дерева (test-режим не
  # регенерирует её там) — перезаписываем актуальными переводами из temp-регена.
  # I18N_TEST_STAGE_DIR остаётся живой до purge_build_only_role() (после
  # assert_i18n_complete(), которая тоже читает эту папку) — [internal].
  # release-режим не устанавливает I18N_TEST_STAGE_DIR — `return` без явного кода тогда
  # наследует статус [[ ]] (false=1), и под `set -e` это молча валит весь build-скрипт
  # (return 1 из последней команды stage_source_tree()). Явный `return 0` — не баг-фикс
  # поведения, только честный успешный exit code для этого legitimate early-return.
  [[ -n "${I18N_TEST_STAGE_DIR:-}" ]] || return 0
  rsync -a --delete "${I18N_TEST_STAGE_DIR}/" "${SRC_STAGE_DIR}/languages/"
}

# Роль build-only: файл нужен во время сборки, но не в архиве ([internal], [internal]).
# Вызывается ПОСЛЕ sync_runtime_vendor() — composer install читает composer.json, удалить
# его раньше значит сломать установку vendor.
#
# Здесь же схлопнута прежняя cleanup_test_i18n(): временный каталог тест-словарей — это
# тот же класс «нужно во время сборки, не нужно в архиве». Отдельных функций под каждый
# такой файл больше не заводим — именно из этой развилки выросли десять заплаток #597.
purge_build_only_role() {
  # Временный каталог тест-словарей ([internal]): живёт вне стейджа и только в
  # test-режиме, поэтому не выражается путём внутри архива и не лежит в ROLE_BUILD_ONLY_PATHS.
  # Тот же set -e/bare-return риск, что в stage_test_i18n() — явная проверка обязательна.
  if [[ -n "${I18N_TEST_STAGE_DIR:-}" ]]; then
    rm -rf "$I18N_TEST_STAGE_DIR"
  fi

  (( ${#ROLE_BUILD_ONLY_PATHS[@]} )) || return 0
  local relative
  for relative in "${ROLE_BUILD_ONLY_PATHS[@]}"; do
    # Роль чистится в обоих стейджах: SRC_STAGE_DIR — источник для assemble_release_tree,
    # STAGE_DIR — то, что реально уезжает в архив. Пустой/отсутствующий путь не ошибка:
    # роль может быть объявлена для файла, которого в этой сборке нет.
    rm -rf "${SRC_STAGE_DIR:?}/${relative}" "${STAGE_DIR:?}/${relative}"
  done
}

sync_runtime_vendor() {
  # [internal]: раньше здесь была двухветочная логика (cp -a с диска разработчика, если
  # vendor/ уже содержит все пакеты из lock — иначе composer install с нуля). Дыра: проверка
  # смотрела только на существование директории пакета, не на версию — устаревшая локальная
  # версия молча копировалась в staging без единой ошибки (тот же класс бага, что #195, только
  # на уровне версии, а не classmap). Три независимых skeptic pass (Architecture/Ops
  # Support/QA) дали fail по этой логике и рекомендовали unify на PRO-паттерн
  # (bin/build-pro-zip.sh install_prod_vendor()): всегда composer install с нуля по lock —
  # единственный источник истины, без зависимости от состояния диска разработчика.
  # composer.lock уже присутствует в $SRC_STAGE_DIR через обычный rsync stage_source_tree()
  # (не в exclude-списке) — install-from-lock срабатывает сразу, без доп. копирования.
  #
  # [internal]: --no-scripts --no-plugins — установка при сборке не исполняет НИКАКОЙ код:
  # ни event-хуки root composer.json, ни composer-плагины из allow-plugins. Сегодня оба
  # флага no-op (в scripts только ручные команды, prod-плагинов в lock нет) — это замок на
  # будущее: появление легитимного prod composer-plugin (напр. composer/installers) должно
  # стать ЯВНЫМ решением с пересмотром этих флагов, а не тихим включением стороннего кода
  # в каждую сборку. Снятие флагов без такого решения = регресс; парный гейт —
  # bin/test-build-vendor-sync.sh (кейс «install flags»).
  composer install --working-dir="$SRC_STAGE_DIR" --no-dev --no-scripts --no-plugins -o >"$COMPOSER_LOG" 2>&1 || {
    echo "composer install (runtime vendor) провалился — лог ниже:" >&2
    cat "$COMPOSER_LOG" >&2
    exit 1
  }
}

scope_runtime_vendor() {
  # [internal] ([internal]): изолировать сторонний runtime-vendor под префикс
  # `Plathix\Vendor`, чтобы чужая копия `enshrined/svg-sanitize` (FileBird Pro 0.15.4,
  # тема Bricks 0.14.0 — живые примеры) не подменяла нашу 0.22.0 и наоборот. Причина
  # подмены: глобальный namespace + prepend-регистрация автолоадеров у каждого плагина;
  # выигрывает загруженный последним, а темы грузятся после всех плагинов.
  #
  # Работаем ПОСЛЕ sync_runtime_vendor() (полный prod-vendor уже стоит с нуля по lock) и
  # ДО assemble_release_tree() (иначе правки не попадут в архив).
  #
  # fail-fast, а не тихий пропуск: молча собранный ZIP без изоляции выглядит нормальным
  # и уезжает в релиз. Это ровно тот no-op-паттерн, который этот же пакет вычищает из
  # bin/build-pro-zip.sh.
  local scoper="$ROOT_DIR/vendor/bin/php-scoper"
  if [[ ! -x "$scoper" ]]; then
    echo "php-scoper не найден ($scoper) — изоляция vendor невозможна, сборка остановлена." >&2
    echo "Установите dev-зависимости: composer install" >&2
    exit 1
  fi

  local scoped_dir="${STAGE_ROOT}/scoped"
  rm -rf "$scoped_dir"

  # --working-dir = стейдж: относительные пути finders ('vendor/enshrined', 'src') из
  # scoper.inc.php разрешаются относительно него, то есть префиксуется именно то дерево,
  # которое поедет в архив, а не рабочая копия разработчика.
  "$scoper" add-prefix \
    --config="$ROOT_DIR/scoper.inc.php" \
    --working-dir="$SRC_STAGE_DIR" \
    --output-dir="$scoped_dir" \
    --force --quiet >>"$COMPOSER_LOG" 2>&1 || {
    echo "php-scoper провалился — лог ниже:" >&2
    cat "$COMPOSER_LOG" >&2
    exit 1
  }

  # Возвращаем префиксованные деревья на место исходных.
  local sub
  for sub in src vendor; do
    if [[ -d "$scoped_dir/$sub" ]]; then
      rm -rf "${SRC_STAGE_DIR:?}/$sub.pre-scope"
      mv "$SRC_STAGE_DIR/$sub" "$SRC_STAGE_DIR/$sub.pre-scope"
      # scoper выдаёт только обработанные поддеревья (для vendor — лишь то, что было в
      # finders), поэтому исходное дерево не выбрасываем, а накладываем результат поверх.
      mv "$SRC_STAGE_DIR/$sub.pre-scope" "$SRC_STAGE_DIR/$sub"
      cp -a "$scoped_dir/$sub/." "$SRC_STAGE_DIR/$sub/"
    fi
  done
  rm -rf "$scoped_dir"

  sync_scoped_autoload_registry
}

sync_scoped_autoload_registry() {
  # Composer строит автолоад из РЕЕСТРА vendor/composer/installed.json, а не из файлов
  # пакетов на диске. php-scoper обновляет composer.json ВНУТРИ пакета, но не реестр —
  # без этой синхронизации dump-autoload зарегистрирует несуществующий глобальный
  # namespace, и артефакт будет нерабочим (доказано прогоном SVGISO-101).
  # Тот же класс дефекта, что чинил [internal] ([internal]).
  #
  # Источник истины — composer.json самих пакетов после scoper, а не захардкоженный
  # список: добавление нового пакета в finders подхватится автоматически.
  php -r '
    $stage = $argv[1];
    $registry = $stage . "/vendor/composer/installed.json";
    if (!is_file($registry)) { fwrite(STDERR, "installed.json не найден: $registry\n"); exit(1); }
    $data = json_decode(file_get_contents($registry), true);
    if (!is_array($data)) { fwrite(STDERR, "installed.json нечитаем\n"); exit(1); }
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
    fwrite(STDERR, "scoped registry: синхронизировано пакетов = $patched\n");
  ' "$SRC_STAGE_DIR" 2>>"$COMPOSER_LOG" || {
    echo "синхронизация installed.json провалилась — лог ниже:" >&2
    cat "$COMPOSER_LOG" >&2
    exit 1
  }

  # Пересобираем автолоад поверх префиксованного дерева и синхронизированного реестра.
  composer dump-autoload --working-dir="$SRC_STAGE_DIR" --no-dev -o >>"$COMPOSER_LOG" 2>&1 || {
    echo "dump-autoload после scoper провалился — лог ниже:" >&2
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
  # [internal]: не даём в ZIP уехать новому непереведённому тексту
  # (найдено вживую на стенде — 'Folder field in Quick Edit'/'More power in PRO' не
  # переведены). .po исключён из архива (COMMON_EXCLUDES "*.po" — source-файл, не
  # нужен рантайму WP), поэтому проверяем исходный *.po отдельно от архива.
  # Release: build_i18n() пересобрал languages/*.po ПРЯМО в рабочем дереве — проверяем
  # его там (тот же [internal] контракт, что и раньше).
  # Test: build_i18n() регенерировал во временную папку ([internal], рабочее дерево
  # не тронуто) — до stage_test_i18n() эта папка ещё жива, проверяем её оттуда.
  # Существующий долг в bin/i18n-baseline-plathix.txt не блокирует — гасится отдельным пакетом.
  local po_source="languages/plathix-ru_RU.po"
  if [[ "$BUILD_KIND" != "release" && -n "${I18N_TEST_STAGE_DIR:-}" ]]; then
    po_source="${I18N_TEST_STAGE_DIR}/plathix-ru_RU.po"
  fi
  bash bin/check-i18n-completeness.sh "$po_source" bin/i18n-baseline-plathix.txt
}

stamp_release_version() {
  # Штампуем ЧИСТЫЙ semver (BASE_VERSION), НЕ BUILD_VERSION: поле Version:/Stable tag —
  # WP-facing (админка + каталог WP.org), там timestamp = мусор. Timestamp живёт только
  # в имени zip. Правит staged-копии, git-файлы не трогаются ([internal] / симметрия [internal]).
  # PLATHIX_VERSION живёт в includes/bootstrap.php ([internal] PHP-guard split, plathix.php
  # теперь несёт только докблок + version_compare-guard, не константы) — Version: в докблоке
  # остаётся в plathix.php, поэтому патчатся оба файла раздельно.
  export BASE_VERSION
  sed -i -E "s#^( \\* Version:[[:space:]]*).*\$#\\1${BASE_VERSION}#" "$STAGE_DIR/plathix.php"
  sed -i -E "s#define\\([[:space:]]*'PLATHIX_VERSION'[[:space:]]*,[[:space:]]*'[^']+'[[:space:]]*\\);#define('PLATHIX_VERSION', '${BASE_VERSION}');#" "$STAGE_DIR/includes/bootstrap.php"
  perl -0pi -e 's/^(Stable tag:\s*).*$/$1$ENV{BASE_VERSION}/m' "$STAGE_DIR/readme.txt"
}

write_build_info() {
  # BUILD_INFO живёт внутри артефакта (не только в имени zip), чтобы после деплоя
  # можно было прочитать с диска, какой именно коммит реально задеплоен, без
  # ручного grep по содержимому файлов ([internal], [internal] [internal]).
  local commit dirty
  commit="$(git rev-parse HEAD)"
  # -uno: untracked файлы (напр. чужой параллельный spec/ WIP) не входят в HEAD и не
  # создают несоответствия между commit hash и реальным деревом — только tracked-грязь
  # (modified/staged) делает BUILD_INFO's commit недостоверным ([internal] fix,
  # найдено при первой реальной stand-сборке: untracked spec/ ложно блокировал release).
  if [[ -n "$(git status --porcelain -uno)" ]]; then
    dirty=true
  else
    dirty=false
  fi
  # Release-сборка (публикуемая на WP.org) не должна уезжать с незакоммиченным кодом,
  # о котором знает только bool-флаг dirty без diff — иначе supply-chain audit trail
  # (сверка shipped-кода с публичным GitHub-коммитом) рвётся именно тогда, когда нужнее
  # всего ([internal], найдено wp-security-skeptic при паковке [internal]).
  # Test-сборка (стенд/дев-проверка) dirty остаётся разрешён — нужен для дебага.
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
    # [internal]: из какого дерева и какой ветки собрано. Инцидент 2026-08-24 (в артефакт
    # попал файл из чужой рабочей копии) опознавался только приёмкой на стенде — по
    # BUILD_INFO это видно сразу: `unzip -p <zip> plathix/BUILD_INFO`.
    # Пишем basename, не полный путь: имя каталога различает деревья, а раскрывать
    # локальную файловую структуру разработчика в публичном ZIP незачем.
    # basename, не полный путь: имя каталога различает деревья, а раскрывать локальную
    # файловую структуру в публичном ZIP незачем. Для package-worktree (basename
    # '.worktree') берём и родителя — иначе имя пакета теряется и строка бесполезна.
    # [internal]: source_root и branch пишутся ТОЛЬКО в test-сборке. Имя package-ветки и
    # basename worktree несут тикет-код (`PLX-…`) — ровно тот класс внутренних маркеров,
    # который verify_no_internal_refs обязан ловить. В release-артефакте, уезжающем на
    # WP.org, эти два поля не нужны: коммита достаточно для audit trail, а диагностика
    # «из какого дерева собрано» ([internal]) нужна на стенде, то есть в test-сборке.
    if [[ "$BUILD_KIND" != "release" ]]; then
      src_name="$(basename "$ROOT_DIR")"
      [[ "$src_name" == .* ]] && src_name="$(basename "$(dirname "$ROOT_DIR")")/${src_name}"
      printf 'source_root=%s\n' "$src_name"
      printf 'branch=%s\n' "$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
    fi
  } > "$STAGE_DIR/BUILD_INFO"
}

# Права в архиве не должны зависеть от машины и рабочего дерева сборщика (DH-103,
# [internal]). Стейдж наполняется через `rsync -a`, а `-a` переносит режим ИСХОДНИКА с
# диска — не то, что записано в git. Доказано сборкой одного и того же коммита из двух
# деревьев: в одном `src/Http/Nonce.php` и `src/Helpers/FolderHelper.php` легли в архив
# как `0600`, а `languages/` как `0700`, в другом — как `0644`/`0755`. В git оба файла
# `100644`; разошлись именно права на диске.
#
# Последствие не гипотетическое: `unzip` восстанавливает режим дословно (проверено под
# `umask 022` и `umask 077` — оба раза `600`), поэтому ручная распаковка на хостинге даёт
# файлы, нечитаемые для любого процесса кроме владельца. На наших продах не сломалось по
# совпадению — PHP там исполняется под владельцем файлов. Установка через админку не
# страдает: `unzip_file()` внутри WP нормализует права сама.
#
# Нормализуем весь стейдж непосредственно перед упаковкой — после `write_build_info` и
# всех шагов, которые ещё создают или переписывают файлы. Более раннее место не годится:
# поздний шаг вернул бы права источника.
#
# 644/755 без исключений: в поставке нет ничего исполняемого — бинарники и скрипты
# исключены из архива на входе.
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

# Список путей архива читаем ОДИН раз и кэшируем, чтобы assert-хелперы не гоняли
# `zipinfo | rg -q` в пайпе: `rg -q` закрывает пайп при первом совпадении → zipinfo
# ловит SIGPIPE → под `set -o pipefail` это ложный фейл на случайном файле.
ZIP_ENTRIES=""
load_zip_entries() {
  ZIP_ENTRIES=$( zipinfo -1 "$OUT" )
}

# Три функции ниже — fail-closed по той же схеме, что assert_stage_clean ([internal],
# расширено [internal]): `if rg …` не отличал отказ сканера (exit 2 — битый паттерн) от
# штатного ответа, поэтому у первой это читалось как «нарушений нет», а у двух других —
# как «путь на месте». Теперь любой код, кроме 0 и 1, означает, что проверка не выполнена.
assert_zip_not_contains() {
  local pattern=$1 rc=0
  rg -q "$pattern" <<<"$ZIP_ENTRIES" || rc=$?
  if [[ $rc -eq 0 ]]; then
    echo "Archive contains blocked path pattern: $pattern" >&2
    exit 1
  fi
  if [[ $rc -ne 1 ]]; then
    echo "Archive gate не выполнен (rg exit ${rc}) для blocked-паттерна: $pattern" >&2
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
    echo "Archive gate не выполнен (rg exit ${rc}) для required-пути: $path" >&2
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
    echo "Archive gate не выполнен (rg exit ${rc}) для required-паттерна: $pattern" >&2
    exit 1
  fi
}

# [internal]: стейдж обязан существовать и быть непустым в каждой точке, где сборка на
# него опирается. Без этой проверки исчезнувший каталог давал не отказ гейта, а случайное
# падение дальше по цепочке (sed не находил plathix.php) — причём уже ПОСЛЕ того, как
# гейты Guideline-5 «ничего запрещённого не нашли» в пустоте.
require_stage_present() {
  local at="$1"
  if [[ ! -d "$STAGE_DIR" ]] || [[ -z "$(find "$STAGE_DIR" -mindepth 1 -print -quit 2>/dev/null)" ]]; then
    echo "BUILD BLOCKED: stage dir is missing or empty ($STAGE_DIR) на шаге ${at} — артефакт нельзя считать проверенным." >&2
    exit 1
  fi
}

assert_scoper_autoload_safe() {
  # [internal]. `vendor/scoper-autoload.php` разрешён в whitelist (см. vendor_allow), но
  # разрешён УЗКО: он легитимен, только пока держит алиасы функций самой изолированной
  # библиотеки. Если php-scoper снова начнёт префиксовать вызовы ядра WordPress
  # (`wp_*`, `apply_filters`, `add_query_arg`, `as_*` — Action Scheduler), файл объявит
  # глобальные обёртки, ПОДМЕНЯЮЩИЕ функции ядра, и это уедет в поставку молча.
  #
  # Такое уже случилось вживую при первой сборке этого пакета: 26 алиасов, включая
  # `apply_filters()` и `wp_upload_dir()`. Тогда поймал whitelist-гейт — но после того,
  # как файл внесён в allow-список, он бы уже не поймал. Отсюда отдельная проверка.
  local scoper_autoload="$STAGE_DIR/vendor/scoper-autoload.php"
  [[ -f "$scoper_autoload" ]] || return 0

  local forbidden
  forbidden="$(rg -o "function_exists\('(wp_[a-z_]*|apply_filters|add_query_arg|home_url|trailingslashit|dbDelta|get_current_[a-z_]*|as_[a-z_]*)'\)" \
    "$scoper_autoload" -r '$1' 2>/dev/null | sort -u || true)"

  if [[ -n "$forbidden" ]]; then
    echo "SCOPER LEAK: vendor/scoper-autoload.php объявляет глобальные алиасы функций WordPress/Action Scheduler:" >&2
    echo "$forbidden" | sed 's/^/  - /' >&2
    echo "Это подменяет функции ядра в рантайме WP. Добавьте имена в 'exclude-functions' в scoper.inc.php." >&2
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
    # .local-tools / local-tools больше не выписаны литералами: они объявлены ролью
    # dev-only и добавляются в этот список автоматически ниже ([internal]).
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

  # Роли ([internal], [internal]) питают ОБА рубежа из одного источника: вход
  # (COMMON_EXCLUDES, fail-open) и выход (blocked_paths, fail-safe). Дублирование входа и
  # выхода здесь намеренное и НЕ является избыточностью: вход решает «что не копировать»,
  # выход ловит то, что вход пропустил из-за бага в фильтре. Ослаблять любой из трёх
  # механизмов запрещено (см. комментарий в stage_source_tree(), запрет wp-security-skeptic).
  # Раньше пути ролей были выписаны здесь литералами и расходились с входом при каждой
  # правке — теперь расхождение невозможно по построению.
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
  # Артефакт несёт только СКОМПИЛИРОВАННЫЕ переводы (.mo + .json).
  # Исходники .po/.pot живут в git/локально и исключены из zip (COMMON_EXCLUDES).
  # ── CTAN-402 ([internal]): Guideline-5 grep-gate поставки ────────
  # Критерий скептиков (4 раунда paradigm, 2026-08-24): в Free-zip нет ни следа механизма
  # «список типов» и ни строки, исполнимой только для платных типов. Паттерны точные:
  # generic postType-параметр JS и uninstall-скан по префиксу опций сюда не попадают.
  # [internal] (fail-open): `if rg -q …` не различал «не найдено» (exit 1) и «искать
  # негде» (exit 2 — каталога нет). Если стейдж исчезал, все шесть проверок ниже молча
  # проходили, то есть гейт WP.org-требований отключался ровно в аварийной ситуации.
  # Теперь exit 2 и любой другой ненулевой код, кроме 1, считаются отказом гейта.
  assert_stage_clean() {
    local pattern=$1 hint=$2 rc=0
    rg -q "$pattern" "$STAGE_DIR" --glob '!*.mo' --glob '!*.min.js' || rc=$?
    if [[ $rc -eq 0 ]]; then
      echo "Guideline-5 gate: staged Free tree contains forbidden pattern: $pattern ($hint)" >&2
      rg -n "$pattern" "$STAGE_DIR" --glob '!*.mo' | head -5 >&2
      exit 1
    fi
    if [[ $rc -ne 1 ]]; then
      echo "Guideline-5 gate: stage dir is missing or unreadable ($STAGE_DIR) — проверка '$pattern' не выполнена, сборка остановлена." >&2
      exit 1
    fi
  }
  assert_stage_clean 'content_types/enabled' 'фильтр-рубильник типов удалён пакетом'
  assert_stage_clean 'ContentTypeRegistry' 'реестр типов не существует во Free'
  assert_stage_clean 'plathix_post_types' 'опция принадлежит PRO'
  assert_stage_clean 'render_post_list_fragments' 'CPT-fragments уехали в PRO'
  assert_stage_clean 'PostListAdapter' 'адаптер списков записей — только PRO-бандл'
  assert_stage_clean 'render_enabled_sections' 'запертый UI удалён'
  # CEC-401: узкие паттерны — широкий 'edit' неприменим (edit.php, комментарии, тесты).
  # Защищают от возврата дубля решения об экранах в JS/PHP (CEC-101/103).
  # Паттерн покрывает и исходник, и МИНИФИКАТ: webpack переписывает
  # `runtime.screenBase !== 'edit'` в `"edit"!==e.screenBase`. Проверено мутацией
  # (возврат литерала в mount-manager.js краснит сборку). Легитимные вхождения
  # screenBase (upload+grid override, getScreenBase) паттерном не задеваются.
  assert_stage_clean '"edit"[!=]==?[a-zA-Z_$.]*screenBase' 'решение об экране приходит из PHP-конфига, не из списка литералов (CEC-101)'
  assert_stage_clean "screenBase [!=]==? 'edit'" 'то же в неминифицированном виде'
  assert_stage_clean "screen_context === 'edit'" 'Free-резолвер знает только медиатеку (CEC-103)'

  assert_zip_contains_path 'plathix/languages/plathix-ru_RU.mo'
  assert_zip_contains_pattern '^plathix/languages/plathix-[^/]+\.json$'

  local css_file
  while IFS= read -r css_file; do
    [[ -z "$css_file" ]] && continue
    assert_zip_contains_path "plathix/assets/css/${css_file}"
  done < <(find resources/css -maxdepth 1 -type f -printf '%f\n' | sort)

  # Сверяем с ЗАСТЕЙДЖЕННОЙ копией ($STAGE_DIR), не с живым $ROOT_DIR/assets/img:
  # verify обязан сверяться с тем же источником, из которого реально паковал
  # create_archive() (rsync в assemble_release_tree() → $STAGE_DIR), а не с
  # независимым live-деревом — иначе любой процесс, физически меняющий
  # assets/img во время сборки (сейчас assets/img защищён webpack keep-regex,
  # [internal]/6b90f370, но verify не должен полагаться на это как на
  # гарантию), может расхождить список ожидаемых файлов с уже собранным zip.
  # [internal]: структурный фикс класса verify != source-of-truth,
  # не привязан к конкретной причине [internal] (та причина устранена раньше, см. пакет).
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

  # 0-байтовые JS в поставке запрещены ([internal], [internal]): CSS-only entries по
  # построению не эмитят пустой JS (RemoveEmptyJsEmitPlugin, webpack.config.js), а этот
  # рубеж ловит ВОЗВРАТ класса — закоммиченная пустышка нового CSS-only entry со
  # сломанным/снятым механизмом уехала бы в архив молча (8 таких ехали с 2026-08-20,
  # не поймал ни один гейт). Это fail-рубеж, НЕ вычистка: архив не мутируется
  # (запрет класса #597).
  local empty_js
  empty_js="$(unzip -l "$OUT" 'plathix/assets/js/*.js' 2>/dev/null | awk '$1 == "0" && $NF ~ /\.js$/ {print $NF}' || true)"
  if [[ -n "$empty_js" ]]; then
    echo "EMPTY JS: 0-байтовые JS-бандлы в архиве (возврат класса #594 — CSS-only entry без подавления эмита?):" >&2
    sed 's/^/  - /' <<<"$empty_js" >&2
    exit 1
  fi

  assert_zip_contains_pattern '^plathix/assets/presets/[^/]+/preset\.plx\.md$'
  assert_zip_contains_pattern '^plathix/assets/presets/[^/]+/preview\.(png|jpg|jpeg|webp)$'

  # Версия в поле — ЧИСТЫЙ semver (BASE_VERSION), а не timestamp-мусор ([internal]).
  # Ловит регресс, если stamp отвалится или снова начнёт лепить хвост в WP-facing поле.
  # PLATHIX_VERSION — в includes/bootstrap.php ([internal] PHP-guard split), Version:
  # докблока — в plathix.php, проверяются раздельно из соответствующих файлов архива.
  local pv bs rt
  pv="$(unzip -p "$OUT" plathix/plathix.php)"
  bs="$(unzip -p "$OUT" plathix/includes/bootstrap.php)"
  rt="$(unzip -p "$OUT" plathix/readme.txt)"
  rg -q "^ \* Version:[[:space:]]*${BASE_VERSION}[[:space:]]*\$" <<<"$pv" || { echo "Version в plathix.php не равен чистому '${BASE_VERSION}' (timestamp-мусор?)" >&2; exit 1; }
  rg -qF "'PLATHIX_VERSION', '${BASE_VERSION}'" <<<"$bs" || { echo "PLATHIX_VERSION не равна чистому '${BASE_VERSION}'" >&2; exit 1; }
  rg -q "^Stable tag:[[:space:]]*${BASE_VERSION}[[:space:]]*\$" <<<"$rt" || { echo "Stable tag в readme.txt не равен чистому '${BASE_VERSION}'" >&2; exit 1; }

  # WHITELIST-GATE ([internal]): security-инвариант «в shipped-ZIP ТОЛЬКО нужное». Blacklist-excludes
  # выше fail-open (забыл имя → секрет уехал; так секретные env-файлы утекли на публичный прод). Здесь
  # fail-safe: КАЖДАЯ запись-файл обязана матчнуться на allow, иначе билд падает (цена ошибки — сборка
  # встала, не утечка). Разобрано WP-Security + WP-QA скептиками (spec/[internal]).
  #
  # Записи-каталоги (trailing '/') скипаются: пустой dir секрета не несёт, файлы внутри проверяются
  # своими записями (zipinfo выдаёт пустые builds//plathix/plathix/ как dir-записи — иначе ложный фейл).
  #
  # allow: exact корневые файлы + runtime-каталоги по префиксу + runtime-vendor ПО СОДЕРЖИМОМУ
  # (пакеты из composer.lock, как sync_runtime_vendor — НЕ голый ^vendor/, иначе лишний dev-пакет
  # прошёл бы = дыра). vendor/composer/ + vendor/autoload.php — служебные, разрешены явно.
  local allowed_exact=(
    'plathix/plathix\.php'
    'plathix/readme\.txt'
    'plathix/uninstall\.php'
    'plathix/license\.txt'
    'plathix/BUILD_INFO'
    # composer.json остаётся в allow как РАЗРЕШЁННЫЙ, но не обязательный: он объявлен
    # ролью build-only и удаляется из стейджа до упаковки ([internal]). Allow-список
    # отвечает на «что можно», а не «что должно быть» — требование наличия живёт в
    # assert_zip_contains_path() ниже, и composer.json туда намеренно не входит.
    'plathix/composer\.json'
  )
  local allowed_dir_prefixes=(
    'plathix/languages/'
    'plathix/assets/'
    'plathix/src/'
    'plathix/views/'
    'plathix/includes/'
  )
  # runtime-vendor: собрать разрешённые vendor-префиксы из composer.lock packages (тот же источник,
  # что sync_runtime_vendor) + служебные автолоад-пути composer.
  # scoper-autoload.php — служебный автолоад, который генерирует php-scoper рядом с
  # vendor/autoload.php ([internal], scope_runtime_vendor()). Он нужен рантайму: держит
  # алиасы функций, объявленных ВНУТРИ изолированной библиотеки и уехавших под префикс
  # (сейчас — `sysexit` из svg-sanitize/src/svg-scanner.php).
  # Разрешение узкое и намеренно не покрывает алиасы функций WordPress: если scoper снова
  # начнёт префиксовать вызовы ядра (wp_*, apply_filters, as_*), файл разбухнет, но гейт
  # его уже пропустит — поэтому содержимое файла отдельно проверяется ниже.
  local vendor_allow=( 'plathix/vendor/composer/' 'plathix/vendor/autoload\.php' 'plathix/vendor/scoper-autoload\.php' )
  local pkg
  while IFS= read -r pkg; do
    [[ -z "$pkg" ]] && continue
    vendor_allow+=( "plathix/vendor/${pkg}/" )
  done < <(php -r '$lock = json_decode(file_get_contents("'"$ROOT_DIR"'/composer.lock"), true); foreach (($lock["packages"] ?? []) as $p) { echo preg_quote($p["name"], "/"), PHP_EOL; }')

  local entry matched
  while IFS= read -r entry; do
    [[ -z "$entry" ]] && continue
    [[ "$entry" == */ ]] && continue          # dir-запись — скип (файлы внутри проверяются отдельно)
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
      echo "ARTIFACT LEAK: '${entry}' не в allow-списке shipped-ZIP (DEBT-100 whitelist-gate)" >&2
      exit 1
    fi
  done <<<"$ZIP_ENTRIES"

  # CONTENT AUTHENTICITY GATE ([internal]): whitelist выше доказывает только "те ли пути
  # внутри" — не "то ли в этих файлах лежит". Верное имя пути с чужим/устаревшим
  # содержимым (инцидент 2026-08-24, гонка за общий staging-каталог, [internal]) проходил
  # незамеченным. Сверяем tracked-файлы с git HEAD (не с $STAGE_DIR — тот же процесс, что
  # паковал архив, self-referential gate, отвергнут security+architecture skeptic pass при
  # паковке [internal]), а не-tracked generated-пути — с explicit allowlist.
  #
  # generated-allowlist: НЕ переиспользует allowed_dir_prefixes целиком — тот массив
  # смешивает generated-каталоги (languages/, assets/) с обычными tracked PHP-каталогами
  # (src/, views/, includes/), которые обязаны проходить content-сверку, а не быть
  # пропущены. Переиспользуем только vendor_allow (уже узкий, только composer-пакеты) —
  # он корректно составляет generated-vendor-allowlist для ОБЕИХ целей (path whitelist
  # выше и content-authenticity здесь), расхождение между ними невозможно по построению.
  local artauth_generated_allowlist=(
    "languages"
    "assets"
    "BUILD_INFO"
  )
  local vp
  for vp in "${vendor_allow[@]}"; do
    artauth_generated_allowlist+=( "${vp#plathix/}" )
  done
  # снять regex-экранирование (\.) и trailing-regex-якоря — generated_allowlist сравнивает
  # литеральные строки, а vendor_allow писался как regex-фрагмент для path whitelist-gate.
  artauth_generated_allowlist=( "${artauth_generated_allowlist[@]%/}" )
  artauth_generated_allowlist=( "${artauth_generated_allowlist[@]//\\/}" )

  # transform-list: точечные легитимные пост-коммитные замены НА КОНКРЕТНОМ пути —
  # stamp_release_version() (строки 782-793). Три файла, три замены. Формат правила:
  # "путь|regex_до|regex_после" — regex применяется к git-blob перед сравнением с
  # артефактом (см. bin/lib/verify-artifact-authenticity.sh).
  local artauth_transform_rules=(
    "plathix.php|^( \\* Version:[[:space:]]*).*\$|\\1${BASE_VERSION}"
    "includes/bootstrap.php|define\\('PLATHIX_VERSION', '[^']+'\\);|define('PLATHIX_VERSION', '${BASE_VERSION}');"
    "readme.txt|^(Stable tag:[[:space:]]*).*\$|\\1${BASE_VERSION}"
  )

  # Вторая (широкая) легитимная трансформация — strip_internal_comment_refs() заменяет
  # МАРКЕР (PLX-.../DEBT-.../issue #N/...) на "[internal]" в комментариях ЛЮБОГО PHP/JS/CSS
  # файла, не точечно. Не выражается как transform_rule (не привязано к одному пути) —
  # передаём helper'у тот же $MARKER_PATTERN, что strip_internal_comment_refs уже
  # экспортировал (строка 462), чтобы не дублировать regex вторым источником правды.
  #
  # Третья легитимная трансформация — весь src/**/*.php легитимно проходит AST-репринт
  # через php-scoper add-prefix ([internal], обнаружено запуском реальной сборки: namespace
  # exclude есть, но prettyprinter меняет форматирование байт-в-байт даже без семантических
  # изменений). Байтовое сравнение с прямым git blob для src/ гарантированно false-positive.
  # Helper повторно вызывает тот же php-scoper на git HEAD (не на $STAGE_DIR — это дало бы
  # self-referential gate, отвергнутый security+architecture skeptic pass на паковке) в
  # изолированном временном каталоге и сверяется с результатом.
  verify_artifact_content_authenticity "$ROOT_DIR" "$STAGE_DIR" \
    artauth_transform_rules artauth_generated_allowlist "${MARKER_PATTERN:-}" \
    "$ROOT_DIR/vendor/bin/php-scoper" "$ROOT_DIR/scoper.inc.php" "src"
}

# Стабильный алиас на последнюю сборку — чтобы не искать нужный timestamp-zip в builds/.
# Имена latest-release.zip / latest-test.zip — те же, что делает bin/build-stand-artifact.sh:41
# (единый алиас, НЕ плодим второй набор plathix-latest*.zip).
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
assemble_release_tree
# [internal]: BUILD_INFO создаётся ДО гейта — раньше он писался после и потому не
# проверялся ни им, ни (до GFC-201) по типу файла, хотя уезжает внутри артефакта.
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

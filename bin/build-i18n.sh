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

# Fail-fast на i18n-command старше v2.7 ([internal]). У таких версий `wp i18n make-json`
# имеет флаг `--purge` со значением по умолчанию true — после генерации JSON команда
# УДАЛЯЕТ JS-записи (msgid+msgstr) из .po. Потеря необратима: следующая сборка вставит
# msgid заново с пустыми msgstr, и JSON интерфейса уедут в релиз без переводов.
# Наличие `--purge` в справке = детектор версии: в v2.7+ флаг убран апстримом полностью
# (i18n-command PR #472 "Make PO files the source of truth"), см. build_json() ниже.
# Тихий `--no-purge` для legacy отклонён (wp-ops-support skeptic, пакет [internal]):
# он чинил бы только make-json, оставляя make-pot/make-mo того же старого стека
# непроверенными, и легализовал бы дрейф сред. WP_CLI_PAGER=cat — справка не должна
# звать интерактивный пейджер (в CI-контейнере less отсутствует).
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
  # [internal]: временно — диагностика расхождения line-wrap между CI и локальной
  # машиной для одной длинной msgid-строки (прогоны 33639560164, 33644353674). Убрать
  # после того, как причина найдена и зафиксирована.
  echo "DEBUG: $(msgmerge --version | head -1)" >&2
  echo "DEBUG: PHP $("$WP_CLI_BIN" cli version --allow-root 2>&1 | head -1)" >&2
}

build_pot() {
  # --slug: без него wp-cli берёт basename текущей рабочей директории —
  # внутри git worktree (spec/<PACK>/.worktree) это ломает Report-Msgid-Bugs-To.
  "$WP_CLI_BIN" i18n make-pot . "$POT_FILE" \
    --slug="$TEXT_DOMAIN" \
    --domain="$TEXT_DOMAIN" \
    --include="$SOURCE_PATHS" \
    --exclude="$EXCLUDE_PATHS"
}

# Вызывается ПОСЛЕ strip_volatile_metadata() — раньше стояла
# сразу после update_po() и компилировала .mo из ещё "грязного" .po (со свежим
# POT-Creation-Date от msgmerge), делая .mo неидемпотентным даже когда .po уже зачищен.
# .mo — лист в пайплайне: ни build_json()/merge_bundle_json() (читают только .po/.json),
# ни purge_obsolete() (читает/пишет только .po) не открывают .mo — переносить компиляцию
# в конец безопасно относительно них (см. историю пакета i18n-date, wp-architecture-skeptic
# verdict conditional + regression-скрипт test-build-i18n-mo-not-upstream.sh ниже).
build_mo() {
  "$WP_CLI_BIN" i18n make-mo "$LANG_DIR/"
}

update_po() {
  # wp i18n update-po сбрасывает существующие msgstr (баг WP-CLI) — используем GNU msgmerge.
  local po
  for po in "$LANG_DIR"/*.po; do
    [[ -f "$po" ]] || continue
    # --backup=off: без него msgmerge оставляет рядом резервную копию `<файл>.po~`
    # (проверено прогоном). В PRO такой `.po~` (88 КБ) доехал до публичного
    # архива — он рождается ВНУТРИ временного каталога сборки, уже после фильтра
    # `git ls-files`, и allow-списками на входе не ловится. Лечится в месте порождения.
    # --no-wrap: без него GNU gettext переносит длинные msgid/msgstr по эвристике,
    # зависящей от ВЕРСИИ gettext (обнаружено release-zip прогоном 33639560164, причина
    # добита прогоном 33742829801: локальный 0.21 и CI 0.23.1 выбирают разную точку
    # переноса одной и той же строки "This preset uses... bin/convert-preset-format.php."
    # — содержимое идентично, ломался только byte-level assert_i18n_git_clean).
    # ВАЖНО ([internal]): прежние попытки --width=0/--width=100000 ЗДЕСЬ не работали,
    # потому что финальную форму файлов задаёт не msgmerge, а msgattrib-вызовы ниже по
    # пайплайну (strip_volatile_metadata и purge_obsolete переписывают файл целиком
    # своим дефолтным версиозависимым wrap) — --no-wrap обязан стоять на ВСЕХ трёх.
    msgmerge --update --backup=off --no-fuzzy-matching --quiet --no-wrap "$po" "$POT_FILE"
  done
}

build_json() {
  find "$LANG_DIR" -maxdepth 1 -type f -name 'plathix-*.json' -delete
  # make-json больше не удаляет JS-строки из PO (--purge убран из самой команды
  # апстримом в i18n-command v2.7.0, PR #472 "Make PO files the source of truth" —
  # PO/JS-location теперь сохраняются всегда, без флага). Прежний --no-purge был
  # добавлен по ошибочной атрибуции причины бага и падает "unknown --purge
  # parameter" на v2.7+/v3 (2026-08-20).
  #
  # Post-condition guard ([internal], [internal]): по тому же контракту v2.7+
  # make-json ВООБЩЕ не пишет в .po — любая мутация .po этим шагом означает
  # purge-класс потери переводов (или новый неизвестный класс того же рода) и обязана
  # ронять сборку немедленно, пока переводы ещё восстановимы из snapshot/git.
  # Побайтовое сравнение вместо парсинга "JS-записей" намеренно: честное удаление
  # строки из исходников происходит в update_po (msgmerge помечает #~ obsolete) ДО
  # этого шага, поэтому здесь любой diff = дефект, эвристика не нужна.
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
      echo "build_json: 'wp i18n make-json' мутировал $(basename "$po") — по контракту i18n-command v2.7+ этот шаг не пишет в .po ([internal], purge-класс потери переводов):" >&2
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

# Сливает per-source JSON от make-json в per-bundle JSON под md5 зарегистрированного
# src бандла ([internal]). WP load_script_textdomain() ищет файл по
# md5(относительный-путь-ЗАРЕГИСТРИРОВАННОГО-src), а make-json именует по
# md5(пути-ИСХОДНИКА) из #:-location в .po — без слияния пересечение имён пустое и
# ни один handle не получает JS-переводы. Обобщение PRO merge_bundle_json (карта
# бандлов вместо хардкода одного бандла; sorted+детерминированный dump — иначе
# assert_i18n_git_clean флапает; fail-loud вместо тихого пропуска — иначе новый
# entry со строками молча воспроизводит #584; локали из *.po вместо хардкода).
# Строго после build_json (читает его вывод) и до purge_obsolete/strip_volatile_metadata.
merge_bundle_json() {
  python3 - "$LANG_DIR" "$TEXT_DOMAIN" <<'PY'
import glob
import hashlib
import json
import os
import re
import sys

lang_dir, domain = sys.argv[1], sys.argv[2]

# Ключ — зарегистрированный src бандла относительно корня плагина: ровно та строка,
# которую WP хеширует в load_script_textdomain(). Значение — префиксы исходников,
# чьи строки webpack собирает в этот бандл (сверять с entry в webpack.config.js).
# Бандлы без переводимых строк (sidebar.js, admin-ui/system-info.js) не входят
# намеренно: make-json не создаёт для них JSON, пустой merged был бы мусором.
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
            continue  # merged-файл: build_json стирает всё перед make-json, это страховка
        per_source.append((j, src, d))

    for bundle_rel, prefixes in sorted(BUNDLE_MAP.items()):
        matched = [(j, src, d) for (j, src, d) in per_source if src.startswith(prefixes)]
        if not matched:
            continue  # у бандла легитимно может не быть строк в этой локали — не ошибка
        merged, header = {}, None
        for j, src, d in matched:
            msgs = d.get('locale_data', {}).get('messages', {})
            if header is None and msgs.get(''):
                header = msgs['']
            merged.update({k: v for k, v in msgs.items() if k})
        if header is None:
            # header '' обязателен: print_translations делает localeData[""].domain=...,
            # без него TypeError в браузере; Plural-Forms критичен для _n().
            print(
                f'merge_bundle_json: ни один источник бандла {bundle_rel} ({locale}) '
                'не несёт header "" — сборка перевода некорректна',
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
        print(f'merge_bundle_json: {target} <- {len(matched)} источников, {len(merged) - 1} строк')

    covered = tuple(p for prefixes in BUNDLE_MAP.values() for p in prefixes)
    for j, src, d in per_source:
        if not src.startswith(covered):
            print(
                f'merge_bundle_json: {j} (source={src}) не покрыт BUNDLE_MAP — '
                'добавь бандл/префикс в карту и вызов wp_set_script_translations',
                file=sys.stderr,
            )
            failed = True

sys.exit(1 if failed else 0)
PY
}

# Убирает волатильный git-шум ПОСЛЕДНИМ шагом пайплайна.
#
# Location (#: комментарии) — ТОЛЬКО из POT, не из PO:
#  - POT пересоздаётся целиком при каждой сборке; его location = чистый шум.
#  - PO должен СОХРАНЯТЬ location: make-json читает их, чтобы понять к какому JS-файлу
#    относится каждая строка и как назвать JSON-файл. Без location из PO → JSON пустые
#    на следующей сборке (msgmerge/update-po не копирует переводы в новые location-free записи).
#
# POT-Creation-Date — из ОБОИХ, POT и PO:
#  - POT — источник даты, пересоздаётся целиком при каждой сборке, дата = чистый шум.
#  - PO ЗЕРКАЛИТ ту же дату: msgmerge --update копирует POT-Creation-Date из .pot в .po
#    заголовок на каждый прогон, даже если ни одна строка перевода не изменилась — та же
#    волатильность, что и в .pot, просто на шаг позже. Не зачищать её в .po оставляло
#    `assert_i18n_git_clean()` непроходимым в принципе: regen делал .po "грязным" даже
#    без реальных изменений перевода.
#  - PO-Revision-Date НЕ трогается — эта дата содержательна (реальное время последнего
#    изменения перевода, управляется msgmerge только при фактическом diff'е msgstr).
#    Sed-паттерн ниже якорится на литерал `POT-Creation-Date:`, синтаксически не
#    пересекается с `PO-Revision-Date:` — трогать её нечем.
#
# Строго после build_json (PO уже дочитан для JSON-location к этому моменту) и ДО
# build_mo (переставлена ПОСЛЕ этой функции именно
# затем, чтобы .mo компилировался уже из зачищенного .po, а не наследовал грязную дату
# в скомпилированный бинарник).
# msgattrib переводы НЕ теряет; POT-Creation-Date он не трогает — обнуляем отдельным sed.
strip_volatile_metadata() {
  local tmp po
  if [[ -f "$POT_FILE" ]]; then
    tmp="${POT_FILE}.strip.tmp"
    # --no-wrap: msgattrib переписывает файл целиком и без него применяет свой
    # версиозависимый wrap, сводя на нет --no-wrap у msgmerge ([internal]).
    msgattrib --no-wrap --no-location "$POT_FILE" -o "$tmp"
    mv "$tmp" "$POT_FILE"
    sed -i 's/^"POT-Creation-Date:[^\\]*\\n"$/"POT-Creation-Date: \\n"/' "$POT_FILE"
  fi
  for po in "$LANG_DIR"/*.po; do
    [[ -f "$po" ]] || continue
    sed -i 's/^"POT-Creation-Date:[^\\]*\\n"$/"POT-Creation-Date: \\n"/' "$po"
  done
}

# Убирает накопленные obsolete-записи (#~) из .po ПОСЛЕДНИМ шагом пайплайна, рядом со
# strip_volatile_metadata(). msgmerge --update (в отличие от
# прежнего wp i18n update-po с purge=true по умолчанию) не удаляет строки, отсутствующие
# в новом .pot — помечает их #~ и оставляет в файле навсегда, накапливая мёртвый текст на
# каждой регенерации. --no-obsolete не трогает активные msgid/msgstr/location (другая
# функциональная группа флагов msgattrib, чем --no-location в strip_volatile_metadata) —
# не сливается с той функцией намеренно, разные инварианты (location-в-.pot vs
# obsolete-в-.po).
purge_obsolete() {
  local po tmp
  for po in "$LANG_DIR"/*.po; do
    [[ -f "$po" ]] || continue
    tmp="${po}.purge.tmp"
    # --no-wrap: последний полный писатель .po в пайплайне — именно здесь фиксируется
    # каноническая one-line форма, независимая от версии gettext ([internal]).
    msgattrib --no-wrap --no-obsolete "$po" -o "$tmp"
    mv "$tmp" "$po"
  done
}

# Убирает из .po ссылки на PHP-исходники ПОСЛЕДНИМ шагом пайплайна (порт из PRO,
# [internal]/[internal]; во Free — [internal]/[internal]).
#
# ПРИЧИНА (а не симптом). Номера строк в ссылках `#:` меняются от ЛЮБОЙ правки кода
# рядом с переводимой строкой — даже когда сам перевод не тронут. Дальше цепочка:
# дерево грязное -> release встаёт на assert_i18n_git_clean -> релиз не собирается
# у другого человека. Замер Free при порте: 745 location-строк, из них 735 на PHP.
#
# ПОЧЕМУ ТОЛЬКО PHP, а не все location (важно, не упрощать):
#  - ссылки на JS-исходники ФУНКЦИОНАЛЬНЫ: make-json читает их, чтобы понять, к какому
#    JS-файлу относится строка и как назвать JSON-файл. Убрать их -> JSON пустеют;
#  - ссылки на PHP не читает никто: .mo не несёт location вообще.
#
# ОТЛИЧИЕ ОТ PRO-ОРИГИНАЛА (намеренное): PRO фильтрует по каталогу `resources/js` —
# во Free JS-исходники живут ещё и в src/Modules/*/assets/ (co-located, [internal]),
# поэтому критерий здесь — расширение `.js` в ссылке, не каталог. Смешанных строк
# (PHP+JS в одной `#:`) во Free ноль (замер при порте); если появятся — строка
# сохраняется целиком: терять функциональную JS-ссылку нельзя ни при каких условиях.
#
# Строго ПОСЛЕ build_json/merge_bundle_json: до них location ещё нужны make-json.
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

# Для кастомного --out= (не "languages") LANG_DIR стартует пустым — update_po
# итерирует "$LANG_DIR"/*.po и без затравки не найдёт существующие переводы,
# с которыми msgmerge --update должен смержить новый POT.
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

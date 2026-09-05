#!/usr/bin/env bash
set -euo pipefail

# Гейт полноты перевода (найдено вживую на стенде —
# 'Folder field in Quick Edit'/'More power in PRO' не переведены). Использует msgattrib
# --untranslated (GNU gettext, тот же инструмент, что уже применяет bin/build-i18n.sh) —
# не изобретаем свой .po-парсер там, где gettext уже решает задачу корректно для
# многострочных msgid/msgid_plural.
#
# Contract:
#   default mode: exit 0 — нет НОВЫХ непереведённых msgid сверх baseline; exit 1 — есть.
#   --report-stale-baseline: отчёт о baseline-строках, которые уже переведены (протухли).
#     exit 0 — протухших строк нет; exit 1 — есть ([internal]: раньше этот режим всегда
#     возвращал 0, что делало CI-шаг, который его вызывает, структурно недостижимо
#     красным). Вызывающий сам решает, читать ли код возврата: pre-commit намеренно его
#     игнорирует (см. i18n-stale-baseline-warning — non-blocking by design, риск
#     `--no-verify`-генератора), CI на него полагается.
# Baseline существующего долга не блокирует (см. bin/i18n-baseline-*.txt).

usage() {
  cat >&2 <<'USAGE'
Usage:
  check-i18n-completeness.sh <po-file> <baseline-file>
  check-i18n-completeness.sh --report-stale-baseline <po-file> <baseline-file>
USAGE
  exit 2
}

ensure_prereqs() {
  local po_file="$1"

  if [[ ! -f "$po_file" ]]; then
    echo "check-i18n-completeness: PO file not found: $po_file" >&2
    exit 2
  fi

  if ! command -v msgattrib >/dev/null 2>&1; then
    echo "check-i18n-completeness: GNU gettext (msgattrib) is required." >&2
    exit 2
  fi

  if ! command -v msgcat >/dev/null 2>&1; then
    echo "check-i18n-completeness: GNU gettext (msgcat) is required." >&2
    exit 2
  fi

  # [internal]/#807 (GATEOWNER): rg/python3 не проверялись здесь вовсе — CI-шаг был
  # зелёным без реальной проверки в контейнере без обеих команд (live-подтверждено:
  # rg: command not found / python3: command not found в реальных CI-логах). exit 2 —
  # тот же код, что остальные ensure_prereqs-отказы этого файла (не exit 1 от require_rg
  # общего хелпера — контракт файла: usage()/ensure_prereqs() используют 2, основная
  # логика 1; сохраняем существующий контракт вместо смешивания кодов).
  if ! command -v rg >/dev/null 2>&1; then
    echo "check-i18n-completeness: ripgrep (rg) is required." >&2
    exit 2
  fi
  if ! command -v python3 >/dev/null 2>&1; then
    echo "check-i18n-completeness: python3 is required." >&2
    exit 2
  fi
}

extract_untranslated() {
  local po_file="$1"

  # msgattrib correctly marks untranslated entries, but long msgid values may still be emitted
  # as msgid "" + continuation lines. Canonicalize them back to single-line msgid values
  # before comparing against the baseline.
  msgattrib --untranslated "$po_file" 2>/dev/null \
    | msgcat --no-wrap - 2>/dev/null \
    | rg '^msgid "' \
    | sed 's/^msgid "\(.*\)"$/\1/' \
    | rg -v '^$' \
    || true

  # Частично переведённые множественные записи.
  # msgattrib --untranslated их НЕ возвращает — он считает запись переведённой, если
  # заполнена хотя бы одна форма. Это и есть заимствованное определение, от которого
  # проект отказался: см. «Что считается переведённой строкой» в
  # spec/skills/standards/i18n-code-completeness-standard.md.
  extract_partial_plurals "$po_file"
}

# Частично переведённая множественная запись = долг (определение проекта, норматив выше).
# Штатного флага для этого в gettext НЕТ: --untranslated её не видит, --incomplete и
# --translated не различают, --empty печатает и полностью переведённые, msgfmt --check
# даёт exit 0. Все пять проверены прогонами 2026-08-25 — поэтому здесь разбирается
# нормализованный вывод msgcat --no-wrap, тем же python-инлайном, что и check_glued_msgstr.
# Это осознанное исключение из канона файла «свой .po-парсер не изобретаем».
extract_partial_plurals() {
  local po_file="$1"
  msgcat --no-wrap "$po_file" 2>/dev/null | python3 -c "
import re, sys

lines = sys.stdin.read().splitlines()

# nplurals берём из СКЛЕЕННОГО заголовка: в живом словаре строка Plural-Forms разорвана
# на две физические строки, поэтому собираем все продолжения, а не читаем одну строку.
header = ''.join(l.strip().strip('\"') for l in lines if l.startswith('\"'))
m = re.search(r'nplurals\s*=\s*([0-9]+)', header)

# Заголовок Plural-Forms обязателен ТОЛЬКО если в словаре есть множественные записи:
# словарь без единого msgid_plural его иметь не обязан, и падать на нём нельзя — иначе
# ломается проверка обычных строк (поймано прогоном: тест-фикстура «длинный
# непереведённый msgid» переставала находиться). Если plural-записи есть, а заголовка
# нет — падаем, а не пропускаем молча: посчитать полноту форм нечем.
has_plural = any(l.startswith('msgid_plural ') and not l.startswith('#~') for l in lines)
if not m:
    if has_plural:
        sys.stderr.write('check-i18n-completeness: в .po есть множественные записи, но в '
                         'заголовке нет Plural-Forms/nplurals — полноту форм определить '
                         'невозможно.\n')
        sys.exit(2)
    sys.exit(0)
nplurals = int(m.group(1))

state = {'msgid': None, 'plural': False, 'forms': {}}
bad = []

def flush():
    if state['msgid'] and state['plural']:
        empty = [i for i in range(nplurals) if not state['forms'].get(i)]
        if empty and len(empty) < nplurals:
            bad.append(state['msgid'])
    state['msgid'] = None
    state['plural'] = False
    state['forms'] = {}

for line in lines:
    # Устаревшие записи (#~) выведены из обращения и в интерфейс не попадают.
    if line.startswith('#~'):
        continue
    if not line.strip():
        flush()
        continue
    if line.startswith('msgid '):
        flush()
        state['msgid'] = line[6:].strip().strip('\"')
    elif line.startswith('msgid_plural '):
        state['plural'] = True
    elif line.startswith('msgstr['):
        idx = int(line[7:line.index(']')])
        state['forms'][idx] = line[line.index(']') + 1:].strip().strip('\"')
flush()

for mid in bad:
    print(mid)
" || {
    local rc=$?
    if [[ $rc -eq 2 ]]; then
      exit 1
    fi
    return 0
  }
}

# Детект СКЛЕЕННЫХ msgstr — двух полных вариантов перевода,
# сконкатенированных в одну строку. Класс порчи рукотворный (агентские правки .po),
# воспроизводился минимум пятью коммитами разных сессий и месяцами жил незамеченным.
# Два детектора, полноту даёт только их объединение (калибровка — 10 реальных склеек
# из состояния a37525f4^):
#   Д1 — стык предложений без пробела ([а-яё)»a-z].[А-ЯЁ]) — ловит склейку по точке;
#   Д2 — половины строки с общим префиксом >=7 символов — ловит склейку без точки на
#        стыке (двоеточие, скобка, латиница).
# Baseline к склейкам НЕ применяется намеренно: склейка не бывает легитимной.
# Unwrap — msgcat --no-wrap (gettext), свой .po-парсер не изобретаем (канон этого файла).
check_glued_msgstr() {
  local po_file="$1"
  msgcat --no-wrap "$po_file" 2>/dev/null | python3 -c '
import re, sys
lines = sys.stdin.read().splitlines()
mid = None
bad = []
for line in lines:
    if line.startswith("msgid "):
        mid = line[6:].strip().strip("\"")
    elif line.startswith("msgstr ") and mid is not None:
        s = line[7:].strip().strip("\"")
        d1 = bool(re.search(r"[а-яёa-z)»]\.[А-ЯЁ]", s))
        d2 = any(len(s[c:]) > 10 and s[:7] == s[c:c+7] for c in range(len(s)//3, 2*len(s)//3))
        if d1 or d2:
            bad.append(mid)
        mid = None
if bad:
    print("check-i18n-completeness: СКЛЕЕННЫЕ переводы (два варианта в одном msgstr):", file=sys.stderr)
    for b in bad:
        print("  msgid: " + b[:100], file=sys.stderr)
    sys.exit(1)
'
}

read_baseline() {
  local baseline_file="$1"

  if [[ ! -f "$baseline_file" ]]; then
    return 0
  fi

  rg -v '^[[:space:]]*(#.*)?$' "$baseline_file" || true
}

report_stale_baseline() {
  local po_file="$1"
  local baseline_file="$2"
  local untranslated baseline line stale_entries stale_count

  ensure_prereqs "$po_file"
  untranslated="$(extract_untranslated "$po_file")"
  baseline="$(read_baseline "$baseline_file")"
  stale_entries=""
  stale_count=0

  while IFS= read -r line; do
    [[ -z "$line" ]] && continue
    if ! rg -qxF "$line" <<<"$untranslated"; then
      stale_entries+="$line"$'\n'
      stale_count=$((stale_count + 1))
    fi
  done <<<"$baseline"

  if [[ "$stale_count" -gt 0 ]]; then
    echo "check-i18n-completeness: baseline contains $stale_count line(s) already translated; remove them from $baseline_file:" >&2
    printf '%s' "$stale_entries" >&2
    return 1
  fi

  return 0
}

mode="check"
if [[ $# -gt 0 && "$1" == "--report-stale-baseline" ]]; then
  mode="report-stale-baseline"
  shift
# Pre-commit хуку нужен САМ СПИСОК новых непереведённых строк (чтобы
# пересечь его со строками текущего коммита), а не только код возврата. Режим печатает
# список в stdout по одной строке и всегда завершается нулём — решение принимает
# вызывающий. Логика «что считать новым» остаётся здесь, дублировать её в хуке нельзя.
elif [[ $# -gt 0 && "$1" == "--list-new" ]]; then
  mode="list-new"
  shift
fi

[[ $# -eq 2 ]] || usage

PO_FILE="$1"
BASELINE_FILE="$2"

if [[ "$mode" == "report-stale-baseline" ]]; then
  stale_rc=0
  report_stale_baseline "$PO_FILE" "$BASELINE_FILE" || stale_rc=$?
  exit "$stale_rc"
fi

if [[ "$mode" == "list-new" ]]; then
  ensure_prereqs "$PO_FILE"
  list_untranslated="$(extract_untranslated "$PO_FILE")"
  list_baseline="$(read_baseline "$BASELINE_FILE")"
  while IFS= read -r line; do
    [[ -z "$line" ]] && continue
    rg -qxF "$line" <<<"$list_baseline" || printf '%s\n' "$line"
  done <<<"$list_untranslated"
  exit 0
fi

ensure_prereqs "$PO_FILE"
check_glued_msgstr "$PO_FILE"
untranslated="$(extract_untranslated "$PO_FILE")"
baseline="$(read_baseline "$BASELINE_FILE")"
new_untranslated=""

while IFS= read -r line; do
  [[ -z "$line" ]] && continue
  if ! rg -qxF "$line" <<<"$baseline"; then
    new_untranslated+="$line"$'\n'
  fi
done <<<"$untranslated"

if [[ -n "$new_untranslated" ]]; then
  echo "check-i18n-completeness: НОВЫЕ непереведённые строки (нет в baseline $BASELINE_FILE):" >&2
  printf '%s' "$new_untranslated" >&2
  exit 1
fi

exit 0

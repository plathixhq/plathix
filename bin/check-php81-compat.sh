#!/usr/bin/env bash
# Guard совместимости с минимально заявленной версией PHP (8.1).
#
# ЗАЧЕМ греп, а не `php -l`: `php -l` ловит 8.2-only синтаксис ТОЛЬКО если сам интерпретатор
# < 8.2. В среде гейта стоит PHP 8.3+ (нет php8.1/docker локально), поэтому `php8.3 -l`
# пропускает `readonly class` как валидный → мёртвый guard. Детектор паттернов работает на
# ЛЮБОЙ версии PHP и ловит регресс до того, как он уедет на 8.1-хост (прод клиента и т.п.).
#
# Плагин объявляет Requires PHP 8.1 (plathix.php header + composer ">=8.1"). Код обязан
# парситься под 8.1. Известные 8.2-only конструкции, которые здесь блокируются:
#   - class-level `readonly class` (8.2)   → используй property-level `public readonly`
#   - standalone types null|true|false в сигнатурах (8.2)
#   - DNF-типы (A&B)|C (8.2)
# Список расширяемый: при новой находке добавь паттерн сюда.
#
# Использование: bash bin/check-php81-compat.sh [файл ...]
#   без аргументов — сканирует весь src/ и plathix.php.
#   с аргументами — только переданные (для pre-commit staged-контура).
# Код возврата: 0 — чисто; 1 — найдены 8.2-only конструкции.

set -uo pipefail
require_rg() {
  # Гейты ниже сканируют дерево через rg. Без этой проверки отсутствие rg не остановило бы
  # скрипт: `|| true` и `if rg …` не отличают «нарушений нет» (exit 1) от «искать нечем»
  # (exit 127/2), и гейт печатал бы PASS ровно тогда, когда ничего не проверил.
  # Форма — та же, что require_gettext()/require_wp_cli() в bin/build-i18n.sh.
  if ! command -v rg >/dev/null 2>&1; then
    echo "ripgrep (rg) is required: гейты этого скрипта сканируют дерево через rg." >&2
    echo "Install ripgrep: 'apt-get install ripgrep' or 'brew install ripgrep'." >&2
    exit 1
  fi
}

ROOT_DIR="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"

if [[ $# -gt 0 ]]; then
  files=("$@")
else
  mapfile -t files < <(find "${ROOT_DIR}/src" -name '*.php'; echo "${ROOT_DIR}/plathix.php")
fi

require_rg

fail=0
for f in "${files[@]}"; do
  [[ -f "$f" ]] || continue
  [[ "$f" == *.php ]] || continue

  # 1. class-level readonly: `readonly class` / `final readonly class` / `abstract readonly class`
  #    (property-level `public readonly int $x` — легально на 8.1, его НЕ трогаем: паттерн
  #    требует ключевое слово class сразу после readonly).
  # fail-closed: rc захватывается явно. `if rg …` не отличал отказ сканера
  # (exit 2) от «нарушений нет» — при отказе файл считался проверенным и чистым.
  rc=0
  rg -n '(^|[[:space:]])(readonly[[:space:]]+class|final[[:space:]]+readonly[[:space:]]+class|abstract[[:space:]]+readonly[[:space:]]+class)[[:space:]]' "$f" >/dev/null 2>&1 || rc=$?
  if [[ $rc -eq 0 ]]; then
    echo "PHP81 FAIL: class-level readonly (8.2+) в $f — используй property-level 'public readonly':" >&2
    rg -n '(readonly[[:space:]]+class)' "$f" >&2
    fail=1
  elif [[ $rc -ne 1 ]]; then
    echo "PHP81 FAIL: проверка не выполнена (rg exit ${rc}) для $f — считаем файл непроверенным." >&2
    fail=1
  fi

  # 2. DNF-тип (A&B)|C в сигнатуре (8.2). Multiline (-U): WP-стиль часто переносит
  #    параметры сигнатуры на отдельные строки, DNF-параметр может оказаться не на
  #    той же строке, что открывающая скобка функции.
  rc=0
  rg -Un '\([A-Za-z_][A-Za-z0-9_\\]*(&[A-Za-z_][A-Za-z0-9_\\]*)+\)\s*\|' "$f" >/dev/null 2>&1 || rc=$?
  if [[ $rc -eq 0 ]]; then
    echo "PHP81 FAIL: DNF-тип (A&B)|C (8.2+) в $f — DNF-типы недоступны на 8.1:" >&2
    rg -Un '\([A-Za-z_][A-Za-z0-9_\\]*(&[A-Za-z_][A-Za-z0-9_\\]*)+\)\s*\|' "$f" >&2
    fail=1
  elif [[ $rc -ne 1 ]]; then
    echo "PHP81 FAIL: проверка DNF не выполнена (rg exit ${rc}) для $f — считаем файл непроверенным." >&2
    fail=1
  fi

  # 3. standalone null/true/false как ЕДИНСТВЕННЫЙ тип (8.2) — не как значение по
  #    умолчанию и не как часть union (`bool|false` легален с 8.0, не standalone).
  #    Return-type позиция (`function f(): null {`) и параметр-тип позиция (`(null $x`).
  #    Return-type требует явный якорь `function\s+` перед скобками — без него `)  :
  #    null;` ложно матчил тернарник `cond ? x : null;` (везде, где `)` замыкает
  #    вызов ВНУТРИ условия, не сигнатуру функции) — живой прогон на src/ дал 12
  #    false positive до этого уточнения.
  rc=0
  rg -n 'function\s+[A-Za-z_]*\s*\([^()]*\)\s*:\s*(null|true|false)\s*[{;]' "$f" >/dev/null 2>&1 || rc=$?
  if [[ $rc -eq 0 ]]; then
    echo "PHP81 FAIL: standalone null/true/false return-type (8.2+) в $f:" >&2
    rg -n 'function\s+[A-Za-z_]*\s*\([^()]*\)\s*:\s*(null|true|false)\s*[{;]' "$f" >&2
    fail=1
  elif [[ $rc -ne 1 ]]; then
    echo "PHP81 FAIL: проверка standalone-return-type не выполнена (rg exit ${rc}) для $f — считаем файл непроверенным." >&2
    fail=1
  fi
  rc=0
  rg -n '\(\s*(null|true|false)\s+\$' "$f" >/dev/null 2>&1 || rc=$?
  if [[ $rc -eq 0 ]]; then
    echo "PHP81 FAIL: standalone null/true/false параметр-тип (8.2+) в $f:" >&2
    rg -n '\(\s*(null|true|false)\s+\$' "$f" >&2
    fail=1
  elif [[ $rc -ne 1 ]]; then
    echo "PHP81 FAIL: проверка standalone-param-type не выполнена (rg exit ${rc}) для $f — считаем файл непроверенным." >&2
    fail=1
  fi
done

if [[ "$fail" -eq 0 ]]; then
  echo "PHP81 OK: 8.2-only конструкций не найдено (${#files[@]} файлов проверено)."
fi
exit "$fail"

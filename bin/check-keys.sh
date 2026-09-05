#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

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

require_rg

COMMON_GLOBS=(
    --glob '!vendor/**'
    --glob '!node_modules/**'
    --glob '!assets/**'
    --glob '!src/Infrastructure/Keys.php'
)

# (1) Литерал plathix_* прямо в вызове transient/cache-функции.
# fail-closed: rc захватывается явно. `if rg …` не отличал отказ сканера
# (exit 2 — битый паттерн, нечитаемый путь) от «нарушений нет», и гейт печатал
# "Keys check passed" ровно тогда, когда ничего не проверил.
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
    echo "Keys check не выполнен (rg exit ${rc}) для паттерна прямых transient/cache-вызовов." >&2
    exit 1
fi

# (2) Присваивание plathix_*-ключа в transient/cache-переменную/константу
# (cache_key/CACHE_KEY/rate_key = 'plathix_...') — обходной путь мимо паттерна (1):
# ключ кладут в переменную, а в get/set_transient() передают её. Ловим по имени,
# несущему transient/cache-семантику (option/meta/job-hook строки НЕ трогаем).
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
    echo "Keys check не выполнен (rg exit ${rc}) для паттерна присваивания ключа в переменную." >&2
    exit 1
fi

echo "Keys check passed"

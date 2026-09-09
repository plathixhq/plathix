#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

bash bin/check-js-coverage.sh

# Runtime images in assets/img are source-controlled and must survive rebuilds.


IMG_BACKUP=""
if [[ -d assets/img ]]; then
  IMG_BACKUP="$(mktemp -d)"
  cp -a assets/img/. "$IMG_BACKUP/"
fi

rm -f assets/*.js assets/*.php assets/*.css assets/*-rtl.css

npx wp-scripts build

mkdir -p assets/css












for css_src in resources/css/*.css; do
  css_name="$(basename "$css_src")"
  if [[ ! -f "assets/css/${css_name}" ]]; then
    cp "$css_src" "assets/css/${css_name}"
  fi
done


if [[ -n "$IMG_BACKUP" ]]; then
  mkdir -p assets/img
  cp -a "$IMG_BACKUP/." assets/img/
  rm -rf "$IMG_BACKUP"
fi

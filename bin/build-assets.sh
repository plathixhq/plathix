#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

bash bin/check-js-coverage.sh

# Runtime images in assets/img are source-controlled and must survive rebuilds.
# wp-scripts build (webpack output.clean) очищает весь output dir и сносит
# assets/img — поэтому сохраняем её во временную папку и восстанавливаем после.
IMG_BACKUP=""
if [[ -d assets/img ]]; then
  IMG_BACKUP="$(mktemp -d)"
  cp -a assets/img/. "$IMG_BACKUP/"
fi

rm -f assets/*.js assets/*.php assets/*.css assets/*-rtl.css

npx wp-scripts build

mkdir -p assets/css

# Часть CSS собирает сам webpack из JS-entry (admin-ui, sidebar, lightbox,
# settings, admin-menu) — и для lightbox он ВКЛЮЧАЕТ базовый CSS PhotoSwipe
# через `import 'photoswipe/style.css'`. Эти файлы уже лежат в assets/css/
# после wp-scripts build, и их НЕЛЬЗЯ затирать сырыми исходниками из
# resources/css/ — иначе из lightbox.css пропадёт ~3.7KB базового CSS PhotoSwipe
# и лайтбокс ломается (изображение вылезает за вьюпорт на узком экране).
# Поэтому из resources/css/ докопируем только standalone-CSS, у которых нет
# собственного webpack-entry (gallery, plathix, attachment-fields) — их webpack
# не генерит, и в assets/css/ их ещё нет.
for css_src in resources/css/*.css; do
  css_name="$(basename "$css_src")"
  if [[ ! -f "assets/css/${css_name}" ]]; then
    cp "$css_src" "assets/css/${css_name}"
  fi
done

# Восстанавливаем source-controlled картинки, снесённые webpack-ом.
if [[ -n "$IMG_BACKUP" ]]; then
  mkdir -p assets/img
  cp -a "$IMG_BACKUP/." assets/img/
  rm -rf "$IMG_BACKUP"
fi

#!/usr/bin/env bash
set -euo pipefail

#

usage() {
	echo "Usage: $0 X.Y.Z" >&2
	exit 1
}

NEW_VERSION="${1:-}"

if [[ -z "$NEW_VERSION" ]]; then
	usage
fi

if ! [[ "$NEW_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Error: version must match X.Y.Z (got: $NEW_VERSION)" >&2
	exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=bin/lib/detect-plugin-context.sh
. "${SCRIPT_DIR}/lib/detect-plugin-context.sh"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
detect_plugin_context "$REPO_ROOT" || exit 1

MAIN_FILE="$REPO_ROOT/${PLUGIN_SLUG}.php"
BOOTSTRAP_FILE="$REPO_ROOT/includes/bootstrap.php"
README_FILE="$REPO_ROOT/readme.txt"

FILES_TO_CHECK=("$MAIN_FILE" "$BOOTSTRAP_FILE")
if [[ "$HAS_README_STABLE_TAG" == "1" ]]; then
	FILES_TO_CHECK+=("$README_FILE")
fi

for f in "${FILES_TO_CHECK[@]}"; do
	if [[ ! -f "$f" ]]; then
		echo "Error: expected file not found: $f" >&2
		exit 1
	fi
done

get_old_version() {
	rg -oP "Version:\s*\K[0-9]+\.[0-9]+\.[0-9]+" "$MAIN_FILE" | head -n1
}

OLD_VERSION="$(get_old_version)"

if [[ -z "$OLD_VERSION" ]]; then
	echo "Error: could not find current Version in $MAIN_FILE" >&2
	exit 1
fi

sed -i -E "s/(\*[[:space:]]*Version:[[:space:]]*)[0-9]+\.[0-9]+\.[0-9]+/\1${NEW_VERSION}/" "$MAIN_FILE"
sed -i -E "s/(define\('${VERSION_CONST_NAME}',[[:space:]]*')[0-9]+\.[0-9]+\.[0-9]+(')/\1${NEW_VERSION}\2/" "$BOOTSTRAP_FILE"
if [[ "$HAS_README_STABLE_TAG" == "1" ]]; then
	sed -i -E "s/(Stable tag:[[:space:]]*)[0-9]+\.[0-9]+\.[0-9]+/\1${NEW_VERSION}/" "$README_FILE"
fi

echo "${PLUGIN_SLUG} version bumped: ${OLD_VERSION} -> ${NEW_VERSION}"
echo "  updated: ${MAIN_FILE#$REPO_ROOT/}"
echo "  updated: ${BOOTSTRAP_FILE#$REPO_ROOT/}"
if [[ "$HAS_README_STABLE_TAG" == "1" ]]; then
	echo "  updated: ${README_FILE#$REPO_ROOT/}"
fi

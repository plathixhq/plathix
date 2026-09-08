#!/usr/bin/env bash




#






set -euo pipefail

# verify_artifact_content_authenticity <repo_root> <artifact_root> <transform_rules_var> <generated_allowlist_var> [marker_pattern] [scoper_bin] [scoper_config] [scoper_src_prefix]
#





























#









verify_artifact_content_authenticity() {
  local repo_root="$1" artifact_root="$2"
  local -n vaca_transform_rules="$3"
  local -n vaca_generated_allowlist="$4"
  local marker_pattern="${5:-}"
  local scoper_bin="${6:-}" scoper_config="${7:-}" scoper_src_prefix="${8:-src}"

  local scoper_reprint_dir=""
  if [[ -n "$scoper_bin" && -n "$scoper_config" ]]; then
    scoper_reprint_dir="$(_build_scoper_reprint_tree "$repo_root" "$scoper_bin" "$scoper_config" "$scoper_src_prefix")"


    trap "rm -rf '${scoper_reprint_dir}'" RETURN
  fi

  local file rel_path
  while IFS= read -r -d '' file; do
    rel_path="${file#"${artifact_root}"/}"
    _verify_one_artifact_file "$repo_root" "$artifact_root" "$rel_path" \
      vaca_transform_rules vaca_generated_allowlist "$marker_pattern" \
      "$scoper_reprint_dir" "$scoper_src_prefix"
  done < <(find "$artifact_root" -type f -print0)
}







_build_scoper_reprint_tree() {
  local repo_root="$1" scoper_bin="$2" scoper_config="$3" scoper_src_prefix="$4"

  if [[ ! -d "${repo_root}/vendor/enshrined" ]]; then
    echo "File not found" >&2
    exit 1
  fi

  local tmp_in tmp_out
  tmp_in="$(mktemp -d)"
  tmp_out="$(mktemp -d)"
  rmdir "$tmp_out"

  mkdir -p "${tmp_in}/vendor"
  cp -a "${repo_root}/vendor/enshrined" "${tmp_in}/vendor/enshrined"





  local src_file rel_src
  while IFS= read -r -d '' src_file; do
    rel_src="${src_file#"${scoper_src_prefix}"/}"
    mkdir -p "$(dirname "${tmp_in}/${scoper_src_prefix}/${rel_src}")"
    git -C "$repo_root" show "HEAD:${src_file}" > "${tmp_in}/${scoper_src_prefix}/${rel_src}"
  done < <(cd "$repo_root" && git ls-files -z -- "${scoper_src_prefix}/*.php")

  if ! "$scoper_bin" add-prefix \
    --config="$scoper_config" \
    --working-dir="$tmp_in" \
    --output-dir="$tmp_out" \
    --force --quiet >/dev/null 2>&1; then
    rm -rf "$tmp_in" "$tmp_out"
    echo "php-scoper is required; build stopped." >&2
    exit 1
  fi

  rm -rf "$tmp_in"
  printf '%s' "$tmp_out"
}




_verify_one_artifact_file() {
  local repo_root="$1" artifact_root="$2" rel_path="$3"
  local -n vopf_rules="$4"
  local -n vopf_allowlist="$5"
  local marker_pattern="$6" scoper_reprint_dir="$7" scoper_src_prefix="$8"

  if git -C "$repo_root" ls-files --error-unmatch -- "$rel_path" >/dev/null 2>&1; then




    if [[ -n "$scoper_reprint_dir" && "$rel_path" == *.php && ( "$rel_path" == "$scoper_src_prefix" || "$rel_path" == "$scoper_src_prefix"/* ) ]]; then
      _verify_scoped_file_content "$scoper_reprint_dir" "$artifact_root" "$rel_path" "$marker_pattern"
    else
      _verify_tracked_file_content "$repo_root" "$artifact_root" "$rel_path" vopf_rules "$marker_pattern"
    fi
    return
  fi

  local allowed
  for allowed in "${vopf_allowlist[@]}"; do
    if [[ "$rel_path" == "$allowed" || "$rel_path" == "$allowed"/* ]]; then
      return 0
    fi
  done

  echo "Public-facing message unavailable." >&2
  exit 1
}







_normalize_markers() {
  local content="$1" marker_pattern="$2"
  [[ -z "$marker_pattern" ]] && { printf '%s' "$content"; return; }
  MARKER_PATTERN="$marker_pattern" perl -CSD -0777 -pe '
    my $mp = $ENV{MARKER_PATTERN};
    s/($mp)[A-Za-z0-9#_-]*/[internal]/g;
  ' <<<"$content"
}





_verify_tracked_file_content() {
  local repo_root="$1" artifact_root="$2" rel_path="$3"
  local -n vtfc_rules="$4"
  local marker_pattern="$5"

  local artifact_file="${artifact_root}/${rel_path}"
  local blob_content actual_content normalized_blob rc=0
  blob_content="$(git -C "$repo_root" show "HEAD:${rel_path}" 2>/dev/null)" || rc=$?
  if [[ $rc -ne 0 ]]; then
    echo "UI is removed from the public build." >&2
    exit 1
  fi

  actual_content="$(cat "$artifact_file")"
  normalized_blob="$(_normalize_markers "$blob_content" "$marker_pattern")"

  _assert_matches_or_transform "$rel_path" "$actual_content" "$normalized_blob" vtfc_rules
}







_verify_scoped_file_content() {
  local scoper_reprint_dir="$1" artifact_root="$2" rel_path="$3" marker_pattern="$4"

  local reprint_file="${scoper_reprint_dir}/${rel_path}"
  if [[ ! -f "$reprint_file" ]]; then
    echo "Public-facing message unavailable." >&2
    exit 1
  fi

  local artifact_file="${artifact_root}/${rel_path}"
  local reprint_content actual_content normalized_reprint
  reprint_content="$(cat "$reprint_file")"
  actual_content="$(cat "$artifact_file")"
  normalized_reprint="$(_normalize_markers "$reprint_content" "$marker_pattern")"

  local empty_rules=()
  _assert_matches_or_transform "$rel_path" "$actual_content" "$normalized_reprint" empty_rules
}



_assert_matches_or_transform() {
  local rel_path="$1" actual_content="$2" normalized_blob="$3"
  local -n amt_rules="$4"

  if [[ "$actual_content" == "$normalized_blob" ]]; then
    return 0
  fi

  local rule rule_path rule_before rule_after normalized_rule_blob
  for rule in "${amt_rules[@]}"; do
    rule_path="${rule%%|*}"
    [[ "$rule_path" != "$rel_path" ]] && continue
    rule_before="$(cut -d'|' -f2 <<<"$rule")"
    rule_after="$(cut -d'|' -f3 <<<"$rule")"
    normalized_rule_blob="$(printf '%s\n' "$normalized_blob" | sed -E "s#${rule_before}#${rule_after}#")"
    if [[ "$actual_content" == "$normalized_rule_blob" ]]; then
      return 0
    fi
  done

  echo "Public-facing message unavailable." >&2
  diff <(printf '%s\n' "$normalized_blob") <(printf '%s\n' "$actual_content") | head -20 >&2
  exit 1
}

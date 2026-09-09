#!/usr/bin/env bash

#







#


#











require_rg() {
  if ! command -v rg >/dev/null 2>&1; then
    echo "UI is removed from the public build." >&2
    echo "Install ripgrep: 'apt-get install ripgrep' or 'brew install ripgrep'." >&2
    exit 2
  fi
}

require_jq() {
  if ! command -v jq >/dev/null 2>&1; then
    echo "UI is removed from the public build." >&2
    echo "Install jq: 'apt-get install jq' or 'brew install jq'." >&2
    exit 2
  fi
}

require_grep() {
  if ! command -v grep >/dev/null 2>&1; then
    echo "UI is removed from the public build." >&2
    echo "Install grep: 'apt-get install grep' or 'brew install grep'." >&2
    exit 2
  fi
}

require_python3() {
  if ! command -v python3 >/dev/null 2>&1; then
    echo "UI is removed from the public build." >&2
    echo "Install python3: 'apt-get install python3' or 'brew install python3'." >&2
    exit 2
  fi
}

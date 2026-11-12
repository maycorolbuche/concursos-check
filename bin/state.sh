#!/usr/bin/env bash
set -euo pipefail

ref=refs/heads/state
directory="${RUNNER_TEMP:?}/concursos-state"
mkdir -p "$directory"

case "${1:-}" in
  load)
    status=0
    git ls-remote --exit-code origin "$ref" > "$directory/remote" || status=$?
    case "$status" in
      0)
        git fetch --no-tags origin "$ref"
        git rev-parse FETCH_HEAD > "$directory/parent"
        git show FETCH_HEAD:state.json > "$directory/state.json"
        ;;
      2)
        : > "$directory/parent"
        rm -f "$directory/state.json"
        ;;
      *) exit "$status" ;;
    esac
    ;;
  save)
    parent="$(cat "$directory/parent")"
    blob="$(git hash-object -w "$directory/state.json")"
    tree="$(printf '100644 blob %s\tstate.json\n' "$blob" | git mktree)"
    parents=()
    if [[ -n "$parent" ]]; then
      if [[ "$tree" == "$(git rev-parse "$parent^{tree}")" ]]; then
        exit 0
      fi
      parents=(-p "$parent")
    fi
    commit="$(git commit-tree "$tree" "${parents[@]}" -m "Atualizar estado do monitor")"
    git push origin "$commit:$ref"
    printf '%s\n' "$commit" > "$directory/parent"
    ;;
  *) exit 2 ;;
esac

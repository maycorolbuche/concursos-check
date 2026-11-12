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
        git rev-parse FETCH_HEAD > "$directory/head"
        git show FETCH_HEAD:state.json > "$directory/state.json"
        ;;
      2)
        : > "$directory/head"
        rm -f "$directory/state.json"
        ;;
      *) exit "$status" ;;
    esac
    ;;
  save)
    previous="$(cat "$directory/head")"
    blob="$(git hash-object -w "$directory/state.json")"
    tree="$(printf '100644 blob %s\tstate.json\n' "$blob" | git mktree)"
    if [[ -n "$previous" && "$tree" == "$(git rev-parse "$previous^{tree}")" \
        && -z "$(git show -s --format=%P "$previous")" ]]; then
      exit 0
    fi
    commit="$(git commit-tree "$tree" -m "Atualizar estado do monitor")"
    git push --force-with-lease="$ref:$previous" origin "$commit:$ref"
    printf '%s\n' "$commit" > "$directory/head"
    ;;
  *) exit 2 ;;
esac

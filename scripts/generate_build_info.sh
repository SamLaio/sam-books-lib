#!/usr/bin/env bash
set -eu

repo_root="$(git rev-parse --show-toplevel)"
cd "$repo_root"

output="site/build_info.json"
generated_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"

tracked_manifest="$(
  git ls-files -z \
    | sort -z \
    | while IFS= read -r -d '' file; do
        [ "$file" = "$output" ] && continue
        [ -f "$file" ] || continue
        blob_hash="$(git hash-object -- "$file")"
        printf '%s\t%s\n' "$blob_hash" "$file"
      done
)"

source_hash="$(printf '%s\n' "$tracked_manifest" | sha256sum | awk '{print $1}')"
file_count="$(printf '%s\n' "$tracked_manifest" | sed '/^$/d' | wc -l | tr -d ' ')"

mkdir -p "$(dirname "$output")"
cat > "$output" <<EOF
{
  "generated_at": "$generated_at",
  "source_hash": "$source_hash",
  "source_file_count": $file_count,
  "generator": "scripts/generate_build_info.sh"
}
EOF

printf 'Generated %s: %s / %s files\n' "$output" "$source_hash" "$file_count"

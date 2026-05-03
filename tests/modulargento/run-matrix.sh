#!/usr/bin/env bash
# Drive the per-set removal matrix.
#
# Usage:
#   run-matrix.sh [--version VER] [--profile NAME] [--only set1,set2] [--skip set1,set2]
#
# Steps:
#   1. Generate the baseline composer.json (mageos-full, nothing disabled).
#   2. Run baseline composer install + setup:di:compile — abort the matrix if it fails.
#   3. For each set in definitions/sets/*.yaml, invoke one-shot.sh.
#   4. Merge per-set JSON into results/matrix.json and re-render results/matrix.md.

set -u
set -o pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$script_dir/../.." && pwd)"
results_dir="$script_dir/results"
per_set_dir="$results_dir/per-set"
sandboxes_dir="$script_dir/sandboxes"

PROFILE="mageos-full"
VERSION=""
ONLY=""
SKIP=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --version) VERSION="$2"; shift 2 ;;
    --profile) PROFILE="$2"; shift 2 ;;
    --only)    ONLY="$2";    shift 2 ;;
    --skip)    SKIP="$2";    shift 2 ;;
    -h|--help)
      sed -n '2,12p' "$0"; exit 0 ;;
    *) echo "unknown flag: $1" >&2; exit 2 ;;
  esac
done

mkdir -p "$results_dir" "$per_set_dir" "$sandboxes_dir"
export PROFILE
[[ -n "$VERSION" ]] && export MAGEOS_VERSION="$VERSION"
export COMPOSER_CACHE_DIR="${COMPOSER_CACHE_DIR:-$HOME/.cache/composer}"

baseline_dir="$sandboxes_dir/_baseline"
baseline_composer="$baseline_dir/composer.json"
mkdir -p "$baseline_dir"

echo "[matrix] generating baseline composer.json ($PROFILE)"
configure_args=(--profile="$PROFILE" --output="$baseline_composer")
[[ -n "$VERSION" ]] && configure_args+=(--mageos-version="$VERSION")
( cd "$PROJECT_ROOT" && php artisan mageos:configure "${configure_args[@]}" )

export BASELINE_COMPOSER="$baseline_composer"

# Baseline test (set-name '_baseline') — runs install + compile to prove the harness works.
echo "[matrix] running baseline (full install + di:compile)"
PROFILE="$PROFILE" "$script_dir/one-shot.sh" _baseline ${VERSION:+"$VERSION"} > "$per_set_dir/_baseline.json" || true
baseline_status="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["status"])' "$per_set_dir/_baseline.json")"
echo "[matrix] baseline status: $baseline_status"
if [[ "$baseline_status" != "pass" ]]; then
  echo "[matrix] baseline did not pass — aborting matrix. See $results_dir/raw/_baseline.log" >&2
  exit 1
fi

# Discover sets.
mapfile -t all_sets < <(cd "$PROJECT_ROOT/definitions/sets" && ls *.yaml | sed 's/\.yaml$//' | sort)

filter_csv_to_set() {
  local csv="$1"; local -A m=()
  IFS=',' read -ra arr <<< "$csv"
  for x in "${arr[@]}"; do x="${x// /}"; [[ -n "$x" ]] && m["$x"]=1; done
  for k in "${!m[@]}"; do echo "$k"; done
}

if [[ -n "$ONLY" ]]; then
  mapfile -t only_arr < <(filter_csv_to_set "$ONLY")
  declare -A keep=()
  for k in "${only_arr[@]}"; do keep[$k]=1; done
  filtered=()
  for s in "${all_sets[@]}"; do [[ -n "${keep[$s]:-}" ]] && filtered+=("$s"); done
  all_sets=("${filtered[@]}")
fi

if [[ -n "$SKIP" ]]; then
  mapfile -t skip_arr < <(filter_csv_to_set "$SKIP")
  declare -A drop=()
  for k in "${skip_arr[@]}"; do drop[$k]=1; done
  filtered=()
  for s in "${all_sets[@]}"; do [[ -z "${drop[$s]:-}" ]] && filtered+=("$s"); done
  all_sets=("${filtered[@]}")
fi

total=${#all_sets[@]}
i=0
for s in "${all_sets[@]}"; do
  i=$((i+1))
  echo "[matrix] ($i/$total) $s"
  PROFILE="$PROFILE" "$script_dir/one-shot.sh" "$s" ${VERSION:+"$VERSION"} > /dev/null || true
  status="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["status"])' "$per_set_dir/$s.json" 2>/dev/null || echo unknown)"
  echo "    -> $status"
done

# Merge.
echo "[matrix] merging results into matrix.json"
python3 - "$per_set_dir" "$results_dir/matrix.json" <<'PY'
import json, os, sys
src, out = sys.argv[1], sys.argv[2]
items = []
for fn in sorted(os.listdir(src)):
    if not fn.endswith(".json"): continue
    with open(os.path.join(src, fn)) as f:
        items.append(json.load(f))
with open(out, "w") as f:
    json.dump({"profile": os.environ.get("PROFILE","mageos-full"),
               "version": os.environ.get("MAGEOS_VERSION",""),
               "results": items}, f, indent=2, ensure_ascii=False)
PY

echo "[matrix] rendering matrix.md"
php "$script_dir/render-matrix.php" "$results_dir/matrix.json" > "$results_dir/matrix.md"

echo "[matrix] done. See $results_dir/matrix.md"

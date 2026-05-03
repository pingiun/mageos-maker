#!/usr/bin/env bash
# Run one (set, version) test: configure → composer install → setup:di:compile → classify.
# Emits a single JSON object on stdout: {set, status, fingerprint, log_path, duration_s, composer_diff_baseline}.
#
# Usage:
#   one-shot.sh <set-name> [<mageos-version>]
#
# Env:
#   PROJECT_ROOT          mageos-maker checkout (default: derived from this script)
#   BASELINE_COMPOSER     path to baseline mageos-full composer.json (for noop detection)
#   PROFILE               starter profile name (default: mageos-full)
#   COMPOSER_CACHE_DIR    shared Composer cache (default: ~/.cache/composer)
#   COMPILE_TIMEOUT       seconds (default: 600)
#   INSTALL_TIMEOUT       seconds (default: 1800)

set -u
set -o pipefail

set_name="${1:?set name required}"
version="${2:-}"

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="${PROJECT_ROOT:-$(cd "$script_dir/../.." && pwd)}"
PROFILE="${PROFILE:-mageos-full}"
COMPOSER_CACHE_DIR="${COMPOSER_CACHE_DIR:-$HOME/.cache/composer}"
COMPILE_TIMEOUT="${COMPILE_TIMEOUT:-600}"
INSTALL_TIMEOUT="${INSTALL_TIMEOUT:-1800}"

results_dir="$script_dir/results"
raw_dir="$results_dir/raw"
per_set_dir="$results_dir/per-set"
sandbox="$script_dir/sandboxes/$set_name"
log="$raw_dir/$set_name.log"
per_set_json="$per_set_dir/$set_name.json"

mkdir -p "$raw_dir" "$per_set_dir" "$sandbox"

export COMPOSER_CACHE_DIR
export COMPOSER_NO_INTERACTION=1
export COMPOSER_ALLOW_SUPERUSER=1

start_ts=$(date +%s)

emit_json() {
  # $1 status, $2 fingerprint (raw), $3 composer_diff_baseline (true/false/unknown), $4 phase (configure/install/compile)
  local status="$1" fingerprint="$2" diff="$3" phase="$4"
  local end_ts dur
  end_ts=$(date +%s); dur=$((end_ts - start_ts))
  python3 - "$set_name" "$status" "$fingerprint" "$log" "$dur" "$diff" "$phase" <<'PY' > "$per_set_json"
import json, sys
keys = ["set","status","fingerprint","log_path","duration_s","composer_diff_baseline","phase"]
vals = sys.argv[1:]
vals[4] = int(vals[4])
out = dict(zip(keys, vals))
print(json.dumps(out, ensure_ascii=False))
PY
  cat "$per_set_json"
}

# Locate the configurator binary and run from PROJECT_ROOT.
artisan="$PROJECT_ROOT/artisan"
if [[ ! -x "$artisan" && ! -f "$artisan" ]]; then
  : > "$log"
  echo "artisan not found at $artisan" >> "$log"
  emit_json "harness-error" "artisan-missing" "unknown" "configure"
  exit 0
fi

# 1. Generate composer.json.
: > "$log"
echo "=== one-shot $set_name (profile=$PROFILE version=${version:-latest}) ===" >> "$log"
configure_args=(--profile="$PROFILE" --output="$sandbox/composer.json")
# Names starting with "_" are control rows (e.g. _baseline) — emit profile as-is.
[[ "$set_name" != _* ]] && configure_args+=(--disable="$set_name")
[[ -n "$version" ]] && configure_args+=(--mageos-version="$version")

if ! ( cd "$PROJECT_ROOT" && php artisan mageos:configure "${configure_args[@]}" ) >> "$log" 2>&1; then
  emit_json "configure-failed" "configure-error" "unknown" "configure"
  exit 0
fi

# 2. noop detection — composer.json identical to baseline means the disable had no effect.
diff_flag="unknown"
if [[ -n "${BASELINE_COMPOSER:-}" && -f "$BASELINE_COMPOSER" ]]; then
  if diff -q "$BASELINE_COMPOSER" "$sandbox/composer.json" >/dev/null 2>&1; then
    echo "composer.json identical to baseline — noop disable" >> "$log"
    emit_json "noop" "" "false" "configure"
    exit 0
  else
    diff_flag="true"
  fi
fi

# 3. Carry an auth.json into the sandbox if the project has one.
if [[ -f "$PROJECT_ROOT/auth.json" && ! -f "$sandbox/auth.json" ]]; then
  cp "$PROJECT_ROOT/auth.json" "$sandbox/auth.json"
fi

# 4. composer install.
echo "--- composer install ---" >> "$log"
if ! ( cd "$sandbox" && timeout "$INSTALL_TIMEOUT" composer install --no-interaction --no-dev --no-progress ) >> "$log" 2>&1; then
  fp="$(grep -m1 -E "Your requirements could not be resolved|Conclusion:|Problem [0-9]+" "$log" | head -c 240 | tr -d '\n' || true)"
  [[ -z "$fp" ]] && fp="composer-install-failed"
  emit_json "composer-failed" "$fp" "$diff_flag" "install"
  exit 0
fi

# 5. setup:di:compile.
echo "--- setup:di:compile ---" >> "$log"
compile_status=0
( cd "$sandbox" && timeout "$COMPILE_TIMEOUT" bin/magento setup:di:compile ) >> "$log" 2>&1 || compile_status=$?

if [[ "$compile_status" -eq 0 ]]; then
  emit_json "pass" "" "$diff_flag" "compile"
  exit 0
fi

if [[ "$compile_status" -eq 124 ]]; then
  emit_json "timeout" "compile-timeout-${COMPILE_TIMEOUT}s" "$diff_flag" "compile"
  exit 0
fi

# 6. Fingerprint — first match wins.
fp=""
while IFS= read -r pat; do
  [[ -z "$pat" ]] && continue
  hit="$(grep -m1 -oE "$pat" "$log" || true)"
  if [[ -n "$hit" ]]; then fp="$hit"; break; fi
done <<'PATTERNS'
Class "[^"]+" does not exist
Source class "[^"]+" for "[^"]+" generation does not exist
Type Error occurred when creating object: [^,]+
The requested class did not generate properly, because the '[^']+' file
Plugin class '[^']+'[^']*doesn't exist
Preference '[^']+' for '[^']+'
PATTERNS

if [[ -z "$fp" ]]; then
  fp="$(tail -n 5 "$log" | tr '\n' ' ' | tr -s ' ' | head -c 240)"
  [[ -z "$fp" ]] && fp="unclassified"
fi

emit_json "fail" "$fp" "$diff_flag" "compile"

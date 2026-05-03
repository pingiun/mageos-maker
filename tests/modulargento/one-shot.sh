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
#   INSTALL_TIMEOUT       seconds (default: 1800)  # composer install
#   SETUP_TIMEOUT         seconds (default: 1800)  # bin/magento setup:install
#
#   DB_HOST/DB_USER/DB_PASSWORD          (defaults: 127.0.0.1 / root / "")
#   DB_NAME_PREFIX                        (default: mageos_) — db is "<prefix><sanitized-set>"
#   OPENSEARCH_HOST/OPENSEARCH_PORT      (defaults: 127.0.0.1 / 9200)
#   REDIS_HOST/REDIS_PORT                 (defaults: 127.0.0.1 / 6379)
#   AMQP_HOST/AMQP_PORT/AMQP_USER/AMQP_PASSWORD  (defaults: 127.0.0.1 / 5672 / guest / guest)
#
# Patch injection (e.g. testing a Modulargento fix):
#   --repo TYPE:URL          extra composer repository, repeatable.
#                            For a local Modulargento monorepo with multiple
#                            sub-packages, use a path repo with a glob:
#                              --repo path:/abs/path/to/modulargento-magento2/packages/*
#                            For a single VCS repo:
#                              --repo vcs:https://github.com/foo/bar
#   --require PKG[:CONSTRAINT]   extra `require` entry, repeatable (default constraint: *).
#   --app-code SRC:MODULE[,MODULE2...]   copy patched module sources from
#                            <SRC>/<module>/ into <sandbox>/app/code/Magento/<module>/
#                            after composer install (Magento autoloads app/code
#                            ahead of vendor, so overlaid modules shadow stock).
#                            Repeatable. Use to test Modulargento patches that
#                            live as full Mage-OS app/code overrides:
#                              --app-code /Users/x/dev/tmp/modulargento-magento2/app/code/Magento:Reports,Catalog,Sales,WishlistReports

set -u
set -o pipefail

set_name="${1:?set name required}"
shift
version=""
extra_repos=()
extra_requires=()
app_code_overlays=()

# Optional second positional: version (any non-flag arg).
if [[ $# -gt 0 && "$1" != --* ]]; then
  version="$1"
  shift
fi

while [[ $# -gt 0 ]]; do
  case "$1" in
    --repo)     extra_repos+=("$2"); shift 2 ;;
    --require)  extra_requires+=("$2"); shift 2 ;;
    --app-code) app_code_overlays+=("$2"); shift 2 ;;
    *) echo "unknown flag: $1" >&2; exit 2 ;;
  esac
done

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="${PROJECT_ROOT:-$(cd "$script_dir/../.." && pwd)}"
PROFILE="${PROFILE:-mageos-full}"
COMPOSER_CACHE_DIR="${COMPOSER_CACHE_DIR:-$HOME/.cache/composer}"
COMPILE_TIMEOUT="${COMPILE_TIMEOUT:-600}"
INSTALL_TIMEOUT="${INSTALL_TIMEOUT:-1800}"
SETUP_TIMEOUT="${SETUP_TIMEOUT:-1800}"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_USER="${DB_USER:-root}"
DB_PASSWORD="${DB_PASSWORD:-}"
DB_NAME_PREFIX="${DB_NAME_PREFIX:-mageos_}"
OPENSEARCH_HOST="${OPENSEARCH_HOST:-127.0.0.1}"
OPENSEARCH_PORT="${OPENSEARCH_PORT:-9200}"
REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
REDIS_PORT="${REDIS_PORT:-6379}"
AMQP_HOST="${AMQP_HOST:-127.0.0.1}"
AMQP_PORT="${AMQP_PORT:-5672}"
AMQP_USER="${AMQP_USER:-guest}"
AMQP_PASSWORD="${AMQP_PASSWORD:-guest}"

# Sanitize set name for MySQL identifier / OpenSearch prefix (alnum + underscore).
sanitized="$(printf '%s' "$set_name" | tr -c 'A-Za-z0-9_' '_' | sed 's/^_*//' | head -c 48)"
db_name="${DB_NAME_PREFIX}${sanitized}"
os_prefix="mageos_${sanitized}"

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
if [[ "$set_name" != _* && -n "${BASELINE_COMPOSER:-}" && -f "$BASELINE_COMPOSER" ]]; then
  if diff -q "$BASELINE_COMPOSER" "$sandbox/composer.json" >/dev/null 2>&1; then
    echo "composer.json identical to baseline — noop disable" >> "$log"
    emit_json "noop" "" "false" "configure"
    exit 0
  else
    diff_flag="true"
  fi
fi

# 2b. Inject extra repositories / require entries (e.g. Modulargento patches).
if [[ ${#extra_repos[@]} -gt 0 || ${#extra_requires[@]} -gt 0 ]]; then
  echo "--- inject patches: ${#extra_repos[@]} repo(s), ${#extra_requires[@]} require(s) ---" >> "$log"
  merge_status=0
  python3 - "$sandbox/composer.json" "${#extra_repos[@]}" "${extra_repos[@]:-}" "${#extra_requires[@]}" "${extra_requires[@]:-}" >> "$log" 2>&1 <<'PY'
import json, sys
path = sys.argv[1]
i = 2
n_repos = int(sys.argv[i]); i += 1
repos = sys.argv[i:i+n_repos]; i += n_repos
n_reqs = int(sys.argv[i]); i += 1
reqs = sys.argv[i:i+n_reqs]
with open(path) as f:
    cj = json.load(f)
cj.setdefault("repositories", [])
for spec in repos:
    if not spec: continue
    if ":" not in spec:
        raise SystemExit(f"--repo expects TYPE:URL, got {spec!r}")
    rtype, url = spec.split(":", 1)
    cj["repositories"].append({"type": rtype, "url": url})
    print(f"  repo: {rtype} {url}")
cj.setdefault("require", {})
for spec in reqs:
    if not spec: continue
    if ":" in spec:
        pkg, constraint = spec.split(":", 1)
    else:
        pkg, constraint = spec, "*"
    cj["require"][pkg] = constraint
    print(f"  require: {pkg} {constraint}")
with open(path, "w") as f:
    json.dump(cj, f, indent=2)
    f.write("\n")
PY
  merge_status=$?
  if [[ $merge_status -ne 0 ]]; then
    emit_json "configure-failed" "patch-merge-failed" "$diff_flag" "configure"
    exit 0
  fi
  # Mutating composer.json invalidates any composer.lock left behind by a
  # prior run — drop it so the next `composer install` re-resolves.
  rm -f "$sandbox/composer.lock"
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

# 4b. Overlay patched module sources into app/code so they shadow vendor/.
if [[ ${#app_code_overlays[@]} -gt 0 ]]; then
  echo "--- app-code overlays: ${#app_code_overlays[@]} entry/entries ---" >> "$log"
  mkdir -p "$sandbox/app/code/Magento"
  overlay_failed=0
  for spec in "${app_code_overlays[@]}"; do
    if [[ "$spec" != *:* ]]; then
      echo "--app-code expects SRC:MODULE[,MODULE...], got '$spec'" >> "$log"
      overlay_failed=1; break
    fi
    src="${spec%%:*}"
    modules_csv="${spec#*:}"
    if [[ ! -d "$src" ]]; then
      echo "  source dir not found: $src" >> "$log"
      overlay_failed=1; break
    fi
    IFS=',' read -ra mods <<< "$modules_csv"
    for mod in "${mods[@]}"; do
      mod="${mod// /}"
      [[ -z "$mod" ]] && continue
      if [[ ! -d "$src/$mod" ]]; then
        echo "  module not found: $src/$mod" >> "$log"
        overlay_failed=1; break 2
      fi
      rm -rf "$sandbox/app/code/Magento/$mod"
      cp -R "$src/$mod" "$sandbox/app/code/Magento/$mod"
      # Magento errors on duplicate module definitions (it doesn't shadow
      # vendor with app/code). Drop the vendor copy if one exists.
      kebab="$(printf '%s' "$mod" | sed -E 's/([a-z0-9])([A-Z])/\1-\2/g; s/([A-Z])([A-Z][a-z])/\1-\2/g' | tr '[:upper:]' '[:lower:]')"
      vendor_dir="$sandbox/vendor/mage-os/module-$kebab"
      if [[ -d "$vendor_dir" ]]; then
        rm -rf "$vendor_dir"
        echo "  overlaid Magento/$mod from $src (replaced vendor/mage-os/module-$kebab)" >> "$log"
      else
        echo "  overlaid Magento/$mod from $src (no vendor copy — likely a new bridge module)" >> "$log"
      fi
    done
  done
  if [[ $overlay_failed -ne 0 ]]; then
    emit_json "configure-failed" "app-code-overlay-failed" "$diff_flag" "configure"
    exit 0
  fi
  # Removed vendor module dirs leave their registration.php still referenced
  # in vendor/composer/autoload_files.php. Regenerate the composer autoloader
  # so the bootstrap doesn't fail on the missing files.
  echo "--- composer dump-autoload (after overlay) ---" >> "$log"
  if ! ( cd "$sandbox" && composer dump-autoload --no-interaction --optimize ) >> "$log" 2>&1; then
    emit_json "configure-failed" "dump-autoload-failed" "$diff_flag" "configure"
    exit 0
  fi
fi

# 5. Wipe filesystem state from any prior run — setup:install only cleans the DB.
echo "--- wipe generated/ and var/cache (prior-run leftovers) ---" >> "$log"
rm -rf "$sandbox/generated" "$sandbox/var/cache" "$sandbox/var/page_cache" \
       "$sandbox/var/di" "$sandbox/var/composer_home" "$sandbox/var/log" \
       "$sandbox/app/etc/config.php" "$sandbox/app/etc/env.php"

# 6. Ensure the per-sandbox database exists (setup:install does not create it).
echo "--- create database $db_name ---" >> "$log"
if ! MYSQL_PWD="$DB_PASSWORD" mysql -h "$DB_HOST" -u "$DB_USER" \
    -e "CREATE DATABASE IF NOT EXISTS \`$db_name\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" >> "$log" 2>&1; then
  emit_json "install-failed" "mysql-create-database-failed" "$diff_flag" "install"
  exit 0
fi

# 6. setup:install — Magento needs app/etc/config.php before di:compile will run.
echo "--- setup:install (db=$db_name, os-prefix=$os_prefix) ---" >> "$log"
setup_status=0
( cd "$sandbox" && timeout "$SETUP_TIMEOUT" bin/magento setup:install \
    --base-url=http://localhost:8080/ \
    --db-host="$DB_HOST" \
    --db-name="$db_name" \
    --db-user="$DB_USER" \
    --db-password="$DB_PASSWORD" \
    --cleanup-database \
    --admin-firstname=Admin \
    --admin-lastname=User \
    --admin-email=admin@example.com \
    --admin-user=admin \
    --admin-password='Admin123!' \
    --language=en_US \
    --currency=EUR \
    --timezone=Europe/Amsterdam \
    --use-rewrites=1 \
    --search-engine=opensearch \
    --opensearch-host="$OPENSEARCH_HOST" \
    --opensearch-port="$OPENSEARCH_PORT" \
    --opensearch-index-prefix="$os_prefix" \
    --opensearch-enable-auth=0 \
    --session-save=redis \
    --session-save-redis-host="$REDIS_HOST" \
    --session-save-redis-port="$REDIS_PORT" \
    --session-save-redis-db=2 \
    --cache-backend=redis \
    --cache-backend-redis-server="$REDIS_HOST" \
    --cache-backend-redis-port="$REDIS_PORT" \
    --cache-backend-redis-db=0 \
    --page-cache=redis \
    --page-cache-redis-server="$REDIS_HOST" \
    --page-cache-redis-port="$REDIS_PORT" \
    --page-cache-redis-db=1 ) >> "$log" 2>&1 || setup_status=$?

if [[ "$setup_status" -ne 0 ]]; then
  if [[ "$setup_status" -eq 124 ]]; then
    emit_json "timeout" "setup-install-timeout-${SETUP_TIMEOUT}s" "$diff_flag" "install"
    exit 0
  fi
  fp=""
  # Restrict the search to the setup:install section so noise from earlier
  # phases (e.g. composer dump-autoload's PSR-4 grumbles about test fixtures)
  # doesn't outrank the real error.
  install_section="$(awk '/^--- setup:install/,0' "$log")"
  while IFS= read -r pat; do
    [[ -z "$pat" ]] && continue
    hit="$(printf '%s\n' "$install_section" | grep -m1 -oE "$pat" || true)"
    if [[ -n "$hit" ]]; then fp="$hit"; break; fi
  done <<'PATTERNS'
SQLSTATE\[[^]]+\]:[^,]+
Class "[^"]+" does not exist
Source class "[^"]+" for "[^"]+" generation does not exist
Plugin class '[^']+'[^']*doesn't exist
Preference '[^']+' for '[^']+'
Module '[^']+' has been already defined
Constant "[^"]+" is not defined
Fatal error: Uncaught [^:]+: [^ ]+
PATTERNS
  if [[ -z "$fp" ]]; then
    fp="$(printf '%s\n' "$install_section" | tail -n 5 | tr '\n' ' ' | tr -s ' ' | head -c 240)"
    [[ -z "$fp" ]] && fp="setup-install-failed"
  fi
  emit_json "install-failed" "$fp" "$diff_flag" "install"
  exit 0
fi

# 6. setup:di:compile.
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

# 7. Fingerprint — first match wins.
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

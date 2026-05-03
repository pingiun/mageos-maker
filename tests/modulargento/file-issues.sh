#!/usr/bin/env bash
# File one GitHub issue per failing row in tests/modulargento/results/matrix.json,
# targeting the Modulargento issue board.
#
# For each failing set the issue body lists:
#   - status / phase / fingerprint
#   - the composer.json `replace` keys (the modules we're trying to remove)
#   - the tail of the per-set di:compile log
#   - one-line repro command
#
# Existing open issues with the same "[<set>]" title prefix are skipped so the
# script is safe to re-run.
#
# Usage:
#   file-issues.sh [--repo OWNER/NAME] [--label NAME] [--only set1,set2] [--dry-run]
#
# Defaults:
#   --repo  modulargento/modulargento-magento2
#   --label removal-blocker

set -u
set -o pipefail

REPO="modulargento/modulargento-magento2"
LABEL="removal-blocker"
ONLY=""
DRY_RUN=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --repo)    REPO="$2"; shift 2 ;;
    --label)   LABEL="$2"; shift 2 ;;
    --only)    ONLY="$2"; shift 2 ;;
    --dry-run) DRY_RUN=1; shift ;;
    -h|--help) sed -n '2,18p' "$0"; exit 0 ;;
    *) echo "unknown flag: $1" >&2; exit 2 ;;
  esac
done

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$script_dir/../.." && pwd)"
matrix="$script_dir/results/matrix.json"
sandboxes="$script_dir/sandboxes"

[[ -f "$matrix" ]] || { echo "missing $matrix — run run-matrix.sh first" >&2; exit 1; }
command -v gh >/dev/null || { echo "gh not installed" >&2; exit 1; }

if [[ "$DRY_RUN" -eq 0 ]]; then
  gh auth status >/dev/null 2>&1 || { echo "gh not authenticated — run 'gh auth login'" >&2; exit 1; }
fi

# Cache the list of currently-open issue titles so we can skip duplicates.
existing_titles="$(mktemp)"
trap 'rm -f "$existing_titles"' EXIT
if [[ "$DRY_RUN" -eq 0 ]]; then
  gh issue list --repo "$REPO" --state open --limit 500 --json title -q '.[].title' > "$existing_titles" 2>/dev/null || true
else
  : > "$existing_titles"
fi

# Driver — emits one TSV row per failing set: <set>\t<status>\t<phase>\t<log_path>\t<sandbox_composer>\t<title>
mapfile -t rows < <(python3 - "$matrix" "$sandboxes" "$ONLY" <<'PY'
import json, os, re, sys
matrix_path, sandboxes_dir, only_csv = sys.argv[1], sys.argv[2], sys.argv[3]
only = set(s.strip() for s in only_csv.split(",") if s.strip())
with open(matrix_path) as f: data = json.load(f)
FAILING = {"fail","install-failed","composer-failed","timeout"}
def title_for(row):
    s, status, fp = row["set"], row["status"], (row.get("fingerprint") or "").strip()
    m = re.search(r'Class "([^"]+)" does not exist', fp) \
        or re.search(r'Source class "([^"]+)"', fp) \
        or re.search(r"Plugin class '([^']+)'", fp) \
        or re.search(r"Preference '([^']+)'", fp)
    if m: return f"[{s}] di:compile blocked by missing class {m.group(1)}"
    if status == "install-failed": return f"[{s}] setup:install fails when set is removed"
    if status == "composer-failed": return f"[{s}] composer install fails when set is removed"
    if status == "timeout": return f"[{s}] di:compile times out when set is removed"
    return f"[{s}] di:compile fails when set is removed"
for r in data.get("results", []):
    if r["status"] not in FAILING: continue
    if r["set"].startswith("_"): continue
    if only and r["set"] not in only: continue
    sandbox_cj = os.path.join(sandboxes_dir, r["set"], "composer.json")
    print("\t".join([r["set"], r["status"], r.get("phase",""), r.get("log_path",""), sandbox_cj, title_for(r)]))
PY
)

[[ ${#rows[@]} -eq 0 ]] && { echo "no failing rows in matrix.json — nothing to file"; exit 0; }

echo "Found ${#rows[@]} failing rows. Repo: $REPO  Label: $LABEL  Dry-run: $DRY_RUN"
echo

build_body() {
  # $1 set, $2 status, $3 phase, $4 log_path, $5 sandbox_composer
  local set="$1" status="$2" phase="$3" log="$4" sandbox_cj="$5"
  python3 - "$set" "$status" "$phase" "$log" "$sandbox_cj" <<'PY'
import json, os, sys
set_, status, phase, log_path, sandbox_cj = sys.argv[1:]

# Replace keys from sandbox composer.json — the modules being removed.
replace_lines = []
if os.path.isfile(sandbox_cj):
    try:
        cj = json.load(open(sandbox_cj))
        for k in sorted((cj.get("replace") or {}).keys()):
            replace_lines.append(f"- `{k}`")
    except Exception as e:
        replace_lines.append(f"_(failed to parse {sandbox_cj}: {e})_")
else:
    replace_lines.append(f"_(sandbox composer.json not found at {sandbox_cj} — re-run the matrix to regenerate)_")

# Log tail — slice from the last "--- phase ---" marker so we focus on the
# failing phase only (composer install logs would otherwise crowd out the real
# error in long logs), then drop the stock install-progress noise.
import re as _re
tail = ""
phase_label = ""
if os.path.isfile(log_path):
    with open(log_path, errors="replace") as f:
        raw = f.readlines()
    last_marker = None
    for i, line in enumerate(raw):
        if _re.match(r"^---\s.+\s---\s*$", line):
            last_marker = i
            phase_label = line.strip().strip("- ").strip()
    section = raw[last_marker:] if last_marker is not None else raw
    noise = _re.compile(r"^\s*(\[Progress:.*\]|Module '[^']+':|%message%.*|setup:install \[--.*)\s*$")
    filtered = [l for l in section if not noise.match(l)]
    tail = "".join(filtered[-120:]).rstrip()

body = f"""## Context

mage-os-maker's removal matrix (https://github.com/pingiun/mageos-maker/tree/master/tests/modulargento) tries to disable each stock package set against `mageos-full` and runs `bin/magento setup:install` + `bin/magento setup:di:compile`. Disabling **`{set_}`** breaks the install — this issue tracks the cross-module dependency so a Modulargento patch can make the set cleanly removable.

| Field | Value |
|---|---|
| Set | `{set_}` |
| Status | `{status}` |
| Phase | `{phase}` |

## Modules being removed

These are the entries that go into composer `replace` when the set is disabled. Anything in stock Mage-OS that hard-references one of these classes will break unless decoupled.

{chr(10).join(replace_lines) if replace_lines else "_(none)_"}

## Log tail (phase: `{phase_label or phase}`)

<details>
<summary>Last lines of <code>{os.path.basename(log_path) or '(missing)'}</code></summary>

```
{tail or "(log not available)"}
```

</details>

## Reproduction

```bash
git clone https://github.com/pingiun/mageos-maker
cd mageos-maker
tests/modulargento/one-shot.sh {set_}
```

_Filed automatically by `tests/modulargento/file-issues.sh` from matrix run results._
"""
sys.stdout.write(body)
PY
}

created=0
skipped=0
for line in "${rows[@]}"; do
  IFS=$'\t' read -r set status phase log_path sandbox_cj title <<<"$line"

  if grep -Fxq "$title" "$existing_titles"; then
    echo "SKIP  $title  (already open)"
    skipped=$((skipped+1))
    continue
  fi

  body="$(build_body "$set" "$status" "$phase" "$log_path" "$sandbox_cj")"

  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "----- DRY RUN -----"
    echo "Title: $title"
    echo "Body:"
    echo "$body"
    echo
    continue
  fi

  url="$(gh issue create --repo "$REPO" --title "$title" --label "$LABEL" --body "$body" 2>&1)" || {
    echo "FAIL  $title"
    echo "$url"
    continue
  }
  echo "OPEN  $title -> $url"
  created=$((created+1))
done

echo
if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "Dry run — no issues created."
else
  echo "Created: $created   Skipped (already open): $skipped"
fi

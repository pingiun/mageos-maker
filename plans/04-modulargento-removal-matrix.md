# Modulargento removal matrix — one-at-a-time set disable + DI compile

## Context

Mage-OS isn't very modular: a lot of the package sets in `definitions/sets/`
*look* like they should be removable, but in practice the rest of the codebase
holds a hard PHP dependency on a class inside the disabled set, and
`bin/magento setup:di:compile` blows up on missing classes / preferences /
plugin targets / argument types. The new
[Modulargento](https://github.com/modulargento/modulargento-magento2) project
ships small patch modules that surgically cut those dependencies. The first
patch already done: a Reports module rewrite that drops the hard requires on
Wishlist and Reviews, so those two sets can be removed cleanly.

To pick the next patch to write, we need a ranked, reproducible list of which
sets are currently un-removable and **which exact errors block them**. That's
the matrix this plan defines: for each set, disable only that set, run
`setup:di:compile`, record pass/fail + the error excerpt. It's a one-shot
diagnostic harness, not a CI test.

## What we're measuring (and what we're not)

In scope:

- **Per-set blast radius at DI-compile time.** The compile pass is the
  cheapest, most deterministic way to surface broken class references — it
  reads every `di.xml`, every constructor, every plugin and preference, and
  fails closed when something it points at no longer exists. That's exactly
  the failure mode we expect when a module's classes vanish from disk.
- **One-set-at-a-time isolation.** We're looking for "what does removing X
  alone break", not combinatorial blow-ups. The baseline is the
  `mageos-full` profile (everything on); each test disables exactly one set.
- **Error grouping.** Many sets will likely fail with the same root cause
  (e.g. half the storefront referencing a Wishlist helper). The report
  groups identical error fingerprints so we can see "fix this one preference
  and 6 sets become removable".

Out of scope for this plan:

- Combinatorial sweeps (disable 2-of-N, 3-of-N…). Useful later, but the
  one-at-a-time matrix gives us the immediate ranking. Combinatorics blow up
  the runtime by orders of magnitude and produce mostly redundant signal
  while the single-set list still has un-fixed entries.
- Runtime errors (`bin/magento setup:upgrade`, storefront page loads,
  GraphQL, REST, admin). These will catch a different and larger class of
  breakage but require a database, sample data, and per-area smoke tests.
  Add as plan 05 once 04 is acted on.
- Layers and addons. The set list is where the modularization debt is; layers
  are already designed to be opt-out and addons are opt-in.
- Profile-groups (theme/checkout). Switching theme is a different axis.

## Methodology

The harness is a small shell script + a per-test sandbox. Concrete shape:

```
tests/modulargento/
  run-matrix.sh            # iterates sets, drives one test per set
  one-shot.sh              # runs a single (set, mageos-version) test
  sandboxes/
    <set-name>/            # ephemeral Magento install, gitignored
  results/
    matrix.json            # machine-readable per-set: status + error fingerprint
    matrix.md              # human-readable rendered table (regenerated)
    raw/<set-name>.log     # full stdout+stderr of the compile run
```

### Per-test flow

For each set in `definitions/sets/*.yaml`:

1. **Generate composer.json**:
   `php artisan mageos:configure --profile=mageos-full --disable=<set> --output=sandboxes/<set>/composer.json`
2. **Materialize the sandbox**: copy `auth.json` + a pre-warmed
   `composer.lock`-from-baseline if present, then `composer install
   --no-interaction --no-dev` inside `sandboxes/<set>/`.
3. **Run the compile**:
   `bin/magento setup:di:compile 2>&1 | tee results/raw/<set>.log`.
   Wrap with a timeout (10 min) so a hung run can't stall the matrix.
4. **Classify**: exit 0 → `pass`. Non-zero → `fail`; extract the first
   "Class … does not exist" / "Source class … for … does not exist" /
   missing-preference / missing-plugin-target line as the error fingerprint
   (regex set listed below).
5. **Append result** to `results/matrix.json` as
   `{ set, status, fingerprint, log_path, duration_s }`.

After the loop, regenerate `matrix.md` from `matrix.json` grouped by
fingerprint, sorted by group size descending (biggest blockers first —
fixing the top entry unblocks the most sets).

### Baseline + smoke checks

- **Baseline run first**: `mageos-full` with nothing disabled. If this
  doesn't compile cleanly, the harness is broken; bail before iterating.
- **Sanity row**: include `--disable=wishlist` and `--disable=reviews` in the
  matrix. With Modulargento's reports patch applied these should be `pass`
  — that's the regression test for the patch and a positive control for the
  harness.
- **Negative control**: include one set we *know* is non-removable (e.g.
  `inventory` or `swatches`) and confirm it fails with a recognizable error.

### Error fingerprinting

Magento's compile errors are noisy but stereotyped. Match in this order, take
the first hit, normalize whitespace, drop the per-run paths:

```
Class "([^"]+)" does not exist
Source class "([^"]+)" for "([^"]+)" generation does not exist
Type Error occurred when creating object: ([^,]+)
The requested class did not generate properly, because the '([^']+)' file
Plugin class '([^']+)'.* doesn't exist
Preference '([^']+)' for '([^']+)'
```

Fingerprint = the captured class name(s). Two fails with the same captured
class are grouped. If nothing matches, fall back to the last 5 non-empty
lines of the log (raw, but truncated) so we still get a row.

### Caching to make this affordable

The slow steps are `composer install` (~minutes per run) and `setup:di:compile`
(~1–3 min). Two cheap wins:

- **Shared Composer cache dir**: export `COMPOSER_CACHE_DIR` to a single
  path outside the sandboxes; package downloads are reused across all 47
  runs.
- **vendor warm-start**: do one full `composer install` for the baseline,
  then for each per-set sandbox run
  `composer update --with-dependencies <only the differing packages>`
  instead of a fresh install. *Skip this in v1* — the implementation is
  fiddly and the cached download path already cuts most of the wall time.
  Add only if the matrix becomes a routine thing we re-run.

Expected wall time on first run: ~47 sets × ~4 min = ~3 hours. Re-runs with
warm Composer cache: ~47 × ~90 s ≈ 70 min. Acceptable for a diagnostic.

### Concurrency

Don't parallelize in v1. Each sandbox is a full Magento checkout — disk and
memory pressure spike fast. Single-stream is also easier to debug when a
compile hangs. Add a `-j N` flag later if matrix runs become routine.

## Output: what the matrix looks like

`results/matrix.md` is the deliverable; rough shape:

```
# Modulargento removal matrix — Mage-OS 2.2.2 — run 2026-05-03

Baseline: PASS (di:compile clean on mageos-full).

## Removable cleanly (N sets)

| Set       | Notes                              |
|-----------|------------------------------------|
| wishlist  | Modulargento reports patch active  |
| reviews   | Modulargento reports patch active  |
| swagger   |                                    |
| ...       |                                    |

## Blocked — grouped by root cause

### Group: `Magento\Wishlist\Helper\Data` missing (3 sets)
- wishlist
- (any other set whose removal exposes the same helper reference)

Excerpt:
> Class "Magento\Wishlist\Helper\Data" does not exist

### Group: `Magento\Review\Block\Form` missing (1 set)
- reviews

...

## Unclassified failures (N sets)
| Set       | Last log lines                                         |
|-----------|--------------------------------------------------------|
| paypal    | <tail>                                                 |
```

Per-cause groups give Modulargento its work queue: each group header is one
candidate patch module.

## Edge cases

- **Set has no removable effect** (e.g. all packages already replaced/absent
  in baseline). The composer.json is identical to baseline; the run will
  pass trivially. Detect by diffing emitted composer.json against baseline;
  mark such rows `noop` so they don't pollute the "removable" column.
- **Set removal pulls in version solver conflict** at `composer install`
  time (vs. a clean DI failure). Record as `composer-failed` with the
  resolver error — different fingerprint bucket from DI failures because the
  remediation is different (probably a stale `replace:` on a metapackage,
  not a code reference).
- **Set has a sub-toggle** (e.g. 2FA, recaptcha sub-modules per memory).
  v1 disables the *whole* set. A follow-up matrix can iterate sub-toggles
  inside a set independently.
- **Set is `language-*`**. Disabling a non-default language pack should
  always pass; include them so the harness shape is uniform, but don't
  count them in the "removability gain" headline.
- **Forced-on sets via profile-groups / addons.** If the active
  profile-group forces a set on, the configurator may silently re-enable it.
  Sanity-check the emitted composer.json after generation; abort with an
  explicit error if a `--disable=X` did not actually drop X's packages.

## Critical files to create

- `plans/04-modulargento-removal-matrix.md` *(this doc)*
- `tests/modulargento/run-matrix.sh`
- `tests/modulargento/one-shot.sh`
- `tests/modulargento/fingerprint.awk` *(or inline in the shell scripts;
  small enough to live in `one-shot.sh` if preferred)*
- `tests/modulargento/.gitignore` *(ignore `sandboxes/` and `results/raw/`;
  keep `results/matrix.{json,md}` checked in so the diff between runs is
  visible)*

## Verification

1. Baseline `mageos-full` compile: PASS.
2. `--disable=wishlist` and `--disable=reviews` rows: PASS (with Modulargento
   reports patch in the sandbox composer.json).
3. Negative control row (e.g. `inventory`): FAIL with a recognized
   fingerprint.
4. `matrix.md` renders with at least one error group containing more than
   one set — confirms the grouping logic works and gives Modulargento its
   first concrete patch target.
5. Re-running the matrix produces an identical `matrix.json` (modulo
   timing) — the harness is deterministic.

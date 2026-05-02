# Install-tree visualization — pre-baked dep graphs, runtime BFS

## Context

Plan 01 sketched the configurator at a high level and flagged the install-tree visualization as needing its own document. This plan fills that in. The user requirement: **as the user toggles checkboxes, the right-hand pane shows the full list of packages (including transitives) that will end up installed — instantly, with no Composer run and no network call per request.** Exact version constraint solving is explicitly *not* required; we only need to show *which* packages get pulled in. That permission unlocks an aggressive pre-bake strategy.

## What "instant" means

Server-side budget: **< 5 ms per toggle** to compute the reachable set and serialize the response. Network round-trip dominates beyond that. The benchmark below shows the chosen approach hits ~0.6 ms, so we have an order-of-magnitude headroom.

## Probed Mage-OS shape (real numbers, not estimates)

Built a representative graph by walking `https://repo.mage-os.org/include/all$<hash>.json` (8.5 MB batch file with all 399 mage-os packages × all versions inline) starting from `mage-os/product-community-edition` 2.2.2:

- **428 nodes, 2418 edges** in the mage-os subgraph alone (avg out-degree ~5.6).
- **252 of the root's 306 requires are `mage-os/*`** — exact-version pinned, so the mage-os subgraph is fully determined for a given version. Composer doesn't have to *solve* anything inside that subgraph; it just walks.
- **0 `magento/*` direct requires** in the lockfile-equivalent. Every legacy `magento/module-foo` is satisfied via a `replace` block on the corresponding `mage-os/module-foo`. The user's `replace: "*"` toggle must therefore target `mage-os/*` names — those are what's actually installed. The seed YAMLs under `definitions/sets/` already use `mage-os/*`; plan 01's example block was stale and has been corrected.
- **~67 third-party requires** (laminas, symfony, monolog, guzzle, ...). Adding their transitive closure (fetched from Packagist `p2` metadata) bumps the graph to roughly **600 nodes / ~3500 edges**.

## High-level approach

For each Mage-OS version, the catalog updater pre-bakes one `composer.lock`-equivalent into a flat JSON file under `storage/app/graphs/`. At request time, `Configurator` loads the JSON, runs an in-memory BFS from the root requires, and excludes any package the user disabled. No Composer, no Packagist, no SAT solver in the request path.

For each non-default profile-group option that adds packages (e.g. Hyvä theme adds `hyva-themes/magento2-default-theme`), we pre-bake a separate **delta** file containing the extra packages and any extra requires the option introduces. At request time we union the chosen options' deltas into the base graph before BFS.

## Storage decision: flat JSON files

Built a real graph from Mage-OS 2.2.2 data and benchmarked the three plausible storage schemes against an identical workload (BFS from root with 28 disabled packages, 1000 iterations on PHP 8.5 / SQLite 3.51):

| Approach                             | Size on disk | Per-iter cost | Notes                                              |
|--------------------------------------|--------------|---------------|----------------------------------------------------|
| JSON file: `file_get_contents` + `json_decode` + BFS | 116 KB       | **583 µs**    | The default. Simple, atomic, easy to inspect.      |
| PHP-array file: `require` + BFS      | 196 KB       | 1252 µs (CLI, no opcache) / ~100 µs (opcache hot) | Best with opcache, matches Laravel `config:cache` idiom. |
| SQLite: open + recursive CTE         | 508 KB (incl. WAL) | 1269 µs       | ~10× slower than in-memory BFS even when warm.     |
| BFS only (graph already in process memory) | —      | 104 µs        | Floor — what we get if we cache the parsed graph in a static var or APCu. |
| JSON gzipped                         | 9.5 KB       | (would add ~50 µs) | For reference; not worth the complexity at this size. |

Cross-check: the JSON BFS and SQLite recursive CTE returned identical reachable sets (400 packages out of 428). Correctness equivalent.

**Decision: flat JSON files.** Fastest cold-start option without any caching machinery, smallest mental model, atomic via temp+rename, trivial to inspect (`jq < graph.json`), trivial to invalidate (`rm`). At ~580 µs we are 8× under the budget already; further optimization is unnecessary.

If profiling later shows the visualization is contended in production, the migration path is additive: compile the same JSON to a PHP-array file at update time and `require` it instead. With opcache enabled (the php-fpm default) the load drops to near-zero. We'd keep the JSON as the canonical, human-readable form and treat the PHP file as a derived cache.

SQLite was rejected because (a) the recursive CTE is 2× slower than parse+BFS and ~10× slower than in-process BFS, (b) it would have to be a separate DB file (mixing graph cache into the user-data SQLite complicates invalidation: you can't `rm storage/app/graphs.sqlite` to force a refresh without losing saved configs), and (c) it gains us nothing — the graph fits comfortably in memory and there is no ad-hoc query workload.

## Graph format

`storage/app/graphs/<version>/base.json`:

```json
{
  "version": "2.2.2",
  "rootRequires": ["mage-os/product-community-edition"],
  "packages": {
    "mage-os/product-community-edition": {
      "version": "2.2.2",
      "type": "metapackage",
      "requires": ["mage-os/module-wishlist", "mage-os/framework", "..."],
      "replaces": []
    },
    "mage-os/module-wishlist": {
      "version": "1.0.0",
      "type": "magento2-module",
      "requires": ["mage-os/framework", "mage-os/module-catalog", "..."],
      "replaces": ["magento/module-wishlist"]
    },
    "laminas/laminas-mvc": {
      "version": "3.7.0",
      "type": "library",
      "requires": ["laminas/laminas-eventmanager", "..."],
      "replaces": []
    }
  }
}
```

Choices baked in:
- **Edges are unversioned package names.** The user said exact version matching isn't needed; we trust the pre-baked lock to have done the resolution once. Reverting this later (storing constraints) is purely additive.
- **Platform requires (`php`, `ext-*`, `lib-*`) are stripped.** They aren't packages and clutter the visualization. Surface them in a separate "platform requirements" block on the UI (sourced from the root meta-package's raw requires).
- **`replaces` is preserved** so the UI can show "this package also satisfies `magento/module-wishlist`" if useful, and so the configurator can validate that user-disabled-set names map to packages that actually exist somewhere in the graph.
- **`type` is preserved** — UIs can group `magento2-module` separately from `library` and `metapackage`.
- **Devs deps are not included.** This matches a real `composer install --no-dev` and is what production projects do.
- **No `version` resolution at runtime**, just display.

`storage/app/graphs/<version>/options/<group>/<option>.json` (delta for non-default profile-group options):

```json
{
  "version": "2.2.2",
  "appliesTo": { "group": "theme", "option": "hyva" },
  "addRequires": ["hyva-themes/magento2-default-theme"],
  "addPackages": {
    "hyva-themes/magento2-default-theme": { "version": "1.3.4", "type": "magento2-theme", "requires": ["hyva-themes/magento2-theme-module", "..."], "replaces": [] },
    "hyva-themes/magento2-theme-module":  { "version": "1.3.4", "type": "magento2-module", "requires": ["mage-os/framework"], "replaces": [] }
  }
}
```

`addRequires` extends the graph's `rootRequires`. `addPackages` is union'd into `packages`. Conflicting entries (same key, different versions in two deltas) are last-write-wins for visualization purposes and are flagged at pre-bake time so the maintainer knows the affected option pair will not visualize 100% accurately. They will still install correctly because real Composer arbitrates at install time.

## Pre-bake pipeline

Lives inside `mageos:catalog:update` (the existing command from plan 01). After fetching `packages.json`:

1. **Diff version list.** For every version of `mage-os/project-community-edition` not already on disk under `storage/app/graphs/<version>/base.json`, schedule a bake.
2. **Bake `base.json`** (per new version):
   - Materialize a temporary directory with a stock `composer.json` that requires only `mage-os/project-community-edition: <version>`.
   - Run `composer update --no-install --no-dev --prefer-stable` in that directory using a sandboxed COMPOSER_HOME.
   - Read the resulting `composer.lock`, walk `packages[]`, and emit our format.
   - For mage-os packages already in the cached `all.json` we use that data directly (saves 252+ HTTP fetches per version). Only third-party metadata comes from the live Composer run.
3. **Bake option deltas** (per new version × per profile-group option whose YAML has `enables.requires` or `addPackages`):
   - Same procedure, but the temp `composer.json` also includes the option's extra requires.
   - Diff the resulting lock against `base.json` to extract `addRequires` and `addPackages`.
4. **Atomic publish:** write each file as `<path>.tmp`, then `rename()` over the destination (POSIX-atomic on local FS). Old versions' graphs are kept indefinitely — saved snapshots may pin to them.
5. **Garbage collection:** when a version disappears from the catalog *and* no saved config references it (query `saved_configs.version`), delete its directory.

Total Composer runs per catalog update: 1 base + N option-deltas per *new* version. With our seed (theme + checkout, one non-default option each) that's 3 runs per version. The runs are independent and can be parallelized; serial is fine for now (each run is ~30 s on a warm Composer cache).

A failed bake leaves the previous `base.json` in place and logs an error. The configurator falls back to the most recent successfully-baked version when the user-selected version has no graph, with a banner in the UI explaining the staleness. Cron retries on the next tick.

## Runtime: load + BFS

`app/Services/InstallTreeResolver.php` (new, alongside `Configurator.php`):

```php
public function resolve(SelectionState $sel): array {
    $g = $this->loadBase($sel->version);                       // ~250 µs (cached file read + json_decode)
    foreach ($sel->profileGroups as $group => $option) {
        if ($this->isDefault($group, $option)) continue;
        $delta = $this->loadDelta($sel->version, $group, $option);
        $g['rootRequires'] = array_unique([...$g['rootRequires'], ...$delta['addRequires']]);
        $g['packages']     = $delta['addPackages'] + $g['packages']; // delta wins on conflict
    }
    $disabled = $this->disabledPackages($sel);                 // map<package, true>
    return $this->bfs($g, $disabled);
}
```

`bfs()` is the standard iterative form: an array used as a FIFO with an integer head pointer (avoids `array_shift` cost), a `visited` map, skip nodes in `$disabled`. Returns the visited keys in insertion order, which the UI groups by `type` for rendering. For our 428-node graph: ~100 µs.

`loadBase` and `loadDelta` cache the parsed array in a static class property keyed by `(version, group, option)`. Within a single PHP process every call after the first is a hash lookup. With php-fpm worker reuse this means most requests skip the parse entirely.

## Replace / provide / conflict semantics

These are handled at **pre-bake time**, not runtime:

- **`replace`**: the resolved lock produced by Composer already excludes packages that are replaced by another. They never enter our graph.
- **`provide`**: same — Composer's resolver decides what's provided; we only see the resulting installed set.
- **`conflict`**: by the time we have a resolved lock, all conflicts have been resolved (or the resolution failed and we don't bake). At runtime we don't re-check conflicts.

The user's *disable* mechanism (set/layer toggling) maps to `replace: "*"` in the user's emitted `composer.json`. For visualization, this is just BFS pruning. We never have to model the user's replaces in the graph itself — the graph is the *post-resolution* shape, and the disable list is the runtime mask.

## Edge cases

- **Conditional requires** (`require-dev`, platform branches): we only bake the `--no-dev` runtime closure. Platform variants (`php: ~8.1.0||~8.2.0`) don't affect node identity — we ignore them entirely.
- **A profile-group option introduces a package that also exists in `base.json` at a different version:** delta wins on the merge for display. Logged at bake time. Real `composer install` would arbitrate at install; this is a known fidelity gap and acceptable for visualization.
- **A user disables a set that is required by another set they kept enabled:** BFS still skips it; the install tree shows the orphan-required package as missing. The configurator's emit step should warn ("disabling `wishlist` may break dependents that require `magento/module-wishlist`"), but that's the configurator's concern, not the visualization's.
- **First-install fallback (catalog never ran):** ship a baked `base.json` for the latest stable version known at release time. Same fallback story as the catalog itself.
- **Cycles:** the resolved lockfile graph is theoretically a DAG, but composer allows cycles in some package metadata. BFS with a `visited` set handles cycles correctly regardless.
- **Very large graphs (future):** if we ever exceed ~10 K nodes per version, revisit. The current measurements give two orders of magnitude of headroom.

## File layout

```
storage/app/graphs/
  2.2.2/
    base.json                                  # ~120 KB
    options/
      theme/hyva.json                          # ~5–20 KB
      checkout/hyva-checkout.json              # ~5–20 KB
  2.2.1/
    base.json
    options/...
  ...
```

`.gitignore`d under `storage/app/graphs/` (this is a cache, regenerated by cron). Total disk for 17 versions × ~150 KB ≈ 2.5 MB — trivial.

## Out of scope

- **Verifying the bake against a real `composer install`** every time — covered manually as part of the verification step below; not run on every cron tick.
- **Showing version constraints in the UI** — the user explicitly asked for "what extra requirements are required", not constraint solving. Adding constraint hover tooltips later is purely additive.
- **Visualizing the user's own added packages** (third-party Magento extensions they intend to install on top). The configurator's scope is the Mage-OS skeleton; their extras live outside.
- **Live Packagist mirroring** — the third-party metadata we cache is whatever the bake-time Composer run produced. If Packagist itself is down at bake time the bake fails and we keep the previous graph; we don't run our own Packagist mirror.

## Critical files to create

- `app/Services/InstallTreeResolver.php` — load + BFS; the runtime side.
- `app/Services/GraphBaker.php` — invoked from `CatalogUpdateCommand`; runs sandboxed Composer, converts `composer.lock` to our format, computes deltas. The heavy logic.
- `app/Console/Commands/CatalogUpdateCommand.php` — extended to invoke `GraphBaker` for each new version + each profile-group option.
- `app/Http/Controllers/ConfiguratorController.php` — extended to call `InstallTreeResolver::resolve()` from the live preview endpoint and serialize the package list alongside the rendered `composer.json`.
- `resources/views/configurator/index.blade.php` — install-tree pane (search box, count badge, grouped by package type), updated via the same `fetch` POST that updates the composer.json preview.
- `tests/Unit/InstallTreeResolverTest.php` — Pest unit tests with a fixture graph (small hand-written one, ~10 nodes).
- `tests/Feature/GraphBakerTest.php` — runs against a real fixture composer.lock checked into `tests/fixtures/`.

## Verification

1. **Pre-bake produces correct content:** in `tests/fixtures/` keep a real `composer.lock` from a stock `mage-os/project-community-edition: 2.2.2` install. `GraphBaker` against this fixture produces a `base.json` whose `packages` keys match `composer.lock`'s `packages[].name` exactly. Run as a feature test.
2. **BFS matches reachability:** for the same fixture, BFS with empty disabled set returns every package in the lock. With `mage-os/module-wishlist` disabled, the result equals `lock.packages \ { wishlist + anything reachable only via wishlist }` — checked against an authoritative computation.
3. **Live verification (manual, quarterly):** in a clean directory, `composer install` the user's emitted `composer.json` for a representative selection (Hyvä theme, wishlist disabled). Compare `composer.lock`'s package list against the install-tree resolver's output for the same selection. They should match modulo platform requires and ordering. Discrepancies become regression tests.
4. **Performance regression:** a Pest test asserts `InstallTreeResolver::resolve()` for the seeded 2.2.2 graph completes in under 5 ms, measured with `hrtime()`. CI fails if it doesn't.
5. **Web smoke:** `php artisan serve`, toggle wishlist on/off — install-tree count badge updates within one frame, no perceivable lag, no XHR pending in dev tools longer than ~30 ms.

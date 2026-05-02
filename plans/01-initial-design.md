# mageos-maker — composer.json configurator for Mage-OS

## Context

Mage-OS users currently start from `mage-os/project-community-edition`, which pulls in every module by default. Customizing the install (turning off wishlist, GraphQL, swapping Luma for Hyvä, etc.) means hand-editing `composer.json` and knowing the right `replace` incantations. `mageos-maker` is a small PHP tool — **web UI + CLI** — that lets a user pick high-level options ("modules", "layers", "theme") and emits a tailored `composer.json`. The tool keeps itself current by watching `repo.mage-os.org/packages.json` so the default targeted Mage-OS version tracks upstream releases.

## Feasibility check — Mage-OS Composer repo API

Probed `https://repo.mage-os.org/packages.json` directly. Findings that shape the design:

- **Manifest is small and stable**: 18 KB, ETag/Last-Modified headers present — cheap to poll. Uses Composer v2 layout: `metadata-url: "/p2/%package%.json"` for per-package metadata, plus a single batch `includes` file (`include/all$<hash>.json`, **~8.5 MB**) containing every package version inline.
- **Closed package universe**: `available-packages` lists exactly **399 mage-os packages** — we can enumerate the entire Mage-OS universe in one fetch. No package patterns / wildcards.
- **`mage-os/project-community-edition`**: 17 versions (1.0.0 → 2.2.2). Latest top-level `require` only 3 packages, the meaningful one being `mage-os/product-community-edition`.
- **`mage-os/product-community-edition`** (the real meta-package): 321 requires — **252 mage-os packages with exact-version pins** (`"mage-os/module-foo": "2.2.2"`), 69 third-party (`ext-*`, `composer/composer`, `laminas/*`, `guzzlehttp/guzzle`, etc., mostly with ranges like `^1.2`).
- **Implication for the dep-graph approach**: because Mage-OS internal packages are pinned to exact versions, **graph reachability over the mage-os subgraph requires no SAT solving** — given a Mage-OS version, the entire tree of mage-os packages is fully determined. Disable-by-replace simply prunes nodes; the rest of the graph stays valid. This is the core reason the install-tree visualization can be instant.
- **Third-party deps need real resolution**: the 69 non-mage-os requires use ranges and depend on Packagist. We resolve these **once per Mage-OS version** at catalog-update time (run `composer update --no-install` against a stock `project-community-edition` of that version; capture the resulting lockfile). Stored as `graphs/<version>.lock.json`. At runtime we never resolve — we only walk and prune.
- **Storage budget**: catalog ~8.5 MB + ~17 lockfiles × ~2 MB each ≈ ~40 MB total for the full version history. Trivial.
- **Self-update is straightforward**: poll `packages.json` weekly, diff `available-packages`, re-extract versions of `mage-os/project-community-edition`. Use the ETag for cheap "no change" responses.
- **Risk to flag**: third-party transitive resolution is the only piece that needs a real Composer run; everything else is JSON munging. The pre-bake job must invoke real Composer (in a sandbox) — it is the one heavy operation, but it runs offline in the cron, not in the request path.

**Verdict: feasible as designed.** The pinned-version structure is a meaningful tailwind for the offline-graph approach; we don't have to ship a Composer solver in the request path.

## Stack

- **PHP 8.4+**
- **Laravel** (latest) — drives the web UI; its bundled Symfony Console powers the CLI
- **SQLite** — saved configurations (no auth)
- **Bundled YAML** — package-set / layer / theme definitions, versioned with the tool

## Core concepts

- **Package-set (module)**: named group of composer packages — e.g. `wishlist`, `inventory`, `gift-card`, `hyva`. Disabling a set adds every package in it to `composer.json`'s `replace` section as `"vendor/pkg": "*"`. The base `require` keeps `mage-os/project-community-edition: <version>`.
- **Layer**: orthogonal cross-cut — e.g. `graphql`, `hyva-compat`. Same disable mechanism as a set; conceptually separate so users can disable GraphQL across all enabled modules.
- **Profile group**: a named "pick exactly one" choice — e.g. `theme` (luma | hyva), `checkout` (default | hyva-checkout | ...). Each option in a group is a composition that enables/disables sets and layers. Every profile group declares a `default` option, so the user always has a valid selection without having to pick. Themes and checkouts are just two instances of this same mechanism — adding a new profile group (e.g. search backend, payment bundle) is purely a YAML change.
- **Profile (top-level / starter preset)**: a coarse-grained one-click starting point that seeds the entire selection — e.g. `mageos-full` (everything on), `mageos-lite` (bare-minimum webshop), `mageos-framework` (no e-commerce, just the framework). Picking a profile populates the form with that profile's set/layer/profile-group choices; the user is then free to toggle any individual checkbox afterwards (profile = seed, not lock). Internally a profile is just a named selection-state stored in YAML.
- **Catalog**: cached snapshot of `repo.mage-os.org/packages.json`, refreshed by a scheduled job. Provides the version dropdown and the "current default" version.
- **Selection state**: `{ version, profile: "mageos-full", enabledSets[], disabledSets[], enabledLayers[], disabledLayers[], profileGroups: { theme: "luma", checkout: "default", ... } }`. Default = latest stable, profile `mageos-full`, all sets enabled, every profile group on its declared default. The `profile` field records *which starter the user picked* (so the UI can highlight it and the saved snapshot can show "based on mageos-lite + customizations"); the actual toggles live in the explicit lists.

## Directory layout

```
mageos-maker/
  app/
    Console/Commands/
      ConfigureCommand.php       # interactive + flag-driven CLI
      CatalogUpdateCommand.php   # invoked by cron (php artisan mageos:catalog:update)
    Http/Controllers/
      ConfiguratorController.php # web UI: index, show saved, save
    Services/
      CatalogRepository.php      # fetches & caches packages.json, exposes versions
      DefinitionLoader.php       # reads YAML, resolves theme compositions
      Configurator.php           # selection state -> composer.json array
      ComposerJsonRenderer.php   # array -> pretty JSON string
    Models/
      SavedConfig.php            # uuid, version, selection JSON, created_at
  resources/
    definitions/
      sets/                      # one YAML per set (wishlist.yaml, inventory.yaml, ...)
      layers/                    # graphql.yaml, hyva-compat.yaml
      profile-groups/            # one YAML per group: theme.yaml, checkout.yaml, ...
      profiles/                  # starter presets: mageos-full.yaml, mageos-lite.yaml, mageos-framework.yaml
    views/
      configurator/index.blade.php
  database/migrations/           # saved_configs table
  storage/app/catalog.json       # cached packages.json (gitignored)
  bin/mageos-maker               # thin wrapper -> php artisan mageos:configure
  routes/web.php
  config/mageos.php              # catalog URL, TTL hints, package-edition name
```

## YAML schema (example)

`resources/definitions/sets/wishlist.yaml`:
```yaml
name: wishlist
label: Wishlist
description: Customer wishlist functionality
packages:
  - magento/module-wishlist
  - magento/module-wishlist-analytics
  - magento/module-wishlist-graph-ql
```

`resources/definitions/profile-groups/theme.yaml`:
```yaml
name: theme
label: Theme
options:
  - name: luma
    label: Luma
    default: true
    # default option: no toggles needed (everything's enabled by default)
  - name: hyva
    label: Hyvä
    enables:
      sets: [hyva]
      layers: [hyva-compat]
    disables:
      sets: [luma]
```

`resources/definitions/profile-groups/checkout.yaml`:
```yaml
name: checkout
label: Checkout
options:
  - name: default
    label: Magento default
    default: true
  - name: hyva-checkout
    label: Hyvä Checkout
    enables:
      sets: [hyva-checkout]
    disables:
      sets: [magento-checkout]
```

`resources/definitions/profiles/mageos-lite.yaml`:
```yaml
name: mageos-lite
label: Mage-OS Lite
description: Bare-minimum webshop — only essentials enabled
selection:
  enabledSets: [catalog, customer, sales, checkout-core]
  disabledSets: [wishlist, gift-card, reward-points, inventory, staging]
  disabledLayers: [graphql, admin-graph-ql]
  profileGroups:
    theme: luma
    checkout: default
```

## Composer.json emission

- `require`: `{ "mage-os/project-community-edition": "<chosen version>" }` plus any extra packages a set explicitly adds (rare; mostly themes like Hyvä).
- `replace`: every package belonging to a disabled set or disabled layer, mapped to `"*"`. Sorted alphabetically, deduplicated.
- Pretty-printed with 2-space indent, 4-space for keys following composer convention.

## Web UI

- Top of form: **Profile picker** (radio: mageos-full default | mageos-lite | mageos-framework) — selecting one re-seeds the rest of the form. Picking any individual checkbox afterwards is fine; the profile pill stays as a "started from" hint.
- Single-page Blade view: version dropdown, profile picker, set checkboxes (grouped), layer checkboxes, plus one radio group per profile-group (theme, checkout, ...) rendered dynamically from the YAML.
- **Install-tree pane** (right column, alongside or below the composer.json preview): shows the resolved list of all packages (including transitive deps) that will be installed, with a count and a search/filter box. Updates live with each toggle. Powered by the offline dep-graph described below — no network call per toggle.
- Live preview pane (right column) shows the rendered `composer.json` with a **Copy** button. Update via vanilla `fetch` POST to `/preview` returning the JSON string — no SPA framework.
- **Save** button persists the snapshot (version + selections) to SQLite, redirects to `/c/{uuid}`.
- `/c/{uuid}` reloads the same view pre-filled from the snapshot. Snapshot's version is preserved verbatim — even if the catalog has moved on.

## CLI

- `php artisan mageos:configure` — interactive prompts (Laravel Prompts package).
- Non-interactive flags: `--version=`, `--enable=set1,set2`, `--disable=set3`, `--enable-layer=`, `--disable-layer=`, `--profile=theme:hyva,checkout:hyva-checkout` (repeatable per group), `--output=composer.json` (defaults to stdout).
- `bin/mageos-maker` is a thin shim that execs `artisan mageos:configure "$@"` so end users don't type `artisan`.
- `php artisan mageos:catalog:update` — fetches packages.json, writes `storage/app/catalog.json`. Intended target for user's cron.

## Catalog

- `mageos:catalog:update` (cron-driven) does:
  1. `HEAD`/conditional `GET` on `https://repo.mage-os.org/packages.json` using stored ETag — short-circuit on 304.
  2. On change: fetch the manifest + the referenced `include/all$*.json` batch file (~8.5 MB). Store both under `storage/app/catalog/`.
  3. Extract the list of `mage-os/project-community-edition` versions; for each *new* version, invoke a sandboxed Composer to resolve third-party transitives once and store `storage/app/graphs/<version>.lock.json`. Old versions' lockfiles are kept (saved snapshots may pin to them).
- `CatalogRepository` reads the cached manifest + batch file. Exposes: `availableVersions()`, `latestStable()`, `packageDefinition(name, version)`, `requiresOf(name, version)`.
- If the cache is missing, return a hardcoded fallback (latest known version baked in at release time) and log a warning — keeps the tool usable on first install before the cron has run.
- Versions sorted with `composer/semver`. Default selected version = highest stable; pre-releases reachable via the dropdown.

## Install-tree visualization (separate planning needed)

The user wants the full list of packages (including transitive deps) for any selection rendered **instantly** — no on-demand `composer update` and no per-request package downloads. Plan this as its own document; the high-level approach baked into the catalog update job:

**Strategy: pre-baked dependency graph per Mage-OS version, runtime graph walk.**

- The `mageos:catalog:update` job, after fetching `packages.json`, runs a **one-time offline resolve** for each Mage-OS version's stock `mage-os/project-community-edition` composer.json. This produces a canonical `composer.lock`-equivalent that we keep on disk (`storage/app/graphs/<version>.json`).
- The job then walks every package's metadata (already in `packages.json` plus a mirrored Packagist metadata cache) and builds a **dependency graph**: nodes = locked package versions, edges = `requires`. Stored as a flat JSON: `{ rootRequires: [...], packages: { name: { version, requires: [...] } } }`.
- At selection time the `Configurator` performs a **reachability walk**: seed = enabled root requires (project-community-edition + any extra packages from enabled profile-group options), exclude any package that is in a disabled set/layer, then BFS over `requires`. The reachable set = the install tree. Pure in-memory PHP, milliseconds.
- This is reachability, not re-resolution: it's correct as long as version constraints in the locked graph stay satisfied when packages are removed (true for the Magento module ecosystem because the meta-package pins everything explicitly). Edge cases (packages with conditional requires, platform constraints) are flagged in the separate plan.
- For non-Mage-OS transitive deps (Symfony, monolog, etc.), the catalog updater pre-fetches their metadata from Packagist's metadata-only endpoint (`https://repo.packagist.org/p2/<vendor>/<package>.json`) — metadata only, no zips. Cached alongside the graph.
- **Storage**: roughly a few MB per Mage-OS version. Cleaned up by the same updater when versions drop out of the catalog.

What the separate plan must cover: exact graph format, handling of `replace`/`provide`/`conflict`, platform constraints, dev-deps vs runtime, Packagist mirror cadence, fallback when a chosen profile-group option introduces a package not present in the pre-resolved graph (re-resolve at update time, not at request time), and verification against a real `composer install`.

## Seed definitions (initial)

Sets: `wishlist`, `inventory`, `gift-card`, `reward-points`, `staging`, `hyva`, `hyva-checkout`, `luma`, `magento-checkout`.
Layers: `graphql`, `hyva-compat`, `admin-graph-ql`.
Profile groups: `theme` (luma default | hyva), `checkout` (default | hyva-checkout).
Profiles: `mageos-full` (default, everything on), `mageos-lite` (essentials only), `mageos-framework` (no e-commerce).

Exact package lists derived by inspecting `mage-os/project-community-edition`'s `composer.json` and grouping `magento/module-*` by feature area. Done as part of implementation, not exhaustive — easy to extend.

## Out of scope for this plan (separate planning doc)

- **Install-tree visualization & dep-graph pre-bake pipeline** — sketched above; needs its own plan covering graph schema, replace/provide/conflict semantics, Packagist metadata mirroring, and verification.

## Critical files to create

- `composer.json` (root) — project deps: `laravel/framework`, `laravel/prompts`, `symfony/yaml`, `composer/semver`, `guzzlehttp/guzzle`.
- `app/Services/Configurator.php` — pure function over selection state; the heart of the tool.
- `app/Services/DefinitionLoader.php` — loads sets, layers, and profile groups; resolves the user's profile picks into concrete set/layer toggles before handing to `Configurator`.
- `app/Services/CatalogRepository.php`.
- `app/Console/Commands/ConfigureCommand.php`, `CatalogUpdateCommand.php`.
- `app/Http/Controllers/ConfiguratorController.php` + `routes/web.php` + `resources/views/configurator/index.blade.php`.
- `database/migrations/xxxx_create_saved_configs_table.php` (uuid PK, version string, selection JSON, timestamps).
- YAML seeds under `resources/definitions/`.

## Verification

1. `composer install && php artisan migrate`.
2. **CLI smoke test**: `bin/mageos-maker --disable=wishlist --profile=theme:hyva,checkout:hyva-checkout` → stdout shows `composer.json` with `mage-os/project-community-edition` in `require` and `magento/module-wishlist*` + Luma + magento-checkout packages in `replace`; Hyvä + hyva-checkout packages in `require`.
3. **Catalog update**: `php artisan mageos:catalog:update` → `storage/app/catalog.json` exists, contains a recent `mage-os/project-community-edition` version. Re-running CLI with no `--version` picks that version up.
4. **Web flow**: `php artisan serve`, visit `/`, toggle wishlist + switch to Hyvä → preview pane updates and matches CLI output for the same selections. Click **Save** → redirected to `/c/{uuid}`; reopen URL in a new browser → selections + version restored exactly.
5. **Snapshot durability**: bump catalog cache to a newer fake version, reload `/c/{uuid}` → still shows the originally-saved version (snapshot honored).
6. **Unit tests** (Pest) for `Configurator`: covers default-all-enabled, single set disabled, profile composition (Hyvä theme disables Luma + enables hyva-compat layer), independent profile groups (switching checkout doesn't affect theme), and replace-section sorting/dedup.

# Loki Checkout integration — Hyvä + Luma variants with theme gating

## Context

Loki Checkout ships **two mutually exclusive variants** that target different storefronts:

- **`loki-checkout/magento2-hyva`** — already wired up (`definitions/addons/loki-checkout.yaml`, picked via `checkout=loki-checkout`). Requires the Hyvä theme.
- **`loki-checkout/magento2-luma`** — not yet wired. Replaces Magento's stock Luma checkout. Has an optional but recommended companion module `loki-theme/magento2-luma-components` that strips out unused Luma JavaScript.

Both come from the same `composer.yireo.com` Composer repository (already declared on the existing addon). The docs explicitly warn against enabling the wrong variant for the active theme.

Two configurator capabilities are missing today:

1. **Conditional availability of profile-group options** — a checkout option needs to be greyed out / non-pickable when a different profile-group is in an incompatible state (theme=hyva vs theme=luma). Currently every option in a profile-group is always pickable.
2. **Subtoggles on profile-group options** — analogous to set-level subtoggles (already shipped for 2FA): a sub-checkbox under a picked option that controls a single optional package without splitting the option into multiple radio entries.

Both mechanisms have uses beyond Loki (any future "this option only makes sense with X theme" or "this option has an optional companion package" lands here too), so they're worth generalizing rather than special-casing Loki.

## Proposed changes

### 1. Option-level `requires` constraint

Schema addition for profile-group option YAML:

```yaml
- name: loki-checkout-hyva
  label: Loki Checkout
  requires:
    profileGroups:
      theme: hyva
  enables:
    addons: [loki-checkout-hyva]
```

`requires.profileGroups` is a map of `<groupName> → <optionName>` that must currently be selected for this option to be valid. Multiple keys = AND (all must hold).

**Runtime behaviour:**
- The view renders the option's radio button as `disabled` when its `requires` map isn't satisfied by the current `profileGroups` state. A small "(needs theme = Hyvä)" hint renders next to the disabled label.
- The Configurator service treats a violation as "selection invalid for this option" and falls back to the group's default when emitting `composer.json`. (Belt-and-braces — the UI prevents picking, but a saved-config replay or a CLI flag combo could still produce a contradiction.)
- Switching a profile-group to a state that invalidates a currently-selected option in another group **auto-snaps** the dependent group back to its default. Example: user has theme=Hyvä + checkout=Loki (Hyvä). User switches theme=Luma → checkout snaps to Luma default; show a transient flash explaining the change. (Alternative: keep the picked option but flag the contradiction. Auto-snap is more forgiving and matches how the soft-default mechanism for addons already behaves.)

### 2. Option-level `subtoggles`

Schema addition mirroring the existing set-level subtoggle shape:

```yaml
- name: loki-checkout-luma
  label: Loki Checkout
  requires:
    profileGroups:
      theme: luma
  enables:
    addons: [loki-checkout-luma]
  subtoggles:
    - name: luma-components
      label: Strip unused Luma JS (LokiTheme_LumaComponents)
      description: Optional companion module that removes excess Luma JavaScript
      addons: [loki-luma-components]
      default: true        # checked by default; unchecking keeps the option but skips the addon
```

Each subtoggle pulls in one or more **addons** (not raw packages — reuse the addon mechanism for the install/repository plumbing). The subtoggle is rendered as a checkbox indented under the option's radio, only visible/enabled when the parent option is the currently-picked radio.

**Selection storage:** extend `Selection` with `enabledOptionSubtoggles: list<string>` keyed as `<groupName>.<optionName>.<subName>` — positive list (matches the addon convention; option subtoggles are opt-in by default unless the YAML sets `default: true`, in which case they're soft-defaulted on, mirroring addon soft-defaults).

**Why this isn't just "two more radio options":** "Loki (Luma) lean" + "Loki (Luma) full" as separate radios doubles the radio list and forces the user through a deceptive choice. A subtoggle communicates "this is the same product, with an optional piece" — which matches Loki's docs.

### 3. Two new addon definitions

```
definitions/addons/loki-checkout-luma.yaml
  packages: [loki-checkout/magento2-luma]
  repositories: [{type: composer, url: https://composer.yireo.com/}]

definitions/addons/loki-luma-components.yaml
  packages: [loki-theme/magento2-luma-components]
  repositories: [{type: composer, url: https://composer.yireo.com/}]
```

Repository deduplication is already handled by `Configurator::appendRepositories()` so all three Loki addons listing the same Yireo repo is fine.

### 4. Rename the existing Loki option

`checkout: loki-checkout` → `checkout: loki-checkout-hyva`. Add the `requires.profileGroups.theme: hyva` constraint. The matching addon stays `loki-checkout` (or rename to `loki-checkout-hyva` for symmetry — minor breaking change to saved configs that selected the current option, which is acceptable given the feature is a few hours old and probably has no real users).

## Where edits land

- **`app/Services/Definitions.php`** — pass through `requires` and `subtoggles` on profile-group options (just structural, no helper logic needed in the type).
- **`app/Services/Configurator.php`** — in `resolveProfileGroups()`, validate `requires` and silently skip an invalid option's effects (treat as default). In `build()`, fold option-subtoggle addons into `effectiveAddons` when the parent option is picked AND the subtoggle is on.
- **`app/Services/Selection.php`** — add `enabledOptionSubtoggles` field; default/from/to/applyProfile as for `disabledSubtoggles`. Soft-default subtoggles with `default: true` apply on hydrate-from-default.
- **`app/Services/InstallTreeResolver.php`** — extend `disabledPackageMap` / additive walk to honor option-subtoggle addon packages.
- **`app/Livewire/Configurator.php`** — track `enabledOptionSubtoggles`; auto-snap a group to its default when its current option's `requires` becomes unsatisfied (hook into the existing `updated()` profile-group dispatcher); pass an `optionAvailability` map to the view.
- **`resources/views/livewire/configurator.blade.php`** — render disabled radios with a hint; render subtoggle checkboxes nested under radios; show a flash when an auto-snap occurs.
- **`definitions/profile-groups/checkout.yaml`** — rename `loki-checkout` to `loki-checkout-hyva`, add `requires`, add the new `loki-checkout-luma` option with its `subtoggle`.
- **`definitions/addons/`** — add the two new Luma-side addon YAMLs.

## Edge cases & decisions

- **User picks Loki (Luma) → switches theme to Hyvä:** auto-snap checkout back to default (Luma checkout). User keeps the Loki (Hyvä) variant in the radio list and can re-pick if they meant to. Flash banner: *"Checkout reset to default — Loki (Luma) requires the Luma theme."*
- **Saved-config replay with a now-invalid combination:** the Configurator service emits the default option for the affected group and logs once. The saved config still loads; the user can fix it in the UI.
- **A subtoggle's addon adds a `requires`-satisfied optional repository:** repository dedup handles it; nothing extra needed.
- **`default: true` subtoggle when the option is not the currently-picked radio:** subtoggle state is irrelevant — Configurator only consults subtoggles whose parent option is active. Storing them as enabled when the option isn't picked is harmless (just dormant).
- **Two subtoggles on different options of the same group:** each is independent because only the picked option's subtoggles count. Cross-talk impossible.
- **Composer-level conflict between Loki (Hyvä) and Hyvä Checkout addon:** they're both in the same `checkout` profile-group → radio-exclusive at the UI level. No further work needed.

## Test plan additions

- `tests/Unit/OptionRequiresTest.php` — option with `requires.profileGroups.theme: hyva` is filtered out by Configurator when theme is Luma, picked up when theme is Hyvä.
- `tests/Unit/OptionSubtoggleTest.php` — subtoggle on an option pulls its addon into `require` only when both (a) parent option is picked and (b) subtoggle is on; subtoggle state ignored when parent not picked.
- Extend `SubtoggleTest` to cover that set-subtoggles and option-subtoggles share no state.
- `tests/Feature/LokiCheckoutTest.php` — end-to-end via the configurator: theme=Hyvä + checkout=Loki(Hyvä) → composer require contains `loki-checkout/magento2-hyva` and the Yireo repo; theme=Luma + checkout=Loki(Luma) + subtoggle on → contains both `magento2-luma` and `magento2-luma-components`; subtoggle off → `magento2-luma` only.
- Existing `Configurator` smoke (composer.json shape) still passes.

## Out of scope for this plan

- **Multi-AND constraints across more than `profileGroups`** (e.g. "requires this addon to be enabled"): straightforward additive extension, defer until a real second use case shows up.
- **Cross-group exclusion** ("checkout=X disables theme=Y"): the auto-snap covers the inverse direction (theme change snaps checkout); two-way exclusion is overkill.
- **A "Loki" umbrella that automatically picks the right variant for the chosen theme**: rejected. Hides which package is being installed; harder to understand from the emitted composer.json.

## Critical files to create

- `plans/03-loki-checkout.md` *(this doc)*
- `definitions/addons/loki-checkout-luma.yaml`
- `definitions/addons/loki-luma-components.yaml`
- `tests/Unit/OptionRequiresTest.php`
- `tests/Unit/OptionSubtoggleTest.php`
- `tests/Feature/LokiCheckoutTest.php`

## Verification

1. CLI emits the right composer.json for both theme/checkout combinations and the subtoggle on/off case (covered by the feature test).
2. Web: switching theme between Luma and Hyvä auto-snaps checkout when needed; flash banner appears.
3. Web: the disabled radio renders greyed with a "needs theme = X" hint and is non-clickable.
4. Install-tree pane reflects the addon packages immediately on each toggle.
5. Save+reload (`/c/{uuid}`) round-trips the option-subtoggle state.

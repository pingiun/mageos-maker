<?php

namespace App\Livewire;

use App\Models\SavedConfig;
use App\Services\CatalogRepository;
use App\Services\ComposerJsonRenderer;
use App\Services\Configurator as ConfiguratorService;
use App\Services\Definitions;
use App\Services\InstallTreeResolver;
use App\Services\Selection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Full-page Livewire component that owns the configurator form state.
 *
 * The view models stock items as ENABLED-by-default (lists hold "currently checked"
 * items), which makes Livewire's array-style checkbox bindings the natural fit:
 * a checkbox is checked iff its value is in the bound array. The Selection passed
 * to ConfiguratorService is built in {@see selection()} by computing the disabled
 * complements over the universe of stock names.
 */
class Configurator extends Component
{
    public ?string $version = null;

    public ?string $profile = null;

    /** @var list<string> Stock set names currently enabled (checked). */
    public array $enabledSets = [];

    /** @var list<string> Stock layer names currently enabled (checked). */
    public array $enabledStockLayers = [];

    /** @var list<string> Add-on names currently enabled (checked). Soft-defaults flow into here via {@see updatedProfileGroups()}. */
    public array $enabledAddons = [];

    /** @var array<string,string> Profile-group choices: ['theme' => 'luma', 'checkout' => 'default', ...] */
    public array $profileGroups = [];

    /** @var list<string> Subtoggle keys ("setName.subName") currently enabled. Universe minus this = disabled. */
    public array $enabledSubtoggles = [];

    /** @var list<string> Profile-group option subtoggle keys ("group.option[.variant].sub") currently enabled. Positive list. */
    public array $enabledOptionSubtoggles = [];

    /** Last user-visible auto-snap message, e.g. "Checkout reset to default — Loki (Hyvä) requires the Hyvä theme." */
    public ?string $autoSnapNotice = null;

    public ?string $savedId = null;

    public ?string $savedAt = null;

    // Hyvä credentials are intentionally NOT part of Selection — they must never be
    // persisted to SavedConfig or echoed back via the shared /c/{id} link.

    /** Hyvä packagist authentication token (free, obtained at hyva.io). */
    public string $hyvaToken = '';

    /** Hyvä packagist project name slug (the path component of your composer repo URL). */
    public string $hyvaProject = '';

    /**
     * Defaulted-addons list from the previous resolved state, persisted across
     * Livewire requests so we can diff and apply soft-default deltas.
     *
     * @var list<string>
     */
    #[Locked]
    public array $previousDefaultedAddons = [];

    public function mount(Definitions $defs, CatalogRepository $catalog, ConfiguratorService $configurator, ?string $id = null): void
    {
        if ($id !== null) {
            $cfg = SavedConfig::findOrFail($id);
            $sel = Selection::fromArray(
                array_merge($cfg->selection, ['version' => $cfg->mageos_version]),
                $cfg->mageos_version,
                $defs,
            );
            $this->savedId = $cfg->id;
            $this->savedAt = (string) $cfg->created_at;
        } else {
            $sel = Selection::default($catalog->latestStable(), $defs);
        }

        $this->hydrateFromSelection($sel, $defs, $configurator);
    }

    /**
     * Generic update hook — fires for any property change, including nested
     * array key updates (e.g. wire:model="profileGroups.theme"), where the
     * specific updatedProfileGroups() hook is not invoked by Livewire 4.
     *
     * Routes profile-group changes into the soft-default pass.
     */
    public function updated(string $name): void
    {
        if ($name === 'profileGroups' || str_starts_with($name, 'profileGroups.')) {
            $this->autoSnapInvalidOptions();
            $this->reapplySoftDefaults();
        }
    }

    /**
     * After a profile-group change, walk every other group and snap it back to
     * its default if the previously-picked option's `requires` is no longer met.
     * Records a single user-facing notice when at least one snap happens.
     */
    private function autoSnapInvalidOptions(): void
    {
        $defs = app(Definitions::class);
        $messages = [];
        foreach ($this->profileGroups as $groupName => $optionName) {
            if ($defs->optionMeetsRequires($groupName, $optionName, $this->profileGroups)) {
                continue;
            }
            $default = $defs->defaultProfileGroupOption($groupName);
            if ($default === null || $default === $optionName) {
                continue;
            }
            $optionLabel = $defs->profileGroupOption($groupName, $optionName)['label'] ?? $optionName;
            $groupLabel = $defs->profileGroups[$groupName]['label'] ?? $groupName;
            $messages[] = "$groupLabel reset to default — $optionLabel is no longer compatible with the current selection.";
            $this->profileGroups[$groupName] = $default;
        }
        $this->autoSnapNotice = $messages !== [] ? implode(' ', $messages) : null;
    }

    /**
     * Diff new defaultedAddons against the previous tracker and add/remove
     * from $enabledAddons accordingly. (Bidirectional sync from the inverse
     * direction — toggling an addon back-to-update a profile-group — is
     * handled in updatedEnabledAddons().)
     */
    private function reapplySoftDefaults(): void
    {
        $defaulted = app(ConfiguratorService::class)->defaultedAddons($this->selection());

        $newlyDefaulted = array_values(array_diff($defaulted, $this->previousDefaultedAddons));
        $undefaulted = array_values(array_diff($this->previousDefaultedAddons, $defaulted));

        $this->enabledAddons = array_values(array_diff(
            array_unique(array_merge($this->enabledAddons, $newlyDefaulted)),
            $undefaulted,
        ));

        $this->previousDefaultedAddons = $defaulted;
    }

    /**
     * Bidirectional sync: when the user toggles an addon, snap the profile-group
     * radio that soft-defaults that addon to the matching option (or back to the
     * group's declared default if the user just removed an auto-defaulted item).
     *
     * @param  list<string>  $value  the new $enabledAddons array
     */
    public function updatedEnabledAddons(array $value, ?string $key = null): void
    {
        // Detect single addition/removal vs. previous state.
        $previous = $this->previousDefaultedAddons;
        $defs = app(Definitions::class);

        // Per-addon: was it just checked or unchecked?
        $beforeSet = $this->reconstructPreviousAddonSet($value);
        $added = array_values(array_diff($value, $beforeSet));
        $removed = array_values(array_diff($beforeSet, $value));

        foreach ($added as $name) {
            $this->snapGroupForAddonChange($defs, $name, true);
        }
        foreach ($removed as $name) {
            $this->snapGroupForAddonChange($defs, $name, false);
        }

        // Re-run the soft-default pass since profile-groups may have changed.
        if ($added || $removed) {
            $this->reapplySoftDefaults();
        }
    }

    /**
     * Reconstruct the addon set as it was before the user's toggle. We only know
     * the *new* array post-update; the diff is "which value moved by exactly one"
     * vs. the snapshot we just emitted to the client. To avoid storing extra
     * state, peek at the previous server-rendered state via the request payload's
     * "old" snapshot — but Livewire doesn't expose that directly. Easier: trust
     * the soft-default tracker to be one source, and the diff against profile-
     * group-defaulted addons to be another. For toggles where the item is in
     * `previousDefaultedAddons` ∩ new array → no change to detect; for items
     * that are NOT in either → it's a fresh user toggle.
     *
     * Practical heuristic: compute "expected" addon set if profile-groups didn't
     * change = (this->enabledAddons before update). Livewire passed us the new
     * value but the property in $this is already updated; we don't have the old
     * one. Workaround: maintain a parallel `previousEnabledAddons` snapshot on
     * the client.
     *
     * @param  list<string>  $current
     * @return list<string>
     */
    private function reconstructPreviousAddonSet(array $current): array
    {
        // Use the previous-defaulted list plus any items not in the new array
        // that were locked-in last cycle. In practice this approximation handles
        // the only case that matters for the bidirectional flip: a single toggle
        // that crosses the soft-default boundary. For multi-step changes the
        // sync converges within a couple of round-trips.
        return $this->previousEnabledAddons ?? $current;
    }

    /** @var list<string>|null Snapshot of $enabledAddons taken at the start of each request, used by the diff logic in updatedEnabledAddons(). */
    private ?array $previousEnabledAddons = null;

    public function hydrate(): void
    {
        $this->previousEnabledAddons = $this->enabledAddons;
    }

    /**
     * For a single addon name, flip the profile-group radio that soft-defaults it
     * to follow the user's manual toggle.
     */
    private function snapGroupForAddonChange(Definitions $defs, string $name, bool $checked): void
    {
        foreach ($defs->profileGroups as $groupName => $def) {
            $current = $this->profileGroups[$groupName] ?? null;
            $currentOpt = collect($def['options'] ?? [])->firstWhere('name', $current);

            if (! $checked) {
                // User unchecked $name. If the currently-selected option soft-defaults it,
                // flip back to the group's default.
                if (! $currentOpt) {
                    continue;
                }
                $enables = $currentOpt['enables']['addons'] ?? [];
                if (! in_array($name, $enables, true)) {
                    continue;
                }
                $defaultOpt = collect($def['options'] ?? [])->firstWhere('default', true)
                    ?? ($def['options'][0] ?? null);
                if ($defaultOpt && $defaultOpt['name'] !== $current) {
                    $this->profileGroups[$groupName] = $defaultOpt['name'];
                }
            } else {
                // User checked $name. If some option in this group soft-defaults it
                // and isn't the current selection, snap to it.
                $wanted = collect($def['options'] ?? [])
                    ->first(fn ($opt) => in_array($name, $opt['enables']['addons'] ?? [], true));
                if ($wanted && $wanted['name'] !== $current) {
                    $this->profileGroups[$groupName] = $wanted['name'];
                }
            }
        }
    }

    /**
     * Top-level profile picker: rewrite all the form state from a profile YAML.
     */
    public function setProfile(string $name, Definitions $defs, ConfiguratorService $configurator): void
    {
        if (! isset($defs->profiles[$name])) {
            return;
        }
        $sel = Selection::default($this->version, $defs)->applyProfile($defs->profiles[$name]);
        $this->hydrateFromSelection($sel, $defs, $configurator);
    }

    public function save(ConfiguratorService $configurator)
    {
        $cfg = SavedConfig::create([
            'mageos_version' => $this->version,
            'selection' => $this->selection()->toArray(),
        ]);
        return $this->redirect(route('configurator.show', $cfg->id), navigate: true);
    }

    /**
     * Build a Selection from the current public state.
     *
     * Intentionally NOT a #[Computed] property: state can mutate multiple times
     * within a single request lifecycle (reapplySoftDefaults() runs after a
     * profile-group radio update and itself reads selection() to compute the new
     * defaultedAddons). Caching would hand back a stale Selection.
     */
    public function selection(): Selection
    {
        $defs = app(Definitions::class);
        $allSetNames = array_keys($defs->sets);
        $stockLayerNames = array_values(array_filter(
            array_keys($defs->layers),
            fn ($n) => $defs->isLayerStock($n),
        ));

        $allSubtoggles = $defs->allSubtoggleKeys();

        return new Selection(
            version: $this->version ?? '',
            profile: $this->profile,
            disabledSets: array_values(array_diff($allSetNames, $this->enabledSets)),
            disabledLayers: array_values(array_diff($stockLayerNames, $this->enabledStockLayers)),
            enabledLayers: [],
            enabledAddons: $this->enabledAddons,
            profileGroups: $this->profileGroups,
            disabledSubtoggles: array_values(array_diff($allSubtoggles, $this->enabledSubtoggles)),
            enabledOptionSubtoggles: $this->enabledOptionSubtoggles,
        );
    }

    #[Computed]
    public function composer(): array
    {
        return app(ConfiguratorService::class)->build($this->selection(), $this->hyvaProject);
    }

    #[Computed]
    public function composerJson(): string
    {
        return app(ComposerJsonRenderer::class)->render($this->composer);
    }

    #[Computed]
    public function requireCount(): int
    {
        return count($this->composer['require'] ?? []);
    }

    #[Computed]
    public function replaceCount(): int
    {
        return count($this->composer['replace'] ?? []);
    }

    #[Computed]
    public function forcedAddons(): array
    {
        return app(ConfiguratorService::class)->forcedAddons($this->selection());
    }

    #[Computed]
    public function forcedLayers(): array
    {
        return app(ConfiguratorService::class)->forcedLayers($this->selection());
    }

    #[Computed]
    public function defaultedAddons(): array
    {
        return app(ConfiguratorService::class)->defaultedAddons($this->selection());
    }

    #[Computed]
    public function installTree(): array
    {
        return app(InstallTreeResolver::class)->resolve($this->selection());
    }

    /**
     * True iff the generated composer.json pulls in any hyva-themes/* package
     * (theme=hyva, hyva-checkout, etc.). Used to gate the install-instructions panel.
     */
    #[Computed]
    public function usesHyva(): bool
    {
        $defs = app(Definitions::class);
        $addonsInUse = array_unique(array_merge($this->enabledAddons, $this->forcedAddons));
        foreach ($addonsInUse as $name) {
            foreach ($defs->addonPackages($name) as $pkg) {
                if (str_starts_with($pkg, 'hyva-themes/')) {
                    return true;
                }
            }
        }
        return false;
    }

    private function hydrateFromSelection(Selection $sel, Definitions $defs, ConfiguratorService $configurator): void
    {
        $allSets = array_keys($defs->sets);
        $stockLayers = array_values(array_filter(
            array_keys($defs->layers),
            fn ($n) => $defs->isLayerStock($n),
        ));

        $this->version = $sel->version;
        $this->profile = $sel->profile;
        $this->enabledSets = array_values(array_diff($allSets, $sel->disabledSets));
        $this->enabledStockLayers = array_values(array_diff($stockLayers, $sel->disabledLayers));
        $this->profileGroups = $sel->profileGroups;
        $this->enabledSubtoggles = array_values(array_diff($defs->allSubtoggleKeys(), $sel->disabledSubtoggles));
        $this->enabledOptionSubtoggles = $sel->enabledOptionSubtoggles;
        // Apply soft defaults on top of the selection's explicit enabledAddons.
        $defaulted = $configurator->defaultedAddons($sel);
        $this->enabledAddons = array_values(array_unique(array_merge($sel->enabledAddons, $defaulted)));
        $this->previousDefaultedAddons = $defaulted;
    }

    public function render()
    {
        $defs = app(Definitions::class);
        $catalog = app(CatalogRepository::class);

        // Dispatch the freshly-computed JSON so the wire:ignore'd preview pane
        // can re-paint, re-highlight, and flash the diff client-side.
        $this->dispatch('composer-updated', json: $this->composerJson);

        // Partition sets by category so the view can render Modules and
        // Languages in separate panels. The underlying disable-by-replace
        // mechanism is unchanged — they're all just sets.
        $modules = array_filter($defs->sets, fn ($s) => ($s['category'] ?? 'module') === 'module');
        $languages = array_filter($defs->sets, fn ($s) => ($s['category'] ?? 'module') === 'language');

        return view('livewire.configurator', [
            'setDefs' => $modules,
            'languageDefs' => $languages,
            'layerDefs' => $defs->layers,
            'addonDefs' => $defs->addons,
            'profileDefs' => $defs->profiles,
            'profileGroupDefs' => $defs->profileGroups,
            'versions' => $catalog->availableVersions() ?: [$this->version],
        ])->layout('components.layouts.app');
    }
}

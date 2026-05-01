<?php

namespace App\Console\Commands;

use App\Services\CatalogRepository;
use App\Services\ComposerJsonRenderer;
use App\Services\Configurator;
use App\Services\Definitions;
use App\Services\Selection;
use Illuminate\Console\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

class ConfigureCommand extends Command
{
    protected $signature = 'mageos:configure
        {--mageos-version= : Mage-OS edition version (defaults to latest stable)}
        {--profile= : Starter profile name (e.g. mageos-full, mageos-lite)}
        {--disable=* : Comma-separated set names to disable (added to replace)}
        {--disable-layer=* : Comma-separated layer names to disable (added to replace)}
        {--enable-addon=* : Comma-separated add-on names to enable (added to require)}
        {--profile-group=* : Profile-group choices, e.g. theme:hyva}
        {--output= : Write composer.json to this file (default: stdout)}
        {--interactive : Prompt for choices (otherwise pure flag-driven)}';

    protected $description = 'Generate a customised composer.json for a Mage-OS project';

    public function handle(
        CatalogRepository $catalog,
        Definitions $defs,
        Configurator $configurator,
        ComposerJsonRenderer $renderer,
    ): int {
        $version = $this->option('mageos-version') ?: $catalog->latestStable();

        $selection = Selection::default($version, $defs);

        if ($this->option('profile')) {
            $name = $this->option('profile');
            if (! isset($defs->profiles[$name])) {
                $this->error("Unknown profile: $name");
                return self::FAILURE;
            }
            $selection = Selection::default($version, $defs)->applyProfile($defs->profiles[$name]);
        }

        $disabledSets = $this->splitMulti($this->option('disable'));
        $disabledLayers = $this->splitMulti($this->option('disable-layer'));
        $enabledAddons = $this->splitMulti($this->option('enable-addon'));
        $profileGroups = $selection->profileGroups;
        foreach ($this->splitMulti($this->option('profile-group')) as $pair) {
            [$group, $option] = explode(':', $pair, 2) + [null, null];
            if ($group && $option) {
                $profileGroups[$group] = $option;
            }
        }

        $selection = new Selection(
            version: $version,
            profile: $selection->profile,
            disabledSets: array_values(array_unique(array_merge($selection->disabledSets, $disabledSets))),
            disabledLayers: array_values(array_unique(array_merge($selection->disabledLayers, $disabledLayers))),
            enabledAddons: array_values(array_unique(array_merge($selection->enabledAddons, $enabledAddons))),
            profileGroups: $profileGroups,
        );

        if ($this->option('interactive')) {
            $selection = $this->runInteractive($selection, $defs, $catalog, $configurator);
        }

        $composer = $configurator->build($selection);
        $rendered = $renderer->render($composer);

        if ($this->option('output')) {
            file_put_contents($this->option('output'), $rendered);
            $this->info('Wrote '.$this->option('output'));
        } else {
            // Bypass Symfony's formatter so indentation is preserved verbatim.
            fwrite(STDOUT, $rendered);
        }

        return self::SUCCESS;
    }

    private function splitMulti(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            foreach (explode(',', $v) as $piece) {
                $piece = trim($piece);
                if ($piece !== '') {
                    $out[] = $piece;
                }
            }
        }
        return $out;
    }

    private function runInteractive(Selection $selection, Definitions $defs, CatalogRepository $catalog, Configurator $configurator): Selection
    {
        $versions = $catalog->availableVersions() ?: [$selection->version];
        $version = select(
            label: 'Mage-OS version',
            options: array_combine($versions, $versions),
            default: in_array($selection->version, $versions, true) ? $selection->version : end($versions),
        );

        $profileOptions = [];
        foreach ($defs->profiles as $name => $profile) {
            $profileOptions[$name] = $profile['label'].' — '.($profile['description'] ?? '');
        }
        $profile = select(
            label: 'Starter profile',
            options: $profileOptions,
            default: $selection->profile ?: $defs->defaultProfile(),
        );
        $selection = Selection::default($version, $defs)->applyProfile($defs->profiles[$profile]);

        $profileGroups = $selection->profileGroups;
        foreach ($defs->profileGroups as $group => $def) {
            $options = [];
            foreach ($def['options'] as $opt) {
                $options[$opt['name']] = $opt['label'];
            }
            $current = $profileGroups[$group] ?? $defs->defaultProfileGroupOption($group) ?? array_key_first($options);
            $profileGroups[$group] = select(
                label: $def['label'],
                options: $options,
                default: $current,
            );
        }

        $setOptions = [];
        foreach ($defs->sets as $name => $set) {
            $setOptions[$name] = $set['label'];
        }
        $enabledSetNames = array_diff(array_keys($setOptions), $selection->disabledSets);
        $kept = multiselect(
            label: 'Modules to keep enabled',
            options: $setOptions,
            default: array_values($enabledSetNames),
            scroll: 12,
        );
        $disabledSets = array_values(array_diff(array_keys($setOptions), $kept));

        $layerOptions = [];
        foreach ($defs->layers as $name => $layer) {
            $layerOptions[$name] = $layer['label'];
        }
        $enabledLayerNames = array_diff(array_keys($layerOptions), $selection->disabledLayers);
        $keptLayers = multiselect(
            label: 'Layers to keep enabled',
            options: $layerOptions,
            default: array_values($enabledLayerNames),
            scroll: 8,
        );
        $disabledLayers = array_values(array_diff(array_keys($layerOptions), $keptLayers));

        // Forced add-ons (from current profile-group choices) are excluded from the
        // multiselect — they're always on.
        $tentative = new Selection(
            $version, $profile, $disabledSets, $disabledLayers,
            $selection->enabledAddons, $profileGroups,
        );
        $forced = $configurator->forcedAddons($tentative);
        $userPickable = array_diff_key($defs->addons, array_flip($forced));
        $enabledAddons = $selection->enabledAddons;
        if ($userPickable !== []) {
            $addonOptions = [];
            foreach ($userPickable as $name => $addon) {
                $addonOptions[$name] = $addon['label'];
            }
            $enabledAddons = multiselect(
                label: 'Optional add-ons',
                options: $addonOptions,
                default: array_values(array_intersect(array_keys($addonOptions), $enabledAddons)),
                scroll: 8,
            );
        }
        if ($forced !== []) {
            info('Auto-enabled add-ons (from profile groups): '.implode(', ', $forced));
        }

        info("Configured for Mage-OS $version (profile: $profile)");

        return new Selection(
            version: $version,
            profile: $profile,
            disabledSets: $disabledSets,
            disabledLayers: $disabledLayers,
            enabledAddons: array_values($enabledAddons),
            profileGroups: $profileGroups,
        );
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\SavedConfig;
use App\Services\CatalogRepository;
use App\Services\ComposerJsonRenderer;
use App\Services\Configurator;
use App\Services\Definitions;
use App\Services\Selection;
use Illuminate\Http\Request;

class ConfiguratorController extends Controller
{
    public function __construct(
        private readonly CatalogRepository $catalog,
        private readonly Definitions $defs,
        private readonly Configurator $configurator,
        private readonly ComposerJsonRenderer $renderer,
    ) {}

    public function index()
    {
        $version = $this->catalog->latestStable();
        $selection = Selection::default($version, $this->defs);

        return view('configurator.index', $this->viewData($selection));
    }

    public function show(string $id)
    {
        $config = SavedConfig::findOrFail($id);
        $selection = Selection::fromArray(
            array_merge($config->selection, ['version' => $config->mageos_version]),
            $config->mageos_version,
            $this->defs,
        );

        return view('configurator.index', array_merge(
            $this->viewData($selection),
            ['savedId' => $config->id, 'savedAt' => $config->created_at],
        ));
    }

    public function preview(Request $request)
    {
        $selection = $this->selectionFromRequest($request);
        $composer = $this->configurator->build($selection);

        return response()->json([
            'composer' => $this->renderer->render($composer),
            'requireCount' => count($composer['require'] ?? []),
            'replaceCount' => count($composer['replace'] ?? []),
        ]);
    }

    public function save(Request $request)
    {
        $selection = $this->selectionFromRequest($request);
        $config = SavedConfig::create([
            'mageos_version' => $selection->version,
            'selection' => $selection->toArray(),
        ]);

        return response()->json(['id' => $config->id, 'url' => route('configurator.show', $config->id)]);
    }

    private function selectionFromRequest(Request $request): Selection
    {
        $data = $request->input('selection', []);
        $version = $data['version'] ?? $this->catalog->latestStable();

        $base = Selection::default($version, $this->defs);
        if (! empty($data['profile']) && isset($this->defs->profiles[$data['profile']])) {
            $base = Selection::default($version, $this->defs)->applyProfile($this->defs->profiles[$data['profile']]);
        }

        return new Selection(
            version: $version,
            profile: $data['profile'] ?? $base->profile,
            enabledSets: array_values($data['enabledSets'] ?? $base->enabledSets),
            disabledSets: array_values($data['disabledSets'] ?? $base->disabledSets),
            enabledLayers: array_values($data['enabledLayers'] ?? $base->enabledLayers),
            disabledLayers: array_values($data['disabledLayers'] ?? $base->disabledLayers),
            profileGroups: $data['profileGroups'] ?? $base->profileGroups,
        );
    }

    private function viewData(Selection $selection): array
    {
        return [
            'selection' => $selection,
            'versions' => $this->catalog->availableVersions() ?: [$selection->version],
            'sets' => $this->defs->sets,
            'layers' => $this->defs->layers,
            'profileGroups' => $this->defs->profileGroups,
            'profiles' => $this->defs->profiles,
            'initialComposer' => $this->renderer->render($this->configurator->build($selection)),
        ];
    }
}

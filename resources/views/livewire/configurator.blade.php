<div>
<header>
    <h1>mageos-maker</h1>
    @if ($savedId)
        <span class="saved">Saved config: <code>{{ $savedId }}</code> ({{ $savedAt }})</span>
    @endif
</header>
<main>
    <div>
        <div class="panel">
            <h2>Mage-OS version</h2>
            <select wire:model.live="version">
                @foreach (array_reverse($versions) as $v)
                    <option value="{{ $v }}">{{ $v }}</option>
                @endforeach
            </select>
        </div>

        <div class="panel">
            <h2>Profile</h2>
            <p style="font-size:12px;color:#666;margin:0 0 8px;">Picking a profile reseeds your selections. Customize freely afterwards.</p>
            <div class="radio-group">
                @foreach ($profileDefs as $name => $profileDef)
                    <label>
                        <input type="radio" name="profile" value="{{ $name }}"
                            wire:click="setProfile('{{ $name }}')"
                            @checked($profile === $name)>
                        <span><strong>{{ $profileDef['label'] }}</strong> <span class="desc">{{ $profileDef['description'] ?? '' }}</span></span>
                    </label>
                @endforeach
            </div>
        </div>

        @foreach ($profileGroupDefs as $groupName => $group)
            <div class="panel">
                <h2>{{ $group['label'] }}</h2>
                <div class="radio-group">
                    @foreach ($group['options'] as $opt)
                        @php
                            $defsHelper = app(\App\Services\Definitions::class);
                            $available = $defsHelper->optionMeetsRequires($groupName, $opt['name'], $profileGroups);
                            $prefer = $defsHelper->optionPreferAlternative($groupName, $opt['name'], $profileGroups);
                            $isPicked = ($profileGroups[$groupName] ?? null) === $opt['name'];
                            $hint = '';
                            if (! $available) {
                                $reqs = [];
                                foreach (($opt['requires']['profileGroups'] ?? []) as $g => $needed) {
                                    $reqOpt = collect($profileGroupDefs[$g]['options'] ?? [])->firstWhere('name', $needed);
                                    $reqs[] = ($profileGroupDefs[$g]['label'] ?? $g).' = '.($reqOpt['label'] ?? $needed);
                                }
                                $hint = '(needs '.implode(', ', $reqs).')';
                            } elseif ($prefer !== null) {
                                $altOpt = collect($group['options'] ?? [])->firstWhere('name', $prefer['use']);
                                $altLabel = $altOpt['label'] ?? $prefer['use'];
                                $hint = isset($prefer['reason'])
                                    ? "(prefer {$altLabel} — {$prefer['reason']})"
                                    : "(prefer {$altLabel})";
                            }
                        @endphp
                        <label class="{{ $available ? '' : 'forced' }}">
                            <input type="radio"
                                wire:model.live="profileGroups.{{ $groupName }}"
                                value="{{ $opt['name'] }}"
                                @disabled(! $available)>
                            {{ $opt['label'] }}
                            @if ($hint !== '')
                                <span class="desc">{{ $hint }}</span>
                            @endif
                        </label>
                        @if ($isPicked && $available && ! empty($opt['variants']))
                            @php
                                $activeVariant = $defsHelper->optionActiveVariant($groupName, $opt['name'], $profileGroups, $optionVariants);
                            @endphp
                            <div class="subtoggles" style="margin-left:24px;border-left:2px solid #e5e5e5;padding-left:10px;">
                                @foreach ($opt['variants'] as $variant)
                                    @php
                                        $vAvailable = true;
                                        foreach (($variant['requires']['profileGroups'] ?? []) as $g => $needed) {
                                            if (($profileGroups[$g] ?? null) !== $needed) {
                                                $vAvailable = false;
                                                break;
                                            }
                                        }
                                        $vHint = '';
                                        if (! $vAvailable) {
                                            $reqs = [];
                                            foreach (($variant['requires']['profileGroups'] ?? []) as $g => $needed) {
                                                $reqOpt = collect($profileGroupDefs[$g]['options'] ?? [])->firstWhere('name', $needed);
                                                $reqs[] = ($profileGroupDefs[$g]['label'] ?? $g).' = '.($reqOpt['label'] ?? $needed);
                                            }
                                            $vHint = '(needs '.implode(', ', $reqs).')';
                                        }
                                    @endphp
                                    <label class="{{ $vAvailable ? '' : 'forced' }}" style="display:block;">
                                        <input type="radio"
                                            name="variant-{{ $groupName }}-{{ $opt['name'] }}"
                                            wire:click="setOptionVariant('{{ $groupName }}', '{{ $opt['name'] }}', '{{ $variant['name'] }}')"
                                            value="{{ $variant['name'] }}"
                                            @if ($activeVariant === $variant['name']) checked @endif
                                            @disabled(! $vAvailable)>
                                        {{ $variant['label'] }}
                                        @if ($vHint !== '')
                                            <span class="desc">{{ $vHint }}</span>
                                        @endif
                                    </label>
                                    @if ($activeVariant === $variant['name'] && $vAvailable && ! empty($variant['subtoggles']))
                                        <div style="margin-left:24px;border-left:2px solid #e5e5e5;padding-left:10px;">
                                            @foreach ($variant['subtoggles'] as $sub)
                                                <label style="display:block;">
                                                    <input type="checkbox"
                                                        wire:model.live="enabledOptionSubtoggles"
                                                        value="{{ $groupName }}.{{ $opt['name'] }}.{{ $variant['name'] }}.{{ $sub['name'] }}">
                                                    <span>
                                                        {{ $sub['label'] }}
                                                        @if (! empty($sub['description']))
                                                            <span class="desc">{{ $sub['description'] }}</span>
                                                        @endif
                                                    </span>
                                                </label>
                                            @endforeach
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @elseif ($isPicked && $available && ! empty($opt['subtoggles']))
                            <div class="subtoggles" style="margin-left:24px;border-left:2px solid #e5e5e5;padding-left:10px;">
                                @foreach ($opt['subtoggles'] as $sub)
                                    <label style="display:block;">
                                        <input type="checkbox"
                                            wire:model.live="enabledOptionSubtoggles"
                                            value="{{ $groupName }}.{{ $opt['name'] }}.{{ $sub['name'] }}">
                                        <span>
                                            {{ $sub['label'] }}
                                            @if (! empty($sub['description']))
                                                <span class="desc">{{ $sub['description'] }}</span>
                                            @endif
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        @endforeach

        @if ($autoSnapNotice)
            <div class="panel" style="background:#fff8e0;border-color:#e6c34c;font-size:12px;color:#7a5a00;"
                 wire:key="snap-{{ md5($autoSnapNotice) }}">
                {{ $autoSnapNotice }}
                <button type="button" wire:click="$set('autoSnapNotice', null)" style="float:right;background:none;border:none;cursor:pointer;color:#7a5a00;">×</button>
            </div>
        @endif

        <div class="panel">
            <h2>Add-ons</h2>
            <p style="font-size:12px;color:#666;margin:0 0 8px;">Extra packages outside stock Mage-OS. Greyed-out items are forced by your current profile-group choices.</p>
            <div class="checkbox-list" id="addon-list">
                @foreach ($addonDefs as $name => $addon)
                    @php $isForced = in_array($name, $this->forcedAddons, true); @endphp
                    <label class="{{ $isForced ? 'forced' : '' }}">
                        @if ($isForced)
                            {{-- Forced addons render as a checked+disabled checkbox decoupled
                                 from $enabledAddons; binding wire:model would let Livewire
                                 overwrite the `checked` state on every render. --}}
                            <input type="checkbox" disabled checked>
                        @else
                            <input type="checkbox" wire:model.live="enabledAddons" value="{{ $name }}">
                        @endif
                        <span>
                            <strong>{{ $addon['label'] }}</strong>
                            @if ($isForced) <span class="pill">required</span> @endif
                            <span class="desc">{{ $addon['description'] ?? '' }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        </div>

        <div class="panel">
            <h2>Modules</h2>
            <div class="checkbox-list">
                @foreach ($setDefs as $name => $set)
                    @php $parentEnabled = in_array($name, $enabledSets, true); @endphp
                    <label>
                        <input type="checkbox" wire:model.live="enabledSets" value="{{ $name }}">
                        <span>
                            <strong>{{ $set['label'] }}</strong>
                            <span class="desc">{{ $set['description'] ?? '' }}</span>
                        </span>
                    </label>
                    @if (! empty($set['subtoggles']))
                        <div class="subtoggles" style="margin-left:24px;border-left:2px solid #e5e5e5;padding-left:10px;">
                            @foreach ($set['subtoggles'] as $sub)
                                <label class="{{ $parentEnabled ? '' : 'forced' }}" style="display:block;">
                                    <input type="checkbox"
                                        wire:model.live="enabledSubtoggles"
                                        value="{{ $name }}.{{ $sub['name'] }}"
                                        @disabled(! $parentEnabled)>
                                    <span>
                                        {{ $sub['label'] }}
                                        @if (! empty($sub['description']))
                                            <span class="desc">{{ $sub['description'] }}</span>
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                @endforeach
            </div>
        </div>

        @if (count($languageDefs) > 0)
            <div class="panel">
                <h2>Languages</h2>
                <p style="font-size:12px;color:#666;margin:0 0 8px;">Stock Mage-OS translation packs. Disabled languages are added to <code>replace</code>.</p>
                <div class="checkbox-list">
                    @foreach ($languageDefs as $name => $lang)
                        <label>
                            <input type="checkbox" wire:model.live="enabledSets" value="{{ $name }}">
                            <span>
                                <strong>{{ $lang['label'] }}</strong>
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="panel">
            <h2>Layers</h2>
            <p style="font-size:12px;color:#666;margin:0 0 8px;">Cross-cutting concerns. Stock layers are on by default; non-stock layers are managed by your profile-group choices.</p>
            <div class="checkbox-list" id="layer-list">
                @foreach ($layerDefs as $name => $layer)
                    @php
                        $isStock = ($layer['stock'] ?? true) !== false;
                        $isForced = in_array($name, $this->forcedLayers, true);
                    @endphp
                    @if ($isStock)
                        <label>
                            <input type="checkbox" wire:model.live="enabledStockLayers" value="{{ $name }}">
                            <span>
                                <strong>{{ $layer['label'] }}</strong>
                                <span class="desc">{{ $layer['description'] ?? '' }}</span>
                            </span>
                        </label>
                    @else
                        {{-- Non-stock layer: profile-group-managed only, never user-toggled. --}}
                        <label class="forced">
                            <input type="checkbox" disabled @if ($isForced) checked @endif>
                            <span>
                                <strong>{{ $layer['label'] }}</strong>
                                @if ($isForced) <span class="pill">required</span>
                                @else <span class="pill">auto</span>
                                @endif
                                <span class="desc">{{ $layer['description'] ?? '' }}</span>
                            </span>
                        </label>
                    @endif
                @endforeach
            </div>
        </div>
    </div>

    <div class="right">
        <div class="panel" style="background:#1e1e23;border-color:#1e1e23;">
            <div class="toolbar">
                <button onclick="copyComposer()">Copy</button>
                <button class="secondary" wire:click="save">Save & share</button>
                <span class="stats">require: {{ $this->requireCount }} · replace: {{ $this->replaceCount }}</span>
            </div>
            <pre class="composer" wire:ignore><code id="composer-out" class="language-json">{{ $this->composerJson }}</code></pre>
        </div>

        @if ($this->usesHyva)
            @php
                $token = $hyvaToken !== '' ? $hyvaToken : 'YOUR_HYVA_TOKEN';
                $project = $hyvaProject !== '' ? $hyvaProject : 'yourProjectName';
            @endphp
            <div class="panel hyva-panel">
                <h2>Hyvä install steps</h2>
                <p style="font-size:13px;color:#444;margin:0 0 12px;">
                    The Hyvä Theme is free of charge but requires a packagist token.
                    Register at <a href="https://www.hyva.io/" target="_blank" rel="noopener">hyva.io</a>
                    to get your free token and project name, then run these commands in your project root <strong>before</strong>
                    <code>composer install</code>.
                    See the <a href="https://docs.hyva.io/hyva-themes/getting-started/index.html" target="_blank" rel="noopener">official docs</a>.
                </p>

                <div class="hyva-fields">
                    <label>
                        <span>Hyvä token</span>
                        <input type="text" wire:model.live.debounce.300ms="hyvaToken" placeholder="YOUR_HYVA_TOKEN" autocomplete="off">
                    </label>
                    <label>
                        <span>Project name</span>
                        <input type="text" wire:model.live.debounce.300ms="hyvaProject" placeholder="yourProjectName" autocomplete="off">
                    </label>
                </div>

                <ol class="hyva-steps">
                    <li>
                        <span class="step-label">Configure composer auth</span>
                        <pre class="cmd"><code>composer config --auth http-basic.hyva-themes.repo.packagist.com token {{ $token }}</code></pre>
                    </li>
                    <li>
                        <span class="step-label">Add the Hyvä private repository</span>
                        <pre class="cmd"><code>composer config repositories.hyva-private composer https://hyva-themes.repo.packagist.com/{{ $project }}/</code></pre>
                    </li>
                    <li>
                        <span class="step-label">Install dependencies</span>
                        <pre class="cmd"><code>composer install</code></pre>
                    </li>
                    <li>
                        <span class="step-label">Activate the theme in Magento</span>
                        <pre class="cmd"><code>bin/magento setup:upgrade
bin/magento config:set design/theme/theme_id $(bin/magento dev:theme:list 2>/dev/null | grep Hyva/default | awk '{print $1}')
bin/magento cache:flush</code></pre>
                        <small>Or pick <code>Hyva/default</code> from <em>Content → Design → Configuration</em> in the admin.</small>
                    </li>
                </ol>
            </div>
        @endif

        @php $tree = $this->installTree; @endphp
        <div class="panel install-tree">
            <h2>
                Install tree
                <span class="stats">{{ $tree['count'] }} packages</span>
            </h2>
            @if ($tree['missing'])
                <p class="warn" style="font-size:12px;color:#a15c00;">
                    @if ($tree['fallbackVersion'])
                        No baked graph for {{ $tree['version'] }} yet — showing {{ $tree['fallbackVersion'] }}.
                    @else
                        No baked graph available. Run <code>php artisan mageos:catalog:update</code>.
                    @endif
                </p>
            @endif
            @if ($tree['count'] === 0 && ! $tree['missing'])
                <p style="font-size:12px;color:#666;">No packages — nothing to show.</p>
            @else
                <input type="text" id="install-tree-filter" placeholder="Filter…" autocomplete="off"
                    style="width:100%;padding:6px 8px;margin-bottom:8px;border:1px solid #ccc;border-radius:3px;font-size:12px;"
                    oninput="filterInstallTree(this.value)">
                <div class="install-tree-types" style="font-size:11px;color:#666;margin-bottom:6px;">
                    @foreach ($tree['byType'] as $type => $n)
                        <span style="margin-right:8px;">{{ $type }}: {{ $n }}</span>
                    @endforeach
                    <span style="margin-left:auto;">
                        <a href="#" onclick="installTreeToggleAll(true);return false;" style="font-size:11px;">expand all</a> ·
                        <a href="#" onclick="installTreeToggleAll(false);return false;" style="font-size:11px;">collapse</a>
                    </span>
                </div>
                <div id="install-tree-root" class="install-tree-root" style="max-height:420px;overflow:auto;font-family:monospace;font-size:12px;line-height:1.5;">
                    @include('livewire.partials.install-tree-node', ['nodes' => $tree['tree'], 'depth' => 0])
                </div>
                <style>
                    /* Indent applies to the children of a <details> (both nested details and leaves)
                       so a package with children doesn't end up further right than its sibling leaves
                       just because of the disclosure triangle. */
                    .install-tree-root details > details,
                    .install-tree-root details > .leaf { margin-left: 14px; }
                    .install-tree-root summary { cursor: pointer; list-style: none; padding: 1px 0; }
                    .install-tree-root summary::-webkit-details-marker { display: none; }
                    .install-tree-root summary::before {
                        content: '▸'; display: inline-block; width: 10px; color: #888;
                        transition: transform .1s;
                    }
                    .install-tree-root details[open] > summary::before { content: '▾'; }
                    .install-tree-root .leaf::before { content: '·'; color: #ccc; display: inline-block; width: 10px; }
                    .install-tree-root .pkg-name { color: #1a5fb4; }
                    .install-tree-root .pkg-name.shared { color: #555; font-style: italic; }
                    .install-tree-root .pkg-version { color: #888; margin-left: 6px; }
                    .install-tree-root .pkg-type { color: #aaa; font-size: 10px; margin-left: 6px; }
                    .install-tree-root .hidden { display: none; }
                </style>
                <script>
                    function filterInstallTree(q) {
                        q = q.trim().toLowerCase();
                        const root = document.getElementById('install-tree-root');
                        if (!root) return;
                        // Walk depth-first; node visible iff itself matches OR any descendant matches.
                        function walk(el) {
                            const name = (el.dataset.name || '').toLowerCase();
                            let selfMatch = q === '' || name.includes(q);
                            let descMatch = false;
                            el.querySelectorAll(':scope > details, :scope > .leaf').forEach(child => {
                                if (walk(child)) descMatch = true;
                            });
                            const visible = selfMatch || descMatch;
                            el.classList.toggle('hidden', !visible);
                            if (q !== '' && descMatch && el.tagName === 'DETAILS') el.open = true;
                            return visible;
                        }
                        root.querySelectorAll(':scope > details, :scope > .leaf').forEach(walk);
                    }
                    function installTreeToggleAll(open) {
                        document.querySelectorAll('#install-tree-root details').forEach(d => d.open = open);
                    }
                </script>
            @endif
        </div>

    </div>
</main>
</div>

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
                                    <label class="{{ $vAvailable ? '' : 'forced' }}" style="display:block;"
                                           wire:key="variant-{{ $groupName }}-{{ $opt['name'] }}-{{ $variant['name'] }}-active{{ $activeVariant }}">
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
                    {{-- wire:key includes $isForced so morphdom remounts the checkbox when its
                         forced state flips; otherwise the DOM `checked` *property* lags behind
                         the new attribute — same browser quirk that bit the variant radios. --}}
                    <label class="{{ $isForced ? 'forced' : '' }}"
                           wire:key="addon-{{ $name }}-{{ $isForced ? 'forced' : 'free' }}">
                        @if ($isForced)
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
            <input type="text" id="modules-filter" placeholder="Filter modules…" autocomplete="off"
                style="width:100%;padding:6px 8px;margin-bottom:8px;border:1px solid #ccc;border-radius:3px;font-size:12px;"
                oninput="filterModules(this.value)">
            <div class="checkbox-list" id="modules-list">
                @foreach ($setDefs as $name => $set)
                    @php
                        $parentEnabled = in_array($name, $enabledSets, true);
                        $removable = $setRemovable[$name] ?? true;
                        $haystack = strtolower(trim(
                            $name.' '.($set['label'] ?? '').' '.($set['description'] ?? '').' '
                            .collect($set['subtoggles'] ?? [])
                                ->map(fn ($s) => ($s['label'] ?? '').' '.($s['description'] ?? ''))
                                ->implode(' ')
                        ));
                    @endphp
                    <div class="module-row" data-search="{{ $haystack }}">
                        <label class="{{ $removable ? '' : 'forced' }}">
                            <input type="checkbox" wire:model.live="enabledSets" value="{{ $name }}" @disabled(! $removable)>
                            <span>
                                <strong>{{ $set['label'] }}</strong>
                                @unless ($removable)
                                    <span class="pill" title="This module has hard cross-module dependencies in stock Mage-OS and can't be removed without breaking di:compile or setup:install.">required</span>
                                @endunless
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
                    </div>
                @endforeach
            </div>
            <p id="modules-empty" style="display:none;font-size:12px;color:#888;margin:4px 0 0;">No modules match.</p>
            <script>
                function filterModules(q) {
                    q = q.trim().toLowerCase();
                    const list = document.getElementById('modules-list');
                    if (!list) return;
                    let visible = 0;
                    list.querySelectorAll(':scope > .module-row').forEach(row => {
                        const match = q === '' || (row.dataset.search || '').includes(q);
                        row.style.display = match ? '' : 'none';
                        if (match) visible++;
                    });
                    const empty = document.getElementById('modules-empty');
                    if (empty) empty.style.display = visible === 0 ? '' : 'none';
                }
                // Re-apply filter after Livewire morphs the panel (e.g. toggling a module
                // checkbox): inline display:none gets clobbered, so without this the list
                // jumps back to "all visible" the moment you click anything.
                document.addEventListener('livewire:init', () => {
                    Livewire.hook('morph.updated', () => {
                        const input = document.getElementById('modules-filter');
                        if (input && input.value) filterModules(input.value);
                    });
                });
            </script>
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
                        @php $removable = $layerRemovable[$name] ?? true; @endphp
                        <label class="{{ $removable ? '' : 'forced' }}">
                            <input type="checkbox" wire:model.live="enabledStockLayers" value="{{ $name }}" @disabled(! $removable)>
                            <span>
                                <strong>{{ $layer['label'] }}</strong>
                                @unless ($removable)
                                    <span class="pill" title="This layer is wired into stock Mage-OS bootstrap and can't be removed without breaking the install.">required</span>
                                @endunless
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
                $copyIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" aria-hidden="true" focusable="false"><path d="M480 400L288 400C279.2 400 272 392.8 272 384L272 128C272 119.2 279.2 112 288 112L421.5 112C425.7 112 429.8 113.7 432.8 116.7L491.3 175.2C494.3 178.2 496 182.3 496 186.5L496 384C496 392.8 488.8 400 480 400zM288 448L480 448C515.3 448 544 419.3 544 384L544 186.5C544 169.5 537.3 153.2 525.3 141.2L466.7 82.7C454.7 70.7 438.5 64 421.5 64L288 64C252.7 64 224 92.7 224 128L224 384C224 419.3 252.7 448 288 448zM160 192C124.7 192 96 220.7 96 256L96 512C96 547.3 124.7 576 160 576L352 576C387.3 576 416 547.3 416 512L416 496L368 496L368 512C368 520.8 360.8 528 352 528L160 528C151.2 528 144 520.8 144 512L144 256C144 247.2 151.2 240 160 240L176 240L176 192L160 192z"/></svg>';
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
                        <div class="cmd-row"><pre class="cmd"><code>composer config --auth http-basic.hyva-themes.repo.packagist.com token {{ $token }}</code></pre><button type="button" class="cmd-copy" onclick="copyCmd(this)" aria-label="Copy">{!! $copyIcon !!}</button></div>
                    </li>
                    @if ($hyvaProject === '')
                        <li>
                            <span class="step-label">Add the Hyvä private repository</span>
                            <div class="cmd-row"><pre class="cmd"><code>composer config repositories.hyva-private composer https://hyva-themes.repo.packagist.com/{{ $project }}/</code></pre><button type="button" class="cmd-copy" onclick="copyCmd(this)" aria-label="Copy">{!! $copyIcon !!}</button></div>
                            <small>Skip this once you've filled in the project name above — the repo will be baked into the generated <code>composer.json</code>.</small>
                        </li>
                    @endif
                    <li>
                        <span class="step-label">Install dependencies</span>
                        <div class="cmd-row"><pre class="cmd"><code>composer install</code></pre><button type="button" class="cmd-copy" onclick="copyCmd(this)" aria-label="Copy">{!! $copyIcon !!}</button></div>
                    </li>
                    <li>
                        <span class="step-label">Activate the theme in Magento</span>
                        <div class="cmd-row"><pre class="cmd"><code>bin/magento setup:upgrade
bin/magento config:set design/theme/theme_id frontend/Hyva/default
bin/magento cache:flush</code></pre><button type="button" class="cmd-copy" onclick="copyCmd(this)" aria-label="Copy">{!! $copyIcon !!}</button></div>
                        <small>Or pick <code>Hyva/default</code> from <em>Content → Design → Configuration</em> in the admin.</small>
                    </li>
                    <li>
                        <span class="step-label">Disable the legacy Magento captcha</span>
                        <div class="cmd-row"><pre class="cmd"><code>bin/magento config:set customer/captcha/enable 0</code></pre><button type="button" class="cmd-copy" onclick="copyCmd(this)" aria-label="Copy">{!! $copyIcon !!}</button></div>
                        <small>Hyvä doesn't support the legacy captcha; storefront forms break with it on. Swap in Google ReCaptcha (V2/V3) from the admin if you still want bot protection.</small>
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

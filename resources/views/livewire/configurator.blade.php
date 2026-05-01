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
                        <label>
                            <input type="radio" wire:model.live="profileGroups.{{ $groupName }}" value="{{ $opt['name'] }}">
                            {{ $opt['label'] }}
                        </label>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div class="panel">
            <h2>Add-ons</h2>
            <p style="font-size:12px;color:#666;margin:0 0 8px;">Extra packages outside stock Mage-OS. Greyed-out items are forced by your current profile-group choices.</p>
            <div class="checkbox-list" id="addon-list">
                @foreach ($addonDefs as $name => $addon)
                    @php $isForced = in_array($name, $this->forcedAddons, true); @endphp
                    <label class="{{ $isForced ? 'forced' : '' }}">
                        <input type="checkbox" wire:model.live="enabledAddons" value="{{ $name }}"
                            @disabled($isForced)
                            @if ($isForced) checked @endif>
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
                    <label>
                        <input type="checkbox" wire:model.live="enabledSets" value="{{ $name }}">
                        <span>
                            <strong>{{ $set['label'] }}</strong>
                            <span class="desc">{{ $set['description'] ?? '' }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        </div>

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
    </div>
</main>
</div>

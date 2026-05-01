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
    </div>
</main>
</div>

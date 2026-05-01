<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>mageos-maker</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11.10.0/build/styles/atom-one-dark.min.css">
    <script src="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11.10.0/build/highlight.min.js"></script>
    <script src="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11.10.0/build/languages/json.min.js"></script>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 0; background: #f7f7f8; color: #1a1a1a; }
        header { background: #1a1a1a; color: #fff; padding: 16px 24px; display: flex; align-items: center; gap: 12px; }
        header h1 { font-size: 18px; margin: 0; font-weight: 600; }
        header .saved { font-size: 12px; opacity: 0.7; }
        main { display: grid; grid-template-columns: minmax(340px, 1fr) minmax(500px, 1.5fr); gap: 24px; padding: 24px; max-width: 1700px; margin: 0 auto; }
        .panel { background: #fff; border: 1px solid #e2e2e6; border-radius: 8px; padding: 20px; }
        .panel h2 { font-size: 14px; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 12px; color: #555; }
        .panel + .panel { margin-top: 16px; }
        .field { display: block; margin-bottom: 12px; }
        .field label { display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px; }
        select, input[type=text] { width: 100%; padding: 8px 10px; border: 1px solid #d0d0d6; border-radius: 4px; font-size: 14px; background: #fff; }
        .checkbox-list { display: flex; flex-direction: column; gap: 6px; }
        .checkbox-list label { display: flex; align-items: flex-start; gap: 8px; font-size: 14px; padding: 6px 8px; border-radius: 4px; cursor: pointer; }
        .checkbox-list label:hover { background: #f0f0f4; }
        .checkbox-list label .desc { display: block; font-size: 12px; color: #666; margin-top: 2px; }
        .radio-group { display: flex; flex-direction: column; gap: 6px; }
        .radio-group label { display: flex; align-items: center; gap: 8px; font-size: 14px; cursor: pointer; }
        .right { display: flex; flex-direction: column; gap: 16px; }
        pre.composer { padding: 0; border-radius: 6px; overflow: auto; font-size: 12.5px; line-height: 1.55; max-height: 78vh; margin: 0; position: relative; }
        pre.composer code.hljs { display: block; padding: 18px 20px; background: #1e1e23; border-radius: 6px; position: relative; z-index: 1; }
        .diff-overlay { position: absolute; left: 0; right: 0; top: 18px; pointer-events: none; z-index: 2; }
        .diff-overlay .strip { position: absolute; left: 0; right: 0; background: rgba(250, 204, 21, 0.32); border-left: 2px solid rgba(250, 204, 21, 0.85); animation: diffFade 1.8s ease-out forwards; mix-blend-mode: screen; }
        @keyframes diffFade { 0% { opacity: 1; } 70% { opacity: 0.6; } 100% { opacity: 0; } }
        .toolbar { display: flex; gap: 8px; margin-bottom: 8px; align-items: center; }
        .toolbar .stats { font-size: 12px; color: #ccc; margin-left: auto; }
        button { background: #2563eb; color: #fff; border: 0; padding: 8px 14px; border-radius: 4px; font-size: 13px; font-weight: 500; cursor: pointer; }
        button:hover { background: #1d4ed8; }
        button.secondary { background: #444; }
        button.secondary:hover { background: #222; }
        .pill { display: inline-block; background: #eef; color: #226; font-size: 11px; padding: 2px 8px; border-radius: 99px; margin-left: 6px; }
        .checkbox-list label.forced { opacity: 0.7; cursor: not-allowed; }
        .checkbox-list label.forced:hover { background: transparent; }
    </style>
</head>
<body>
<header>
    <h1>mageos-maker</h1>
    @isset($savedId)
        <span class="saved">Saved config: <code>{{ $savedId }}</code> ({{ $savedAt }})</span>
    @endisset
</header>
<main>
    <div>
        <div class="panel">
            <h2>Mage-OS version</h2>
            <select id="version">
                @foreach (array_reverse($versions) as $v)
                    <option value="{{ $v }}" @selected($v === $selection->version)>{{ $v }}</option>
                @endforeach
            </select>
        </div>

        <div class="panel">
            <h2>Profile</h2>
            <p style="font-size:12px;color:#666;margin:0 0 8px;">Picking a profile reseeds your selections. Customize freely afterwards.</p>
            <div class="radio-group" id="profile-group">
                @foreach ($profiles as $name => $profile)
                    <label>
                        <input type="radio" name="profile" value="{{ $name }}" @checked($selection->profile === $name)>
                        <span><strong>{{ $profile['label'] }}</strong> <span class="desc">{{ $profile['description'] ?? '' }}</span></span>
                    </label>
                @endforeach
            </div>
        </div>

        @foreach ($profileGroups as $groupName => $group)
            <div class="panel">
                <h2>{{ $group['label'] }}</h2>
                <div class="radio-group">
                    @foreach ($group['options'] as $opt)
                        <label>
                            <input type="radio" name="pg-{{ $groupName }}" value="{{ $opt['name'] }}" @checked(($selection->profileGroups[$groupName] ?? null) === $opt['name'])>
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
                @foreach ($addons as $name => $addon)
                    @php
                        $isForced = in_array($name, $forcedAddons, true);
                        $isDefaulted = in_array($name, $defaultedAddons, true);
                        $checked = $isForced || in_array($name, $selection->enabledAddons, true) || $isDefaulted;
                    @endphp
                    <label class="{{ $isForced ? 'forced' : '' }}">
                        <input type="checkbox" name="addon" value="{{ $name }}"
                            @checked($checked)
                            @disabled($isForced)
                            data-forced="{{ $isForced ? '1' : '0' }}">
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
                @foreach ($sets as $name => $set)
                    <label>
                        <input type="checkbox" name="set" value="{{ $name }}" @checked(! in_array($name, $selection->disabledSets, true))>
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
            <p style="font-size:12px;color:#666;margin:0 0 8px;">Cross-cutting concerns. Stock layers are on by default; non-stock layers are off unless your profile-group choices enable them.</p>
            <div class="checkbox-list" id="layer-list">
                @foreach ($layers as $name => $layer)
                    @php
                        $isStock = ($layer['stock'] ?? true) !== false;
                        $isForced = in_array($name, $forcedLayers, true);
                        $isDefaulted = in_array($name, $defaultedLayers, true);
                        if ($isStock) {
                            $checked = ! in_array($name, $selection->disabledLayers, true);
                        } else {
                            $checked = $isForced || in_array($name, $selection->enabledLayers, true) || $isDefaulted;
                        }
                    @endphp
                    <label class="{{ $isForced ? 'forced' : '' }}">
                        <input type="checkbox" name="layer" value="{{ $name }}"
                            @checked($checked)
                            @disabled($isForced)
                            data-stock="{{ $isStock ? '1' : '0' }}"
                            data-forced="{{ $isForced ? '1' : '0' }}">
                        <span>
                            <strong>{{ $layer['label'] }}</strong>
                            @if ($isForced) <span class="pill">required</span> @endif
                            <span class="desc">{{ $layer['description'] ?? '' }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        </div>
    </div>

    <div class="right">
        <div class="panel" style="background:#1e1e23;border-color:#1e1e23;">
            <div class="toolbar">
                <button onclick="copyComposer()">Copy</button>
                <button class="secondary" onclick="saveConfig()">Save & share</button>
                <span class="stats" id="stats"></span>
            </div>
            <pre class="composer"><code id="composer-out" class="language-json">{{ $initialComposer }}</code></pre>
        </div>
    </div>
</main>

<script>
    const csrf = document.querySelector('meta[name=csrf-token]').content;
    const PROFILES = @json($profiles);
    const PROFILE_GROUPS = @json($profileGroups);
    let lastJson = document.getElementById('composer-out').textContent;
    // Track soft-default lists from the previous server response so we can
    // diff and apply changes on the next response (auto-check newly defaulted
    // items, auto-uncheck ones that are no longer defaulted).
    let prevDefaultedAddons = @json($defaultedAddons);
    let prevDefaultedLayers = @json($defaultedLayers);

    // Returns the set of new-line indices whose content changed vs. the old text.
    // Standard LCS over lines; fast enough for typical composer.json sizes.
    function diffLineIndices(oldStr, newStr) {
        const oldLines = oldStr.split('\n');
        const newLines = newStr.split('\n');
        const m = oldLines.length, n = newLines.length;
        if (m === 0 || n === 0) return new Set(newLines.map((_, i) => i));
        // Build LCS length table.
        const lcs = Array.from({length: m + 1}, () => new Uint16Array(n + 1));
        for (let i = 1; i <= m; i++) {
            for (let j = 1; j <= n; j++) {
                lcs[i][j] = oldLines[i-1] === newLines[j-1]
                    ? lcs[i-1][j-1] + 1
                    : Math.max(lcs[i-1][j], lcs[i][j-1]);
            }
        }
        // Backtrack: any new-line index NOT on the LCS path is "changed".
        const changed = new Set();
        let i = m, j = n;
        while (i > 0 && j > 0) {
            if (oldLines[i-1] === newLines[j-1]) { i--; j--; }
            else if (lcs[i-1][j] >= lcs[i][j-1]) { i--; }
            else { changed.add(j - 1); j--; }
        }
        while (j > 0) { changed.add(j - 1); j--; }
        return changed;
    }

    // Group consecutive line indices into [start, end] ranges.
    function groupRanges(indices) {
        const sorted = [...indices].sort((a, b) => a - b);
        const ranges = [];
        for (const i of sorted) {
            const last = ranges[ranges.length - 1];
            if (last && i === last[1] + 1) last[1] = i;
            else ranges.push([i, i]);
        }
        return ranges;
    }

    function flashChangedLines(preEl, codeEl, changedRanges) {
        // Drop any previous overlay so re-renders don't accumulate.
        preEl.querySelectorAll('.diff-overlay').forEach(o => o.remove());
        if (changedRanges.length === 0) return;
        const lineHeight = parseFloat(getComputedStyle(codeEl).lineHeight);
        const overlay = document.createElement('div');
        overlay.className = 'diff-overlay';
        for (const [start, end] of changedRanges) {
            const strip = document.createElement('div');
            strip.className = 'strip';
            strip.style.top = (start * lineHeight) + 'px';
            strip.style.height = ((end - start + 1) * lineHeight) + 'px';
            overlay.appendChild(strip);
        }
        preEl.appendChild(overlay);
        setTimeout(() => overlay.remove(), 2000);

        // Scroll the first change into view if it's outside the visible area.
        const firstStart = changedRanges[0][0];
        const padTop = parseFloat(getComputedStyle(codeEl).paddingTop) || 0;
        const targetTop = firstStart * lineHeight + padTop;
        const visibleTop = preEl.scrollTop;
        const visibleBottom = visibleTop + preEl.clientHeight;
        if (targetTop < visibleTop || targetTop > visibleBottom - lineHeight * 2) {
            preEl.scrollTo({top: Math.max(0, targetTop - lineHeight * 2), behavior: 'smooth'});
        }
    }

    function setComposer(json, requireCount, replaceCount, forcedAddons, forcedLayers, defaultedAddons, defaultedLayers) {
        const el = document.getElementById('composer-out');
        const pre = el.parentElement;
        const changed = diffLineIndices(lastJson, json);
        lastJson = json;
        el.textContent = json;
        delete el.dataset.highlighted;
        hljs.highlightElement(el);
        flashChangedLines(pre, el, groupRanges(changed));
        document.getElementById('stats').textContent = `require: ${requireCount} · replace: ${replaceCount}`;
        if (Array.isArray(defaultedAddons)) {
            applySoftDefaults('#addon-list', 'addon', defaultedAddons, prevDefaultedAddons);
            prevDefaultedAddons = defaultedAddons;
        }
        if (Array.isArray(defaultedLayers)) {
            applySoftDefaults('#layer-list', 'layer', defaultedLayers, prevDefaultedLayers);
            prevDefaultedLayers = defaultedLayers;
        }
        if (Array.isArray(forcedAddons)) applyForcedFlags('#addon-list', 'addon', forcedAddons);
        if (Array.isArray(forcedLayers)) applyForcedFlags('#layer-list', 'layer', forcedLayers);
    }

    function gatherSelection() {
        const disabledSets = [];
        document.querySelectorAll('input[name=set]').forEach(el => { if (!el.checked) disabledSets.push(el.value); });
        const disabledLayers = [];
        const enabledLayers = [];
        document.querySelectorAll('input[name=layer]').forEach(el => {
            // Stock layers: track unchecked. Non-stock layers: track manually-checked
            // (forced ones are added back by the server).
            if (el.dataset.stock === '1') {
                if (!el.checked) disabledLayers.push(el.value);
            } else {
                if (el.checked && el.dataset.forced !== '1') enabledLayers.push(el.value);
            }
        });
        const enabledAddons = [];
        document.querySelectorAll('input[name=addon]').forEach(el => {
            if (el.checked && el.dataset.forced !== '1') enabledAddons.push(el.value);
        });
        const profileGroups = {};
        document.querySelectorAll('input[type=radio][name^="pg-"]:checked').forEach(el => {
            profileGroups[el.name.slice(3)] = el.value;
        });
        return {
            version: document.getElementById('version').value,
            profile: document.querySelector('input[name=profile]:checked')?.value || null,
            disabledSets,
            disabledLayers,
            enabledLayers,
            enabledAddons,
            profileGroups,
        };
    }

    // For soft defaults: items newly in `defaulted` get auto-checked; items
    // dropping out get auto-unchecked. Forced inputs are skipped (their state
    // is owned by applyForcedFlags).
    function applySoftDefaults(listSelector, inputName, defaulted, prevList) {
        const cur = new Set(defaulted);
        const prev = new Set(prevList);
        document.querySelectorAll(`${listSelector} input[name=${inputName}]`).forEach(input => {
            if (input.dataset.forced === '1') return;
            const wasDefault = prev.has(input.value);
            const isDefault = cur.has(input.value);
            if (isDefault && !wasDefault) input.checked = true;
            else if (!isDefault && wasDefault) input.checked = false;
        });
    }

    function applyForcedFlags(listSelector, inputName, forced) {
        const set = new Set(forced);
        document.querySelectorAll(`${listSelector} label`).forEach(label => {
            const input = label.querySelector(`input[name=${inputName}]`);
            if (!input) return;
            const isForced = set.has(input.value);
            input.dataset.forced = isForced ? '1' : '0';
            input.disabled = isForced;
            if (isForced) input.checked = true;
            label.classList.toggle('forced', isForced);
            // Required pill — only one per label, only when forced.
            const existing = label.querySelector('.pill');
            const isRequiredPill = existing && existing.textContent === 'required';
            if (isForced && !isRequiredPill) {
                if (existing && !isRequiredPill) existing.remove();
                const pill = document.createElement('span');
                pill.className = 'pill';
                pill.textContent = 'required';
                label.querySelector('strong').after(' ', pill);
            } else if (!isForced && isRequiredPill) {
                existing.remove();
            }
        });
    }

    let pending = null;
    function refresh() {
        if (pending) clearTimeout(pending);
        pending = setTimeout(async () => {
            const res = await fetch('/preview', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
                body: JSON.stringify({selection: gatherSelection()}),
            });
            const data = await res.json();
            setComposer(data.composer, data.requireCount, data.replaceCount, data.forcedAddons, data.forcedLayers, data.defaultedAddons, data.defaultedLayers);
        }, 80);
    }

    // Compute soft-defaults locally from profile-group YAML so we can pre-check
    // the boxes BEFORE issuing the preview request. Without this, the request
    // would gather a still-unchecked form and the server would emit a JSON
    // missing the about-to-be-checked packages.
    function deriveDefaultsFromGroups() {
        const groups = {};
        document.querySelectorAll('input[type=radio][name^="pg-"]:checked').forEach(el => {
            groups[el.name.slice(3)] = el.value;
        });
        const addons = [], layers = [];
        for (const [group, optionName] of Object.entries(groups)) {
            const def = PROFILE_GROUPS[group];
            if (!def) continue;
            const opt = (def.options || []).find(o => o.name === optionName);
            if (!opt) continue;
            const en = opt.enables || {};
            addons.push(...(en.addons || []));
            layers.push(...(en.layers || []));
        }
        return {addons, layers};
    }

    // Bidirectional coupling between soft-defaulted items and profile-group radios:
    //
    //  - If the user UNCHECKS a soft-defaulted item, snap the responsible group
    //    back to its default option (e.g. uncheck hyva addon → theme back to luma).
    //  - If the user CHECKS an item that some profile-group option soft-defaults,
    //    snap that group to that option (e.g. check hyva addon → theme = hyva).
    function syncGroupsForToggle(kind, value, nowChecked) {
        for (const [groupName, def] of Object.entries(PROFILE_GROUPS)) {
            const current = document.querySelector(`input[name="pg-${groupName}"]:checked`);
            if (!current) continue;
            const currentOpt = (def.options || []).find(o => o.name === current.value);

            if (!nowChecked) {
                // Unchecked path: only act if the currently-selected option enabled this item.
                if (!currentOpt) continue;
                const enables = (currentOpt.enables || {})[kind] || [];
                if (!enables.includes(value)) continue;
                const fallback = (def.options || []).find(o => o.default) || (def.options || [])[0];
                if (!fallback || fallback.name === current.value) continue;
                const fbRadio = document.querySelector(`input[name="pg-${groupName}"][value="${fallback.name}"]`);
                if (fbRadio) fbRadio.checked = true;
            } else {
                // Checked path: find any option that soft-defaults this item; if it
                // isn't the current selection, switch to it.
                const wanted = (def.options || []).find(o => ((o.enables || {})[kind] || []).includes(value));
                if (!wanted || wanted.name === current.value) continue;
                const wRadio = document.querySelector(`input[name="pg-${groupName}"][value="${wanted.name}"]`);
                if (wRadio) wRadio.checked = true;
            }
        }
    }

    function syncDefaultsThenRefresh() {
        const d = deriveDefaultsFromGroups();
        applySoftDefaults('#addon-list', 'addon', d.addons, prevDefaultedAddons);
        applySoftDefaults('#layer-list', 'layer', d.layers, prevDefaultedLayers);
        prevDefaultedAddons = d.addons;
        prevDefaultedLayers = d.layers;
        refresh();
    }

    document.querySelectorAll('input, select').forEach(el => el.addEventListener('change', e => {
        if (e.target.name === 'profile') {
            applyProfile(e.target.value);
            return;
        }
        if (e.target.name && e.target.name.startsWith('pg-')) {
            syncDefaultsThenRefresh();
            return;
        }
        // Toggling an addon/layer may need to flip a profile-group radio
        // to keep the two views in sync.
        if ((e.target.name === 'addon' || e.target.name === 'layer') && e.target.dataset.forced !== '1') {
            const kind = e.target.name === 'addon' ? 'addons' : 'layers';
            syncGroupsForToggle(kind, e.target.value, e.target.checked);
            // A radio may have just been flipped — re-apply soft defaults.
            syncDefaultsThenRefresh();
            return;
        }
        refresh();
    }));

    function applyProfile(name) {
        const profile = PROFILES[name];
        if (!profile) return;
        const sel = profile.selection || {};
        const disabledSets = new Set(sel.disabledSets || []);
        const disabledLayers = new Set(sel.disabledLayers || []);
        const enabledLayers = new Set(sel.enabledLayers || []);
        const enabledAddons = new Set(sel.enabledAddons || []);
        const groups = sel.profileGroups || {};

        document.querySelectorAll('input[name=set]').forEach(el => {
            el.checked = !disabledSets.has(el.value);
        });
        document.querySelectorAll('input[name=layer]').forEach(el => {
            if (el.dataset.forced === '1') return;
            if (el.dataset.stock === '1') el.checked = !disabledLayers.has(el.value);
            else el.checked = enabledLayers.has(el.value);
        });
        document.querySelectorAll('input[name=addon]').forEach(el => {
            if (el.dataset.forced === '1') return;
            el.checked = enabledAddons.has(el.value);
        });
        Object.entries(groups).forEach(([group, opt]) => {
            const radio = document.querySelector(`input[name="pg-${group}"][value="${opt}"]`);
            if (radio) radio.checked = true;
        });

        // We just rewrote the form; let the profile-group syncer re-apply
        // soft defaults on top of the new radio state, then refresh.
        prevDefaultedAddons = [];
        prevDefaultedLayers = [];
        syncDefaultsThenRefresh();
    }

    function copyComposer() {
        navigator.clipboard.writeText(document.getElementById('composer-out').textContent);
    }

    async function saveConfig() {
        const res = await fetch('/save', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
            body: JSON.stringify({selection: gatherSelection()}),
        });
        const data = await res.json();
        location.href = data.url;
    }

    hljs.highlightElement(document.getElementById('composer-out'));
    refresh();
</script>
</body>
</html>

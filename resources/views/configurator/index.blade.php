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
            <h2>Modules (sets)</h2>
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
            <div class="checkbox-list">
                @foreach ($layers as $name => $layer)
                    <label>
                        <input type="checkbox" name="layer" value="{{ $name }}" @checked(! in_array($name, $selection->disabledLayers, true))>
                        <span>
                            <strong>{{ $layer['label'] }}</strong>
                            <span class="desc">{{ $layer['description'] ?? '' }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        </div>

        <div class="panel">
            <h2>Add-ons</h2>
            <p style="font-size:12px;color:#666;margin:0 0 8px;">Extra packages outside stock Mage-OS. Greyed-out add-ons are forced by your current profile-group choices.</p>
            <div class="checkbox-list" id="addon-list">
                @foreach ($addons as $name => $addon)
                    @php $isForced = in_array($name, $forcedAddons, true); @endphp
                    <label class="{{ $isForced ? 'forced' : '' }}">
                        <input type="checkbox" name="addon" value="{{ $name }}"
                            @checked($isForced || in_array($name, $selection->enabledAddons, true))
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
    let lastJson = document.getElementById('composer-out').textContent;

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

    function setComposer(json, requireCount, replaceCount, forcedAddons) {
        const el = document.getElementById('composer-out');
        const pre = el.parentElement;
        const changed = diffLineIndices(lastJson, json);
        lastJson = json;
        el.textContent = json;
        delete el.dataset.highlighted;
        hljs.highlightElement(el);
        flashChangedLines(pre, el, groupRanges(changed));
        document.getElementById('stats').textContent = `require: ${requireCount} · replace: ${replaceCount}`;
        if (Array.isArray(forcedAddons)) applyForcedAddons(forcedAddons);
    }

    function gatherSelection() {
        const disabledSets = [];
        document.querySelectorAll('input[name=set]').forEach(el => { if (!el.checked) disabledSets.push(el.value); });
        const disabledLayers = [];
        document.querySelectorAll('input[name=layer]').forEach(el => { if (!el.checked) disabledLayers.push(el.value); });
        const enabledAddons = [];
        document.querySelectorAll('input[name=addon]').forEach(el => {
            // Forced addons are always included by the server; don't double-send them.
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
            enabledAddons,
            profileGroups,
        };
    }

    function applyForcedAddons(forced) {
        const set = new Set(forced);
        document.querySelectorAll('#addon-list label').forEach(label => {
            const input = label.querySelector('input[name=addon]');
            const isForced = set.has(input.value);
            input.dataset.forced = isForced ? '1' : '0';
            input.disabled = isForced;
            if (isForced) input.checked = true;
            label.classList.toggle('forced', isForced);
            // Pill management: add/remove a `required` pill alongside the label.
            const existing = label.querySelector('.pill');
            if (isForced && !existing) {
                const pill = document.createElement('span');
                pill.className = 'pill';
                pill.textContent = 'required';
                label.querySelector('strong').after(' ', pill);
            } else if (!isForced && existing) {
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
            setComposer(data.composer, data.requireCount, data.replaceCount, data.forcedAddons);
        }, 80);
    }

    document.querySelectorAll('input, select').forEach(el => el.addEventListener('change', e => {
        if (e.target.name === 'profile') {
            // Reload from server with the chosen profile pre-applied.
            applyProfile(e.target.value);
            return;
        }
        refresh();
    }));

    async function applyProfile(name) {
        // Trigger preview using profile, then mirror its disable lists in the form.
        const sel = gatherSelection();
        sel.profile = name;
        sel.enabledSets = []; sel.disabledSets = [];
        sel.enabledLayers = []; sel.disabledLayers = [];
        const res = await fetch('/preview', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
            body: JSON.stringify({selection: {version: sel.version, profile: name}}),
        });
        const data = await res.json();
        setComposer(data.composer, data.requireCount, data.replaceCount);
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

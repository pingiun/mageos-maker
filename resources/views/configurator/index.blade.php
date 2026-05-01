<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>mageos-maker</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 0; background: #f7f7f8; color: #1a1a1a; }
        header { background: #1a1a1a; color: #fff; padding: 16px 24px; display: flex; align-items: center; gap: 12px; }
        header h1 { font-size: 18px; margin: 0; font-weight: 600; }
        header .saved { font-size: 12px; opacity: 0.7; }
        main { display: grid; grid-template-columns: minmax(360px, 1fr) minmax(420px, 1.2fr); gap: 24px; padding: 24px; max-width: 1600px; margin: 0 auto; }
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
        pre.composer { background: #1e1e23; color: #e6e6e6; padding: 16px; border-radius: 8px; overflow: auto; font-size: 12px; line-height: 1.5; max-height: 70vh; margin: 0; }
        .toolbar { display: flex; gap: 8px; margin-bottom: 8px; align-items: center; }
        .toolbar .stats { font-size: 12px; color: #ccc; margin-left: auto; }
        button { background: #2563eb; color: #fff; border: 0; padding: 8px 14px; border-radius: 4px; font-size: 13px; font-weight: 500; cursor: pointer; }
        button:hover { background: #1d4ed8; }
        button.secondary { background: #444; }
        button.secondary:hover { background: #222; }
        .pill { display: inline-block; background: #eef; color: #226; font-size: 11px; padding: 2px 8px; border-radius: 99px; margin-left: 6px; }
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
    </div>

    <div class="right">
        <div class="panel" style="background:#1e1e23;border-color:#1e1e23;">
            <div class="toolbar">
                <button onclick="copyComposer()">Copy</button>
                <button class="secondary" onclick="saveConfig()">Save & share</button>
                <span class="stats" id="stats"></span>
            </div>
            <pre class="composer" id="composer-out">{{ $initialComposer }}</pre>
        </div>
    </div>
</main>

<script>
    const csrf = document.querySelector('meta[name=csrf-token]').content;

    function gatherSelection() {
        const disabledSets = [];
        document.querySelectorAll('input[name=set]').forEach(el => { if (!el.checked) disabledSets.push(el.value); });
        const disabledLayers = [];
        document.querySelectorAll('input[name=layer]').forEach(el => { if (!el.checked) disabledLayers.push(el.value); });
        const profileGroups = {};
        document.querySelectorAll('input[type=radio][name^="pg-"]:checked').forEach(el => {
            profileGroups[el.name.slice(3)] = el.value;
        });
        return {
            version: document.getElementById('version').value,
            profile: document.querySelector('input[name=profile]:checked')?.value || null,
            enabledSets: [],
            disabledSets,
            enabledLayers: [],
            disabledLayers,
            profileGroups,
        };
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
            document.getElementById('composer-out').textContent = data.composer;
            document.getElementById('stats').textContent = `require: ${data.requireCount} · replace: ${data.replaceCount}`;
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
        document.getElementById('composer-out').textContent = data.composer;
        document.getElementById('stats').textContent = `require: ${data.requireCount} · replace: ${data.replaceCount}`;
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

    refresh();
</script>
</body>
</html>

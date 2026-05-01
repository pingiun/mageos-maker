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
    @livewireStyles
</head>
<body>

{{ $slot }}

@livewireScripts
<script>
    // Pure presentation: highlight, diff-flash, scroll-to-change, copy.
    // All form-state lives in the Livewire component on the server.

    let lastJson = document.getElementById('composer-out')?.textContent || '';

    function diffLineIndices(oldStr, newStr) {
        const oldLines = oldStr.split('\n');
        const newLines = newStr.split('\n');
        const m = oldLines.length, n = newLines.length;
        if (m === 0 || n === 0) return new Set(newLines.map((_, i) => i));
        const lcs = Array.from({length: m + 1}, () => new Uint16Array(n + 1));
        for (let i = 1; i <= m; i++) {
            for (let j = 1; j <= n; j++) {
                lcs[i][j] = oldLines[i-1] === newLines[j-1]
                    ? lcs[i-1][j-1] + 1
                    : Math.max(lcs[i-1][j], lcs[i][j-1]);
            }
        }
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

        const firstStart = changedRanges[0][0];
        const padTop = parseFloat(getComputedStyle(codeEl).paddingTop) || 0;
        const targetTop = firstStart * lineHeight + padTop;
        const visibleTop = preEl.scrollTop;
        const visibleBottom = visibleTop + preEl.clientHeight;
        if (targetTop < visibleTop || targetTop > visibleBottom - lineHeight * 2) {
            preEl.scrollTo({top: Math.max(0, targetTop - lineHeight * 2), behavior: 'smooth'});
        }
    }

    function paintComposer(json) {
        const el = document.getElementById('composer-out');
        if (!el) return;
        const pre = el.parentElement;
        const changed = diffLineIndices(lastJson, json);
        lastJson = json;
        el.textContent = json;
        delete el.dataset.highlighted;
        hljs.highlightElement(el);
        flashChangedLines(pre, el, groupRanges(changed));
    }

    function copyComposer() {
        const el = document.getElementById('composer-out');
        if (el) navigator.clipboard.writeText(el.textContent);
    }

    // Initial highlight; subsequent updates come via the Livewire event.
    document.addEventListener('DOMContentLoaded', () => {
        const el = document.getElementById('composer-out');
        if (el) hljs.highlightElement(el);
    });

    document.addEventListener('livewire:initialized', () => {
        Livewire.on('composer-updated', ({json}) => paintComposer(json));
    });
</script>
</body>
</html>

#!/usr/bin/env php
<?php
// Render results/matrix.json into a human-readable Markdown report.
// Usage: render-matrix.php <matrix.json>  (writes to stdout)

if ($argc < 2) {
    fwrite(STDERR, "usage: render-matrix.php <matrix.json>\n");
    exit(2);
}

$data = json_decode(file_get_contents($argv[1]), true);
if (!is_array($data)) {
    fwrite(STDERR, "could not parse matrix.json\n");
    exit(2);
}

$profile = $data['profile'] ?? 'mageos-full';
$version = $data['version'] ?? '';
$rows = array_values(array_filter($data['results'] ?? [], fn($r) => ($r['set'] ?? '') !== '_baseline'));

$baseline = null;
foreach ($data['results'] ?? [] as $r) {
    if (($r['set'] ?? '') === '_baseline') { $baseline = $r; break; }
}

$by_status = [];
foreach ($rows as $r) {
    $by_status[$r['status']][] = $r;
}

echo "# Modulargento removal matrix — profile: $profile";
if ($version !== '') echo " — version: $version";
echo " — run " . date('Y-m-d') . "\n\n";

if ($baseline) {
    $bs = $baseline['status'];
    echo "Baseline (`$profile`, nothing disabled): " . strtoupper($bs);
    echo $bs === 'pass' ? " — di:compile clean.\n\n" : " — see results/raw/_baseline.log\n\n";
}

$counts = [];
foreach (['pass','fail','noop','composer-failed','timeout','configure-failed','harness-error','unknown'] as $s) {
    $counts[$s] = count($by_status[$s] ?? []);
}
echo "Totals: ";
echo implode(' · ', array_map(fn($k,$v) => "$k=$v", array_keys($counts), array_values($counts))) . "\n\n";

if (!empty($by_status['pass'])) {
    echo "## Removable cleanly (" . count($by_status['pass']) . " sets)\n\n";
    echo "| Set | Duration (s) |\n|---|---|\n";
    foreach ($by_status['pass'] as $r) {
        echo "| `{$r['set']}` | {$r['duration_s']} |\n";
    }
    echo "\n";
}

if (!empty($by_status['noop'])) {
    echo "## No-op disables (" . count($by_status['noop']) . " sets)\n\n";
    echo "Composer.json identical to baseline — set was already absent or fully replaced.\n\n";
    foreach ($by_status['noop'] as $r) echo "- `{$r['set']}`\n";
    echo "\n";
}

if (!empty($by_status['fail'])) {
    // Group by fingerprint.
    $groups = [];
    foreach ($by_status['fail'] as $r) {
        $fp = $r['fingerprint'] ?: 'unclassified';
        $groups[$fp][] = $r;
    }
    uasort($groups, fn($a, $b) => count($b) - count($a));

    echo "## Blocked at di:compile — grouped by error fingerprint (" . count($by_status['fail']) . " sets, " . count($groups) . " groups)\n\n";
    foreach ($groups as $fp => $members) {
        $n = count($members);
        echo "### " . ($n > 1 ? "[$n sets] " : "") . "`" . trim($fp) . "`\n\n";
        foreach ($members as $r) {
            echo "- `{$r['set']}`  ([log](raw/{$r['set']}.log))\n";
        }
        echo "\n";
    }
}

foreach (['composer-failed','timeout','configure-failed','harness-error'] as $bucket) {
    if (empty($by_status[$bucket])) continue;
    echo "## $bucket (" . count($by_status[$bucket]) . " sets)\n\n";
    foreach ($by_status[$bucket] as $r) {
        $fp = trim($r['fingerprint'] ?? '');
        echo "- `{$r['set']}` — `" . ($fp !== '' ? $fp : '(no fingerprint)') . "`  ([log](raw/{$r['set']}.log))\n";
    }
    echo "\n";
}

<?php
/**
 * BOB Version Manager
 *
 * Reads version from git tags automatically.
 * On each deploy (git pull), delete cache to refresh:
 *   rm storage/cache/version.txt
 *
 * Or it auto-refreshes every 10 minutes.
 *
 * Tag a release:  git tag v1.2.5 && git push --tags
 * The app picks it up automatically.
 */

function getBobVersion(): array
{
    $cacheFile = __DIR__ . '/../storage/cache/version.txt';
    $cacheDir  = dirname($cacheFile);

    // Check cache (valid for 10 minutes)
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 600) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached && !empty($cached['version'])) {
            return $cached;
        }
    }

    // Read from git
    $version  = 'dev';
    $hash     = '';
    $commits  = '';
    $branch   = '';

    $repoRoot = realpath(__DIR__ . '/..');

    // git describe --tags --always
    $desc = trim(shell_exec("cd " . escapeshellarg($repoRoot) . " && git describe --tags --always 2>/dev/null") ?? '');
    $branchRaw = trim(shell_exec("cd " . escapeshellarg($repoRoot) . " && git rev-parse --abbrev-ref HEAD 2>/dev/null") ?? '');

    if ($desc) {
        // Format: v1.2.4 or v1.2.4-15-gabcdef0
        if (preg_match('/^(v?\d+\.\d+\.\d+)(?:-(\d+)-g([a-f0-9]+))?$/', $desc, $m)) {
            $version = $m[1];
            $commits = $m[2] ?? '';
            $hash    = $m[3] ?? '';
        } else {
            // Just a hash (no tags)
            $version = 'dev';
            $hash    = $desc;
        }
    }

    $branch = $branchRaw ?: '';

    $result = [
        'version' => $version,
        'commits' => $commits,  // commits since last tag
        'hash'    => $hash,     // short commit hash
        'branch'  => $branch,
        'full'    => $desc,
    ];

    // Cache it
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    @file_put_contents($cacheFile, json_encode($result));

    return $result;
}

/**
 * Get formatted version string for display
 */
function getBobVersionString(): string
{
    $v = getBobVersion();
    return $v['version'];
}

/**
 * Get detailed version (e.g., "v1.2.4+15")
 */
function getBobVersionDetailed(): string
{
    $v = getBobVersion();
    $str = $v['version'];
    if (!empty($v['commits'])) {
        $str .= '+' . $v['commits'];
    }
    return $str;
}

<?php

declare(strict_types=1);

/*
 * KaririCode Spec V4.3.3 §24 "secret scanning is clean": fails on
 * high-confidence credential patterns in the files git tracks.
 *
 * Usage: php tools/secret-scan.php. Exit code 1 on any finding.
 */

const PATTERNS = [
    'private key block' => '/-----BEGIN (?:RSA |EC |OPENSSH |DSA |PGP )?PRIVATE KEY-----/',
    'AWS access key id' => '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
    'GitHub token' => '/\bgh[pousr]_[A-Za-z0-9]{36,}\b/',
    'Slack token' => '/\bxox[baprs]-[A-Za-z0-9-]{10,}\b/',
    'Stripe live key' => '/\b[sr]k_live_[A-Za-z0-9]{20,}\b/',
    'Google API key' => '/\bAIza[0-9A-Za-z_\-]{35}\b/',
    'Packagist token' => '/\bpkg_[A-Za-z0-9]{40,}\b/',
];

$root = dirname(__DIR__);
exec('git -C ' . escapeshellarg($root) . ' ls-files -z', $output, $status);

if ($status !== 0) {
    fwrite(STDERR, "secret-scan: git ls-files failed\n");

    exit(1);
}

$findings = [];

foreach (array_filter(explode("\0", implode("\n", $output))) as $path) {
    $file = $root . '/' . $path;

    if (!is_file($file) || filesize($file) > 1_000_000) {
        continue;
    }

    $text = (string) file_get_contents($file);

    foreach (PATTERNS as $label => $pattern) {
        if (preg_match($pattern, $text) === 1) {
            $findings[] = "{$path}: {$label}";
        }
    }
}

foreach ($findings as $finding) {
    fwrite(STDERR, "secret: {$finding}\n");
}

echo $findings === [] ? "secret-scan: clean\n" : sprintf("secret-scan: %d finding(s)\n", count($findings));

exit($findings === [] ? 0 : 1);

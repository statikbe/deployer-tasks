<?php
namespace Deployer;

set('statik_reload_phpfpm_command', 'reloadPHP.sh');
set('statik_reload_phpfpm_debounce_seconds', 60);
set('statik_reload_phpfpm_symlink_wait_seconds', 60);
set('statik_reload_phpfpm_freshness_seconds', 30);
set('statik_reload_phpfpm_max_attempts', 2);

desc('Reload PHP-FPM safely with mutex, debounce, and opcache validation');
task('statik:reload-phpfpm', function () {
    // Resolve absolute paths — deploy_path / release_path may contain `~`.
    $deployPath = trim((string) run('cd {{deploy_path}} && pwd'));
    $releasePath = trim((string) run('cd {{release_path}} && pwd'));

    // Wait for the `current` symlink to point at our release.
    $waitSeconds = (int) get('statik_reload_phpfpm_symlink_wait_seconds');
    $actual = '';
    for ($i = 0; $i < $waitSeconds; $i++) {
        $actual = trim((string) run("readlink -f '{$deployPath}/current' 2>/dev/null || true"));
        if ($actual === $releasePath) {
            break;
        }
        sleep(1);
    }
    if ($actual !== $releasePath) {
        throw new \RuntimeException("Symlink mismatch: '{$actual}' (expected '{$releasePath}')");
    }

    // Drop a one-shot opcache probe in webroot. The 192-bit random filename
    // serves as access control; removed in the finally block below.
    $probe = '_deploy_probe_' . bin2hex(random_bytes(24)) . '.php';
    upload(__DIR__ . '/stubs/opcache-probe.php', "{{release_path}}/public/{$probe}");
    $basicUser = (string) get('basic_auth_user', '');
    $basicPass = (string) get('basic_auth_password', '');
    $authPrefix = '';
    if ($basicUser !== '' && $basicPass !== '') {
        $authPrefix = rawurlencode($basicUser) . ':' . rawurlencode($basicPass) . '@';
    }
    if ($authPrefix !== '') {
        writeln('Basic auth has been set and will be used!');
    } else {
        writeln('No basic auth enabled, using curl without auth');
    }
    $url = "https://{$authPrefix}{{http_host}}/{$probe}";

    try {
        $debounceSeconds = (int) get('statik_reload_phpfpm_debounce_seconds');
        $freshnessSeconds = (int) get('statik_reload_phpfpm_freshness_seconds');
        $maxAttempts = (int) get('statik_reload_phpfpm_max_attempts');

        $before = json_decode((string) run("curl -sL --max-redirs 3 --max-time 10 '{$url}' || true"), true) ?: [];
        $beforeStart = (int) ($before['start_time'] ?? 0);
        $beforeNow = (int) ($before['now'] ?? 0);

        // Debounce: a single FPM master typically serves every subsite on a
        // shared host, so a recent reload by a sibling deploy already covers ours.
        if ($beforeStart > 0 && $beforeNow - $beforeStart < $debounceSeconds) {
            $age = $beforeNow - $beforeStart;
            writeln("<comment>statik:reload-phpfpm: skipping — opcache reset {$age}s ago</comment>");
            return;
        }

        $afterStart = 0;
        $afterAge = 0;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // pgrep mutex: kernel-truth, no stale-lock cleanup.
            run("timeout 60 sh -c 'while pgrep -x {{statik_reload_phpfpm_command}} >/dev/null 2>&1; do sleep 1; done'");

            $reloadOutput = (string) run('{{statik_reload_phpfpm_command}}');
            if (! str_contains($reloadOutput, '"OK"')) {
                throw new \RuntimeException('PHP-FPM reload command did not return "OK": ' . trim($reloadOutput));
            }
            sleep(2);

            $after = json_decode((string) run("curl -sL --max-redirs 3 --max-time 10 '{$url}' || true"), true) ?: [];
            $afterStart = (int) ($after['start_time'] ?? 0);
            $afterAge = (int) ($after['now'] ?? 0) - $afterStart;

            // Validate: opcache start_time advanced AND is fresh on the server's
            // own clock (rules out an unrelated old FPM restart).
            if ($afterStart > $beforeStart && $afterAge >= 0 && $afterAge < $freshnessSeconds) {
                writeln("<info>statik:reload-phpfpm: validated (start_time {$beforeStart} -> {$afterStart}, age {$afterAge}s)</info>");
                return;
            }

            if ($attempt < $maxAttempts) {
                writeln('<comment>statik:reload-phpfpm: validation failed, retrying...</comment>');
                sleep(2);
            }
        }

        throw new \RuntimeException("PHP-FPM reload validation failed: start_time={$afterStart}, age={$afterAge}s");
    } finally {
        run("rm -f {{release_path}}/public/{$probe} || true");
    }
});

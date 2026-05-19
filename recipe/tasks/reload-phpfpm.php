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

    // Resolve {{http_host}} now so the URL is usable in both run() (which
    // would template it anyway) and in error messages (which would otherwise
    // surface the literal `{{http_host}}` placeholder).
    $url = 'https://' . parse('{{http_host}}') . '/' . $probe;

    // Keep basic-auth credentials out of $url: embedding them in the URL
    // leaks them into the deploy log via every exception message that
    // includes $url. Pass them through `-u` instead.
    $basicUser = (string) get('basic_auth_user', '');
    $basicPass = (string) get('basic_auth_password', '');
    $curlAuthOpt = '';
    if ($basicUser !== '' && $basicPass !== '') {
        $curlAuthOpt = ' -u ' . escapeshellarg($basicUser . ':' . $basicPass);
        writeln('Basic auth has been set and will be used!');
    } else {
        writeln('No basic auth enabled, using curl without auth');
    }

    // Fetch the probe URL. Body, HTTP code, and decoded JSON are returned
    // separately so an unexpected response (redirect HTML, Laravel 404 page,
    // basic-auth challenge, …) can be surfaced with its actual status and a
    // body snippet instead of silently being treated as `start_time=0`.
    $fetchProbe = function (string $url) use ($curlAuthOpt): array {
        $sep = '---HTTP_CODE---';
        $raw = (string) run(
            "curl -sL --max-redirs 3 --max-time 10{$curlAuthOpt} -w '\\n{$sep}%{http_code}' '{$url}' || true"
        );
        $parts = explode("\n{$sep}", $raw, 2);
        $body = $parts[0] ?? '';
        $code = isset($parts[1]) ? (int) trim($parts[1]) : 0;
        $json = json_decode($body, true);

        return [
            'body' => $body,
            'http_code' => $code,
            'json' => is_array($json) ? $json : null,
        ];
    };

    // Format a probe response for an error message: HTTP code + body snippet.
    $describeProbe = function (array $resp) use ($url): string {
        $snippet = trim(preg_replace('/\s+/', ' ', $resp['body']) ?? '');
        if ($snippet === '') {
            $snippet = '(empty body)';
        } elseif (strlen($snippet) > 200) {
            $snippet = substr($snippet, 0, 200) . '…';
        }

        return "probe URL {$url} returned HTTP {$resp['http_code']}, body: {$snippet}";
    };

    try {
        $debounceSeconds = (int) get('statik_reload_phpfpm_debounce_seconds');
        $freshnessSeconds = (int) get('statik_reload_phpfpm_freshness_seconds');
        $maxAttempts = (int) get('statik_reload_phpfpm_max_attempts');

        // Pre-flight: a non-JSON or non-200 response usually means the probe
        // URL is misconfigured (wrong http_host, redirect target, htaccess
        // routing the .php through Laravel, basic-auth challenge, etc.), but
        // it can also be a transient race where rsync just landed the file
        // and the web server's view of the filesystem hasn't caught up.
        // Retry a few times before failing so a slow-storage shared host
        // doesn't trip the fast-fail path.
        $preflightAttempts = 3;
        $before = ['body' => '', 'http_code' => 0, 'json' => null];
        for ($i = 1; $i <= $preflightAttempts; $i++) {
            $before = $fetchProbe($url);
            if ($before['http_code'] === 200 && $before['json'] !== null) {
                break;
            }
            if ($i < $preflightAttempts) {
                writeln("<comment>statik:reload-phpfpm: pre-flight probe attempt {$i}/{$preflightAttempts} failed (HTTP {$before['http_code']}), retrying in 2s...</comment>");
                sleep(2);
            }
        }
        if ($before['http_code'] !== 200 || $before['json'] === null) {
            throw new \RuntimeException("Pre-flight probe failed after {$preflightAttempts} attempts — " . $describeProbe($before));
        }

        $beforeStart = (int) ($before['json']['start_time'] ?? 0);
        $beforeNow = (int) ($before['json']['now'] ?? 0);

        // Debounce: a single FPM master typically serves every subsite on a
        // shared host, so a recent reload by a sibling deploy already covers ours.
        if ($beforeStart > 0 && $beforeNow - $beforeStart < $debounceSeconds) {
            $age = $beforeNow - $beforeStart;
            writeln("<comment>statik:reload-phpfpm: skipping — opcache reset {$age}s ago</comment>");
            return;
        }

        $after = $before;
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

            $after = $fetchProbe($url);
            if ($after['json'] === null) {
                // A non-JSON response would silently collapse to `start_time=0`
                // below; throw with the response detail so the operator can
                // diagnose what's actually wrong.
                throw new \RuntimeException('Post-reload probe failed — ' . $describeProbe($after));
            }
            $afterStart = (int) ($after['json']['start_time'] ?? 0);
            $afterAge = (int) ($after['json']['now'] ?? 0) - $afterStart;

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

        throw new \RuntimeException(sprintf(
            'PHP-FPM reload validation failed: start_time=%d, age=%ds (after %d attempt%s); last probe response: HTTP %d',
            $afterStart,
            $afterAge,
            $maxAttempts,
            $maxAttempts === 1 ? '' : 's',
            $after['http_code']
        ));
    } finally {
        // Record whether the probe file is still on disk at cleanup time.
        // If the deploy failed with a web-server 404, this distinguishes
        // "rsync never landed the file" from "file is on disk but the web
        // server's docroot or cache didn't see it".
        $probeExists = trim((string) run("test -f {{release_path}}/public/{$probe} && echo present || echo missing"));
        writeln("<comment>statik:reload-phpfpm: probe file {$probeExists} on disk before cleanup</comment>");
        run("rm -f {{release_path}}/public/{$probe} || true");
    }
});

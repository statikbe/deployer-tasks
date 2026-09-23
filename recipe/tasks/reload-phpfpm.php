<?php

namespace Deployer;

// Host the opcache probe is fetched from. Defaults to {{http_host}}; override
// per host when the deploy target is not reachable on its http_host (split
// DNS, a vhost alias, or a host that answers on a different public domain).
set('statik_reload_phpfpm_host', function () {
    return get('http_host');
});

set('statik_reload_phpfpm_command', 'reloadPHP.sh');
set('statik_reload_phpfpm_debounce_seconds', 60);
set('statik_reload_phpfpm_symlink_wait_seconds', 60);
set('statik_reload_phpfpm_freshness_seconds', 30);
set('statik_reload_phpfpm_max_attempts', 12);
set('statik_reload_phpfpm_preflight_attempts', 5);
set('statik_reload_phpfpm_preflight_sleep_seconds', 15);

desc('Reload PHP-FPM safely with mutex, debounce, and opcache validation');
task('statik:reload-phpfpm', function () {
    // Evaluated per-host: a mixed deploy may target Combell and non-Combell
    // hosts, so this guard lives in the task body rather than around the hook.
    if (! get('combell_hosting', false)) {
        writeln('<comment>statik:reload-phpfpm: skipping — not Combell hosting (combell_hosting=false)</comment>');

        return;
    }

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
    $probe = '_deploy_probe_'.bin2hex(random_bytes(24)).'.php';
    upload(__DIR__.'/stubs/opcache-probe.php', "{{release_path}}/{{public_path}}/{$probe}");

    // Mirror the probe into the previous release. If PHP-FPM's realpath cache
    // or the web server's symlink cache still resolves `current/public` to
    // releases/N-1 (the window only the FPM reload itself can flush), the
    // mirror keeps the probe reachable. The probe reports its own __FILE__,
    // so a response from the mirror is recognised and never counts as a
    // validated reload.
    $mirrorProbe = has('previous_release');
    if ($mirrorProbe) {
        upload(__DIR__.'/stubs/opcache-probe.php', "{{previous_release}}/{{public_path}}/{$probe}");
    }

    // Resolve the probe host now so the URL is usable in both run() (which
    // would template it anyway) and in error messages (which would otherwise
    // surface a literal `{{...}}` placeholder).
    $url = 'https://'.get('statik_reload_phpfpm_host').'/'.$probe;

    // Keep basic-auth credentials out of $url: embedding them in the URL
    // leaks them into the deploy log via every exception message that
    // includes $url. Pass them through `-u` instead.
    $basicUser = (string) get('basic_auth_user', '');
    $basicPass = (string) get('basic_auth_password', '');
    $curlAuthOpt = '';
    if ($basicUser !== '' && $basicPass !== '') {
        $curlAuthOpt = ' -u '.escapeshellarg($basicUser.':'.$basicPass);
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
            $snippet = substr($snippet, 0, 200).'…';
        }

        return "probe URL {$url} returned HTTP {$resp['http_code']}, body: {$snippet}";
    };

    // Which copy of the probe answered. A stale FPM worker (or a web server
    // symlink cache) still resolving `current` to the previous release runs
    // the mirror copy; only a response from the new release proves the
    // request path has moved on. Matched on the release-relative suffix, not
    // the absolute path: the web node may mount the site under a different
    // prefix than the SSH node sees.
    $newReleaseProbeSuffix = '/'.basename($releasePath).'/'.get('public_path').'/'.$probe;
    $servedFile = fn (array $resp): string => (string) ($resp['json']['file'] ?? '');
    $isServedByNewRelease = fn (array $resp): bool => str_ends_with($servedFile($resp), $newReleaseProbeSuffix);

    try {
        $debounceSeconds = (int) get('statik_reload_phpfpm_debounce_seconds');
        $freshnessSeconds = (int) get('statik_reload_phpfpm_freshness_seconds');
        $maxAttempts = (int) get('statik_reload_phpfpm_max_attempts');

        // Pre-flight: a non-JSON or non-200 response usually means the probe
        // URL is misconfigured (wrong statik_reload_phpfpm_host, redirect
        // target, htaccess routing the .php through Laravel, basic-auth
        // challenge, etc.), but it can also be a transient race where rsync
        // just landed the file and the web server's view of the filesystem
        // hasn't caught up.
        // Retry a few times before failing so a slow-storage shared host
        // doesn't trip the fast-fail path.
        $preflightAttempts = max(1, (int) get('statik_reload_phpfpm_preflight_attempts'));
        $preflightSleep = max(0, (int) get('statik_reload_phpfpm_preflight_sleep_seconds'));
        $before = ['body' => '', 'http_code' => 0, 'json' => null];
        for ($i = 1; $i <= $preflightAttempts; $i++) {
            $before = $fetchProbe($url);
            if ($before['http_code'] === 200 && $before['json'] !== null) {
                break;
            }
            if ($i < $preflightAttempts) {
                writeln("<comment>statik:reload-phpfpm: pre-flight probe attempt {$i}/{$preflightAttempts} failed (HTTP {$before['http_code']}), retrying in {$preflightSleep}s...</comment>");
                sleep($preflightSleep);
            }
        }
        if ($before['http_code'] !== 200 || $before['json'] === null) {
            throw new \RuntimeException("Pre-flight probe failed after {$preflightAttempts} attempts — ".$describeProbe($before));
        }

        $beforeStart = (int) ($before['json']['start_time'] ?? 0);
        $beforeNow = (int) ($before['json']['now'] ?? 0);
        $beforeServedByNewRelease = $isServedByNewRelease($before);
        writeln("statik:reload-phpfpm: pre-flight probe served by {$servedFile($before)}");

        // Debounce: a single FPM master typically serves every subsite on a
        // shared host, so a recent reload by a sibling deploy already covers ours.
        // Only when the probe already runs from the new release: a fresh
        // start_time from a worker still on the previous release covers nothing.
        if ($beforeServedByNewRelease && $beforeStart > 0 && $beforeNow - $beforeStart < $debounceSeconds) {
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
                throw new \RuntimeException('PHP-FPM reload command did not return "OK": '.trim($reloadOutput));
            }
            sleep(2);

            $after = $fetchProbe($url);
            if ($after['json'] === null) {
                // A non-JSON response would silently collapse to `start_time=0`
                // below; throw with the response detail so the operator can
                // diagnose what's actually wrong.
                throw new \RuntimeException('Post-reload probe failed — '.$describeProbe($after));
            }
            $afterStart = (int) ($after['json']['start_time'] ?? 0);
            $afterAge = (int) ($after['json']['now'] ?? 0) - $afterStart;

            // Validate: opcache start_time advanced AND is fresh on the server's
            // own clock (rules out an unrelated old FPM restart) AND the probe
            // ran from the new release (rules out a worker still on the old one).
            if (
                $afterStart > $beforeStart
                && $afterAge >= 0
                && $afterAge < $freshnessSeconds
                && $isServedByNewRelease($after)
            ) {
                writeln("<info>statik:reload-phpfpm: validated (start_time {$beforeStart} -> {$afterStart}, age {$afterAge}s, served by {$servedFile($after)})</info>");

                return;
            }

            if ($attempt < $maxAttempts) {
                writeln("<comment>statik:reload-phpfpm: validation failed (start_time {$afterStart}, age {$afterAge}s, served by {$servedFile($after)}), retrying...</comment>");
                sleep(2);
            }
        }

        throw new \RuntimeException(sprintf(
            'PHP-FPM reload validation failed: start_time=%d, age=%ds, served by %s, expected %s (after %d attempt%s); last probe response: HTTP %d',
            $afterStart,
            $afterAge,
            $servedFile($after) ?: '(unknown)',
            $releasePath,
            $maxAttempts,
            $maxAttempts === 1 ? '' : 's',
            $after['http_code']
        ));
    } finally {
        // Record whether the probe file is still on disk at cleanup time.
        // If the deploy failed with a web-server 404, this distinguishes
        // "rsync never landed the file" from "file is on disk but the web
        // server's docroot or cache didn't see it". Wrapped in its own
        // try/catch so a transient SSH failure during the diagnostic can't
        // shadow the real RuntimeException from the try block above.
        try {
            $probeExists = trim((string) run("test -f {{release_path}}/{{public_path}}/{$probe} && echo present || echo missing"));
            writeln("<comment>statik:reload-phpfpm: probe file {$probeExists} on disk before cleanup</comment>");
            if ($mirrorProbe) {
                $mirrorExists = trim((string) run("test -f {{previous_release}}/{{public_path}}/{$probe} && echo present || echo missing"));
                writeln("<comment>statik:reload-phpfpm: mirror probe file {$mirrorExists} on disk before cleanup</comment>");
            }
        } catch (\Throwable $e) {
            writeln('<comment>statik:reload-phpfpm: could not check probe file on disk: '.$e->getMessage().'</comment>');
        }
        run("rm -f {{release_path}}/{{public_path}}/{$probe} || true");
        if ($mirrorProbe) {
            run("rm -f {{previous_release}}/{{public_path}}/{$probe} || true");
        }
    }
});

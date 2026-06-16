<?php

namespace Deployer;

// `env_file` / `htaccess_file` are stage-specific source paths, relative to
// release_path, typically set per host (e.g. in hosts.yml). They are read with
// an inline `null` default rather than declared with set(), so the tasks no-op
// when unconfigured without ever shadowing a per-host or global value.

desc('Copy the stage-specific .env file into the shared path');
task('statik:copy_env', function () {
    $envFile = get('env_file', null);
    if ($envFile) {
        run("cp {{release_path}}/$envFile {{deploy_path}}/shared/.env");
    }
});

desc('Copy the stage-specific htaccess file into the web root');
task('statik:copy_htaccess', function () {
    $htaccessFile = get('htaccess_file', null);
    if ($htaccessFile) {
        run("cp {{release_path}}/$htaccessFile {{release_path}}/{{public_path}}/.htaccess");
    }
});
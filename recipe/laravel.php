<?php

namespace Deployer;

// Deployer resolves recipe/* via its own include path (vendor/deployer/deployer/recipe/...).
require 'recipe/laravel.php';
require __DIR__.'/tasks/reload-phpfpm.php';
require __DIR__.'/tasks/voight.php';
require __DIR__.'/tasks/copy-stage-files.php';

// Statik.be opinionated defaults
set('keep_releases', 5);
set('writable_mode', 'chown'); // Combell hosts do not have ACL installed (.env already shared by recipe/laravel.php)

// Seed the stage-specific .env and webroot .htaccess before deploy:shared
// symlinks the shared .env. Both no-op unless env_file / htaccess_file is set
// (typically per host). The two write to independent targets, so the order
// Deployer runs them in (reverse registration order) does not matter.
before('deploy:shared', 'statik:copy_env');
before('deploy:shared', 'statik:copy_htaccess');

// Reload PHP-FPM immediately after the new release symlink is in place so
// requests pick up the new code path. statik:reload-phpfpm debounces and
// validates internally — see recipe/tasks/reload-phpfpm.php.
after('deploy:symlink', 'statik:reload-phpfpm');

// Run Voight as a post-deploy step. Mirrors the working KNXCOU wiring:
// deploy:success first fires from inside deploy:publish, then statik:voight
// runs, then deploy:success fires once more (idempotent banner) so the final
// notification reflects the post-Voight state.
after('deploy', 'statik:voight');
after('statik:voight', 'deploy:success');

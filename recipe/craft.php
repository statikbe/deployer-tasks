<?php

namespace Deployer;

// Deployer resolves recipe/* via its own include path (vendor/deployer/deployer/recipe/...).
require 'recipe/craftcms.php';
require __DIR__.'/tasks/reload-phpfpm.php';
require __DIR__.'/tasks/voight.php';
require __DIR__.'/tasks/copy-stage-files.php';

// Statik.be opinionated defaults
set('keep_releases', 5);
set('writable_mode', 'chown'); // Combell hosts do not have ACL installed (.env already shared by recipe/craftcms.php)
set('public_path', 'web'); // Craft serves from web/ (the base craftcms recipe does not set public_path)

// Seed the stage-specific webroot .htaccess before deploy:shared. No-ops unless
// htaccess_file is set (typically per host). copy_env is intentionally not wired
// here — Craft env handling is managed separately.
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

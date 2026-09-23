<?php

namespace Deployer;

// Statik.be opinionated defaults
// Combell hosting exposes the reloadPHP.sh control-panel script that this task
// drives. Hosts that are not on Combell set this to false to skip the task.
set('combell_hosting', true);

// Deployer resolves recipe/* via its own include path (vendor/deployer/deployer/recipe/...).
require 'recipe/craft.php';
require __DIR__.'/tasks/reload-phpfpm.php';
require __DIR__.'/tasks/voight.php';
require __DIR__.'/tasks/copy-stage-files.php';


set('keep_releases', 3);
if (get('combell_hosting')) {
    set('writable_mode', 'skip'); // Combell has the same user for file upload as www-data user, this makes deployment significantly faster.
}
else {
    set('writable_mode', 'chown'); // Combell hosts do not have ACL installed (.env already shared by recipe/laravel.php)
}

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

<?php
namespace Deployer;

// Deployer resolves recipe/* via its own include path (vendor/deployer/deployer/recipe/...).
require 'recipe/craftcms.php';
require __DIR__ . '/tasks/voight.php';

// Statik.be opinionated defaults
set('keep_releases', 5);
set('writable_mode', 'chown'); // Combell hosts do not have ACL installed

// Run Voight as a post-deploy step. Mirrors the working KNXCOU wiring:
// deploy:success first fires from inside deploy:publish, then statik:voight
// runs, then deploy:success fires once more (idempotent banner) so the final
// notification reflects the post-Voight state.
after('deploy', 'statik:voight');
after('statik:voight', 'deploy:success');

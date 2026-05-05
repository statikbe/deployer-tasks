<?php
namespace Deployer;

require 'recipe/laravel.php';

require __DIR__ . '/tasks/voight.php';

// Statik.be opinionated defaults
set('keep_releases', 5);
set('writable_mode', 'chown'); // Combell hosts do not have ACL installed
add('shared_files', ['.env']);

// Run Voight after the deploy completes, before the success notification
after('deploy', 'statik:voight');
after('statik:voight', 'deploy:success');

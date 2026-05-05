<?php
namespace Deployer;

require __DIR__ . '/../../recipe/laravel.php';

set('application', 'fixture');
set('repository', 'git@example.com:fixture/fixture.git');

host('fixture.example.org')
    ->set('deploy_path', '/var/www/fixture');

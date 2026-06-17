# statikbe/deployer-tasks

Reusable [Deployer 8](https://deployer.org/) tasks and recipes for Statik.be projects.

## Requirements

- PHP 8.3+
- Deployer 8.x

## Installation

```bash
composer require --dev statikbe/deployer-tasks
```

Or use our bitbucket images which already have this package installed. 
If you update this package, please rebuild the bitbucket Docker images so the new changes are usable in the CI/CD pipelines. 

## Usage

### Laravel projects

```php
<?php
namespace Deployer;

require 'vendor/statikbe/deployer-tasks/recipe/laravel.php';

// regular deployer host configuration:
set('application', 'my-app');
set('repository', 'git@github.com:statikbe/my-app.git');

host('production')
    ->set('hostname', 'prod.example.org')
    ->set('deploy_path', '/var/www/my-app');
```

### Craft CMS projects

```php
<?php
namespace Deployer;

require 'vendor/statikbe/deployer-tasks/recipe/craft.php';

// ...host config
```

### À la carte

Require an individual task file and wire the hook yourself:

```php
require 'vendor/statikbe/deployer-tasks/recipe/tasks/voight.php';
after('deploy', 'statik:voight');
```

## Available tasks

| Task | Description |
|---|---|
| `statik:reload-phpfpm` | Reload PHP-FPM safely with mutex, debounce, and opcache validation. Wired by both starters to `after('deploy:symlink', ...)`. Combell-specific — set `combell_hosting` to `false` (globally or per-host) to skip it on non-Combell hosts. |
| `statik:copy_env` | Copy the stage-specific `.env` file (`env_file`, relative to `release_path`) into `{{deploy_path}}/shared/.env`. No-ops when `env_file` is unset. Wired by the **Laravel** starter to `before('deploy:shared', ...)`. |
| `statik:copy_htaccess` | Copy the stage-specific htaccess file (`htaccess_file`, relative to `release_path`) into `{{public_path}}/.htaccess`. No-ops when `htaccess_file` is unset. Wired by both starters to `before('deploy:shared', ...)`. |
| `statik:voight` | Download and run the Voight versioning script in the release path. |

`env_file` and `htaccess_file` are read with an inline `null` default, so they never shadow a value set per host (e.g. in `hosts.yml`) or globally. Set them per host:

```yaml
# hosts.yml
production:
  hostname: prod.example.org
  deploy_path: /var/www/my-app
  env_file: .env.production
  htaccess_file: .htaccess.production
```

`statik:copy_htaccess` (and `statik:reload-phpfpm`) resolve the web root via `{{public_path}}`. The Laravel base recipe sets this to `public`; the Craft starter sets it to `web`. If you require either task à la carte without a framework recipe, set `public_path` yourself.

More tasks (composer install with secret env, local-build asset rsync, maintenance banner, config-file sync) ship in upcoming releases.

### reload-phpfpm

If the probe URL is behind `.htaccess` basic auth, configure the credentials in your project's `deploy.php` after the `require` line:

```php
require 'vendor/statikbe/deployer-tasks/recipe/laravel.php';

set('basic_auth_user', 'htaccess_user');
set('basic_auth_password', 'htaccess_password');

host('production')->set(/* ... */);
```

Both values must be set; if either is empty the task runs without auth. You can also source them from the environment (e.g. `set('basic_auth_user', getenv('BASIC_AUTH_USER'));`) — handy for Bitbucket Pipelines repository variables. Per-host overrides work too: `host('staging')->set('basic_auth_user', '...');`.

The application's `.env` no longer needs `BASIC_AUTH_USER` / `BASIC_AUTH_PASSWORD` for this task. Existing projects should remove those keys from `.env` and move them to `deploy.php` via the `set()` calls above.

## Development

```bash
composer install
composer test       # Pest — currently no tests; helper classes in src/ will get unit tests
composer format     # Laravel Pint
composer analyse    # PHPStan (exits non-zero until src/ has PHP files; recipe/ is intentionally excluded since Deployer's global functions need stubs)
```

The recipes are smoke-tested by loading them through Deployer:

```bash
vendor/bin/dep --file=tests/fixtures/laravel-deploy.php list
vendor/bin/dep --file=tests/fixtures/craft-deploy.php list
```

## License

MIT — see [LICENSE](LICENSE).

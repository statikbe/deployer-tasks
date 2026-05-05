# statikbe/deployer-tasks

Reusable [Deployer 8](https://deployer.org/) tasks and recipes for Statik.be projects.

## Requirements

- PHP 8.3+
- Deployer 8.x

## Installation

```bash
composer require --dev statikbe/deployer-tasks
```

## Usage

### Laravel projects

```php
<?php
namespace Deployer;

require 'vendor/statikbe/deployer-tasks/recipe/laravel.php';

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
| `statik:voight` | Download and run the Voight versioning script in the release path. |

More tasks (composer install with secret env, local-build asset rsync, maintenance banner, hosting helpers, config-file sync) ship in upcoming releases.

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

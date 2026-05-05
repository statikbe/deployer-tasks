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

## License

MIT — see [LICENSE](LICENSE).

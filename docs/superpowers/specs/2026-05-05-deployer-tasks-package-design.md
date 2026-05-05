# Design: `statikbe/deployer-tasks` package — initial structure

**Date:** 2026-05-05
**Status:** Approved (scaffold scope only — task bodies out of scope for this spec)
**Owner:** Statik.be

## Purpose

A reusable Composer package providing Deployer 8 tasks and recipes that Statik.be uses across Laravel and Craft CMS projects. Eliminates copy-pasting the same task code into every project's `deploy.php`.

## Scope

**In scope:**
- Project skeleton (directories, `composer.json`, `README.md`, basic dev tooling).
- Conventions for task files, framework starter recipes, naming, and namespacing.
- Empty/stub files for each planned task and starter so the package installs and the structure is established.

**Out of scope (future specs):**
- Task body implementations (Voight integration, opcache reset, asset rsync, etc.).
- CI configuration.
- Releasing to Packagist.

## Requirements

| # | Requirement |
|---|---|
| R1 | Target Deployer 8.x only; PHP 8.3+. |
| R2 | Provide framework "starter" recipes for Laravel and Craft CMS that compose the official Deployer recipes plus Statik.be tasks and defaults. |
| R3 | Provide individual task files that can be required à-la-carte in any `deploy.php`, independent of the framework starters. |
| R4 | Cover these tasks: composer install with optimized autoloader + secret env injection; build assets locally and rsync to release; framework-independent maintenance mode banner; sync env-specific config files; send dependency lock files to Voight vulnerability scanner; hosting helpers (opcache reset, php-fpm reload, queue restart, cron sync). |
| R5 | Task names must be prefixed `statik:` to avoid collisions with Deployer built-ins. |
| R6 | Task files must not register `before`/`after` hooks; only the framework starters wire hooks. |
| R7 | Helper PHP classes (when needed) live under PSR-4 `Statikbe\DeployerTasks\` in `src/`. |
| R8 | Use Pest 3 for tests; PHPStan 2 for static analysis. |

## Architecture

### Directory layout

```
deployer-tasks/
├── composer.json
├── LICENSE
├── README.md
├── recipe/
│   ├── laravel.php            # Statik.be Laravel starter
│   ├── craft.php              # Statik.be Craft CMS starter
│   └── tasks/
│       ├── composer.php       # Optimized composer install + secret env
│       ├── assets-rsync.php   # Local build → rsync to release
│       ├── maintenance.php    # Framework-independent maintenance banner
│       ├── voight.php         # Send lock files to Voight scanner
│       ├── config-sync.php    # Upload env-specific config files
│       └── hosting.php        # opcache reset, php-fpm reload, queue restart, cron sync
├── src/                       # PSR-4 root for helper classes (initially empty)
└── tests/                     # Pest tests (initially empty, add as helpers appear)
```

### Composition model

- **Per-task files** (`recipe/tasks/*.php`) are leaf modules. Each declares `namespace Deployer;`, defines defaults via `set()`, and registers one or more tasks via `desc()` + `task()`. They have no `before`/`after` hooks and require no other task files.
- **Framework starters** (`recipe/laravel.php`, `recipe/craft.php`) are composition roots. They `require` Deployer's official framework recipe (`recipe/laravel.php` or `recipe/craftcms.php`), then `require` the per-task files they need, then set Statik.be opinionated defaults and wire `before`/`after` hooks into the deploy flow.
- **End-user `deploy.php`** picks one of:
  - Require a framework starter for the full opinionated experience.
  - Require individual task files for à-la-carte composition.

### Naming conventions

- Tasks: `statik:<concern>` for single-action concerns (e.g., `statik:voight`), or `statik:<concern>:<verb>` when a concern has multiple actions (e.g., `statik:hosting:opcache_reset`, `statik:hosting:queue_restart`, `statik:assets:build_and_sync`).
- Configuration keys (`set('...')`): `statik_<concern>_<key>` (e.g., `statik_voight_endpoint`, `statik_voight_lock_files`). Prefix prevents collisions with Deployer's built-in keys.
- Helper PHP classes: `Statikbe\DeployerTasks\<Concern>\<ClassName>`.

### Why not put tasks in a Statikbe namespace?

Deployer's `task()` function registers tasks against the global `Deployer` namespace. Task files must use `namespace Deployer;` so the function is in scope. Helper *classes* are different — they're invoked from inside task closures and live in our own PSR-4 namespace.

## Component templates

### `composer.json`

```json
{
    "name": "statikbe/deployer-tasks",
    "description": "Reusable Deployer 8 tasks for Statik.be projects (Laravel, Craft CMS, common integrations).",
    "type": "library",
    "license": "MIT",
    "keywords": ["deployer", "deployment", "laravel", "craftcms", "statikbe"],
    "homepage": "https://github.com/statikbe/deployer-tasks",
    "require": {
        "php": "^8.3",
        "deployer/deployer": "^8.0"
    },
    "require-dev": {
        "pestphp/pest": "^3.0",
        "phpstan/phpstan": "^2.0"
    },
    "autoload": {
        "psr-4": {
            "Statikbe\\DeployerTasks\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Statikbe\\DeployerTasks\\Tests\\": "tests/"
        }
    },
    "minimum-stability": "stable",
    "prefer-stable": true,
    "config": {
        "sort-packages": true,
        "allow-plugins": {
            "pestphp/pest-plugin": true
        }
    }
}
```

### Task file template (`recipe/tasks/<name>.php`)

```php
<?php
namespace Deployer;

// Defaults — consumers override via set() in their deploy.php
set('statik_voight_endpoint', 'https://voight.statik.be/api/scan');
set('statik_voight_lock_files', ['composer.lock', 'package-lock.json']);

desc('Send dependency lock files to Voight vulnerability scanner');
task('statik:voight:scan', function () {
    // TODO: implementation in follow-up spec
});
```

### Framework starter template (`recipe/laravel.php`)

```php
<?php
namespace Deployer;

require 'recipe/laravel.php';

require __DIR__ . '/tasks/composer.php';
require __DIR__ . '/tasks/assets-rsync.php';
require __DIR__ . '/tasks/maintenance.php';
require __DIR__ . '/tasks/voight.php';
require __DIR__ . '/tasks/config-sync.php';
require __DIR__ . '/tasks/hosting.php';

set('keep_releases', 5);
add('shared_files', ['.env']);

before('deploy:vendors', 'statik:voight:scan');
after('deploy:symlink', 'statik:hosting:opcache_reset');
```

`recipe/craft.php` follows the same pattern but uses `require 'recipe/craftcms.php';` and Craft-appropriate shared files (e.g., `.env`, `config/license.key`, `config/project`).

### End-user `deploy.php` (illustrative — lives in consumer's project, not in this package)

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

À-la-carte usage:

```php
require 'vendor/statikbe/deployer-tasks/recipe/tasks/voight.php';
before('deploy:vendors', 'statik:voight:scan');
```

## Testing strategy

- **Helper classes in `src/`** — unit-test with Pest.
- **Task closures themselves** — not unit-tested. Deployer tasks call `run()`, `upload()`, etc., which require an SSH host. Verified manually against a staging server (and later, optionally, against a Docker-based fixture in CI).
- `tests/` directory ships empty in the initial scaffold; tests are added when helper classes appear.

## Open assumptions

- Vendor name `statikbe/deployer-tasks` matches existing Statik.be Packagist conventions.
- Repository URL `https://github.com/statikbe/deployer-tasks`.
- Pest 3 + PHPStan 2 are acceptable dev tooling; no other linters/formatters in scope for the initial scaffold.

## Deliverables for the implementation plan

1. `composer.json` as specified above.
2. `README.md` documenting installation and per-task usage.
3. `recipe/laravel.php` and `recipe/craft.php` starter stubs that compose Deployer's official recipes + the task files + Statik.be defaults.
4. Six stub task files in `recipe/tasks/` — each with `namespace Deployer;`, `desc()`, `task()` shell, sensible `set()` defaults, and a `// TODO` for the body.
5. Empty `src/` and `tests/` directories with a `.gitkeep` file in each (Git does not track empty directories).
6. `phpstan.neon` (level 6 starting point, scanning `src/` only — `recipe/` uses Deployer's global functions which need the Deployer stubs) and a Pest config (`tests/Pest.php`, no test classes yet).

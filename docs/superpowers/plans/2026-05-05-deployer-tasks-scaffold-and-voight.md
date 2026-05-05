# Deployer Tasks: Scaffold + Voight Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Scaffold the `statikbe/deployer-tasks` Composer package, two framework starter recipes (Laravel, Craft CMS), and one working task (`statik:voight`) that downloads and runs the Voight versioning script.

**Architecture:** A standard Composer library with a `recipe/` directory holding Deployer task files. Per-task files in `recipe/tasks/` are leaf modules; framework starters in `recipe/` compose them with Deployer's official recipes and Statik.be defaults. PSR-4 `Statikbe\DeployerTasks\` namespace reserved for future helper classes under `src/`.

**Tech Stack:** PHP 8.3+, Deployer 8.x, Composer, Pest 3 (tests), PHPStan 2 (static analysis).

**Spec:** [`docs/superpowers/specs/2026-05-05-deployer-tasks-package-design.md`](../specs/2026-05-05-deployer-tasks-package-design.md)

---

## File Structure

Files created by this plan, in execution order:

| Path | Purpose | Created in |
|---|---|---|
| `composer.json` | Package manifest, deps, autoload | Task 1 |
| `.gitignore` | Ignore vendor/, composer.lock, .phpunit.cache, etc. | Task 1 |
| `README.md` | Installation + usage notes | Task 1 |
| `src/.gitkeep` | Keep empty PSR-4 dir tracked | Task 1 |
| `tests/.gitkeep` | Keep empty tests dir tracked | Task 1 |
| `recipe/tasks/.gitkeep` | Keep dir tracked before voight.php is added | Task 1 |
| `tests/Pest.php` | Pest bootstrap | Task 2 |
| `phpstan.neon` | Static analysis config (level 6, scan `src/` only) | Task 2 |
| `recipe/tasks/voight.php` | Voight task implementation | Task 3 |
| `recipe/laravel.php` | Statik.be Laravel starter recipe | Task 4 |
| `recipe/craft.php` | Statik.be Craft CMS starter recipe | Task 5 |
| `tests/fixtures/laravel-deploy.php` | Smoke-test fixture for Laravel starter | Task 6 |
| `tests/fixtures/craft-deploy.php` | Smoke-test fixture for Craft starter | Task 6 |

---

## Task 1: Initialize package skeleton

**Files:**
- Create: `composer.json`
- Create: `.gitignore`
- Create: `README.md`
- Create: `src/.gitkeep`
- Create: `tests/.gitkeep`
- Create: `recipe/tasks/.gitkeep`

- [ ] **Step 1: Write `composer.json`**

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

- [ ] **Step 2: Write `.gitignore`**

```gitignore
/vendor/
/composer.lock
/.phpunit.cache/
/.phpunit.result.cache
/.phpstan.cache/
/.idea/
/.vscode/
.DS_Store
```

Note: `composer.lock` is ignored because this is a library, not an application.

- [ ] **Step 3: Write minimal `README.md`**

````markdown
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
````

- [ ] **Step 4: Create directory placeholders**

Run:
```bash
mkdir -p src tests recipe/tasks
touch src/.gitkeep tests/.gitkeep recipe/tasks/.gitkeep
```

- [ ] **Step 5: Install dependencies**

Run: `composer install`
Expected: creates `vendor/` with `deployer/deployer`, `pestphp/pest`, `phpstan/phpstan`. No errors.

- [ ] **Step 6: Validate composer.json**

Run: `composer validate --strict`
Expected: `./composer.json is valid`

- [ ] **Step 7: Commit**

```bash
git add composer.json .gitignore README.md src/.gitkeep tests/.gitkeep recipe/tasks/.gitkeep
git commit -m "chore: initialize package skeleton with composer.json and dirs"
```

---

## Task 2: Dev tooling configs (Pest + PHPStan)

**Files:**
- Create: `tests/Pest.php`
- Create: `phpstan.neon`

- [ ] **Step 1: Write `tests/Pest.php`**

```php
<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

// pest()->extend(Tests\TestCase::class)->in('Feature');
```

This is the standard Pest 3 bootstrap. Currently empty because we have no test classes.

- [ ] **Step 2: Write `phpstan.neon`**

```neon
parameters:
    level: 6
    paths:
        - src
```

`recipe/` is excluded because Deployer's task files use global `Deployer\` namespace functions (`task()`, `set()`, `run()`) which need Deployer's PHPStan stubs to type-check cleanly. Adding that wiring is out of scope for the initial scaffold.

- [ ] **Step 3: Run Pest to verify it loads**

Run: `vendor/bin/pest`
Expected: `No tests found.` (or similar — exit code 0). Confirms Pest is wired up.

- [ ] **Step 4: Run PHPStan to verify it loads**

Run: `vendor/bin/phpstan analyse`
Expected: `[OK] No errors` (src/ contains only .gitkeep, so nothing to analyse).

- [ ] **Step 5: Commit**

```bash
git add tests/Pest.php phpstan.neon
git commit -m "chore: add Pest and PHPStan dev tooling configs"
```

---

## Task 3: Implement `statik:voight` task

**Files:**
- Create: `recipe/tasks/voight.php`

The task downloads `voight.sh` from the Voight server into the current release path, runs it, and removes it. Curl flags are stricter than the original bash script (`-fsS` fails on HTTP errors with visible error message but no progress meter) so a server-side outage causes the deploy to fail loudly instead of silently downloading an empty file.

- [ ] **Step 1: Write `recipe/tasks/voight.php`**

```php
<?php
namespace Deployer;

set('statik_voight_script_url', 'https://voight.thekindkids.be/scripts/voight.sh');

desc('Download and run the Voight versioning script in the release path');
task('statik:voight', function () {
    cd('{{release_path}}');
    run('curl -fsS -X POST -H "Content-Type: application/json" {{statik_voight_script_url}} -o voight.sh');
    run('chmod +x voight.sh');
    run('bash voight.sh');
    run('rm -f voight.sh');
});
```

- [ ] **Step 2: Lint the file**

Run: `php -l recipe/tasks/voight.php`
Expected: `No syntax errors detected in recipe/tasks/voight.php`

- [ ] **Step 3: Commit**

```bash
git add recipe/tasks/voight.php
git commit -m "feat: add statik:voight task that runs Voight versioning script"
```

---

## Task 4: Implement Laravel starter recipe

**Files:**
- Create: `recipe/laravel.php`

This file composes Deployer's official Laravel recipe with our voight task and Statik.be opinionated defaults. The hook order mirrors KNXCOU's working setup: voight runs after the main `deploy` task group, and `deploy:success` runs after voight.

- [ ] **Step 1: Write `recipe/laravel.php`**

```php
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
```

- [ ] **Step 2: Lint the file**

Run: `php -l recipe/laravel.php`
Expected: `No syntax errors detected in recipe/laravel.php`

- [ ] **Step 3: Commit**

```bash
git add recipe/laravel.php
git commit -m "feat: add Laravel starter recipe wiring voight into deploy flow"
```

---

## Task 5: Implement Craft CMS starter recipe

**Files:**
- Create: `recipe/craft.php`

Mirrors the Laravel starter but composes Deployer's `recipe/craftcms.php` (which already sets sensible Craft defaults: `shared_files=['.env']`, `shared_dirs=['storage', 'web/assets']`, `writable_dirs=['config/project', 'storage', 'web/assets', 'web/cpresources']`).

- [ ] **Step 1: Write `recipe/craft.php`**

```php
<?php
namespace Deployer;

require 'recipe/craftcms.php';

require __DIR__ . '/tasks/voight.php';

// Statik.be opinionated defaults
set('keep_releases', 5);
set('writable_mode', 'chown'); // Combell hosts do not have ACL installed

// Run Voight after the deploy completes, before the success notification
after('deploy', 'statik:voight');
after('statik:voight', 'deploy:success');
```

- [ ] **Step 2: Lint the file**

Run: `php -l recipe/craft.php`
Expected: `No syntax errors detected in recipe/craft.php`

- [ ] **Step 3: Commit**

```bash
git add recipe/craft.php
git commit -m "feat: add Craft CMS starter recipe wiring voight into deploy flow"
```

---

## Task 6: End-to-end smoke test via Deployer CLI

**Files:**
- Create: `tests/fixtures/laravel-deploy.php`
- Create: `tests/fixtures/craft-deploy.php`

Deployer task closures need a real SSH host to execute, so we cannot unit-test them in isolation. Instead we verify that the recipes load cleanly and that `statik:voight` is registered by running `dep list` against a fixture deploy file. If the recipe has a syntax error, missing require, or unknown task name in a hook, this command will fail.

- [ ] **Step 1: Write `tests/fixtures/laravel-deploy.php`**

```php
<?php
namespace Deployer;

require __DIR__ . '/../../recipe/laravel.php';

set('application', 'fixture');
set('repository', 'git@example.com:fixture/fixture.git');

host('fixture.example.org')
    ->set('deploy_path', '/var/www/fixture');
```

- [ ] **Step 2: Write `tests/fixtures/craft-deploy.php`**

```php
<?php
namespace Deployer;

require __DIR__ . '/../../recipe/craft.php';

set('application', 'fixture');
set('repository', 'git@example.com:fixture/fixture.git');

host('fixture.example.org')
    ->set('deploy_path', '/var/www/fixture');
```

- [ ] **Step 3: Verify Laravel fixture loads and registers `statik:voight`**

Run: `vendor/bin/dep --file=tests/fixtures/laravel-deploy.php list`
Expected: output includes a line containing `statik:voight` and `Download and run the Voight versioning script in the release path`. Exit code 0.

- [ ] **Step 4: Verify Craft fixture loads and registers `statik:voight`**

Run: `vendor/bin/dep --file=tests/fixtures/craft-deploy.php list`
Expected: output includes `statik:voight` line. Exit code 0.

- [ ] **Step 5: Verify hook wiring by inspecting the deploy task**

Run: `vendor/bin/dep --file=tests/fixtures/laravel-deploy.php tree deploy`
Expected: output ends with a tree that includes `statik:voight` after the deploy chain (it appears as an `after` hook of the `deploy` task). Exit code 0.

- [ ] **Step 6: Commit**

```bash
git add tests/fixtures/laravel-deploy.php tests/fixtures/craft-deploy.php
git commit -m "test: add fixture deploy files for recipe smoke tests"
```

---

## Final verification

- [ ] **Step 1: Confirm all checks still pass**

Run sequentially:
```bash
composer validate --strict
vendor/bin/pest
vendor/bin/phpstan analyse
php -l recipe/laravel.php
php -l recipe/craft.php
php -l recipe/tasks/voight.php
vendor/bin/dep --file=tests/fixtures/laravel-deploy.php list | grep -q statik:voight
vendor/bin/dep --file=tests/fixtures/craft-deploy.php list  | grep -q statik:voight
```

Expected: every command exits 0.

- [ ] **Step 2: Review final file tree**

Run: `git ls-files`
Expected:
```
.gitignore
LICENSE
README.md
composer.json
docs/superpowers/plans/2026-05-05-deployer-tasks-scaffold-and-voight.md
docs/superpowers/specs/2026-05-05-deployer-tasks-package-design.md
phpstan.neon
recipe/craft.php
recipe/laravel.php
recipe/tasks/.gitkeep
recipe/tasks/voight.php
src/.gitkeep
tests/.gitkeep
tests/Pest.php
tests/fixtures/craft-deploy.php
tests/fixtures/laravel-deploy.php
```

---

## Out of scope (future work)

- Implementing the other five tasks from the spec (`composer`, `assets-rsync`, `maintenance`, `config-sync`, `hosting`).
- Removing `recipe/tasks/.gitkeep` (delete it once a second task file is added).
- CI workflow (GitHub Actions running phpstan + pest on push).
- Tagging a v0.1.0 release and publishing to Packagist.

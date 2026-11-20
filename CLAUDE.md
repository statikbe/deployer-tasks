# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`statikbe/deployer-tasks`: reusable [Deployer 8](https://deployer.org/) tasks and starter recipes for Statik.be Laravel and Craft CMS projects (PHP 8.3+). Consumers `require 'vendor/statikbe/deployer-tasks/recipe/{laravel,craft}.php'` in their `deploy.php`, or require single files from `recipe/tasks/` à la carte. The package is preinstalled in Statik's Bitbucket pipeline Docker images, so those images must be rebuilt after a release.

## Commands

```bash
composer test       # Pest (no real tests yet; tests/ only holds fixtures)
composer format     # Laravel Pint
composer analyse    # PHPStan level 6 on src/ only (recipe/ excluded: Deployer globals have no stubs)

# Smoke test: load each starter through Deployer and list tasks
vendor/bin/dep --file=tests/fixtures/laravel-deploy.php list
vendor/bin/dep --file=tests/fixtures/craft-deploy.php list
```

The `dep list` smoke tests are the main verification for recipe changes: they catch missing `require`s and hooks that reference undefined tasks.

## Architecture

- `recipe/laravel.php`, `recipe/craft.php`: starters. Each sets Statik defaults, then `require 'recipe/<framework>.php'` (resolved via Deployer's own include path, not this repo), requires the task files, and wires hooks. Keep the two in sync where behaviour is shared.
- `recipe/tasks/*.php`: one file per task group, all in `namespace Deployer`, task names prefixed `statik:`. Tasks must not depend on a starter so they work à la carte.
- `src/`: PSR-4 `Statikbe\DeployerTasks\` for helper classes (currently empty). Logic that needs unit tests or PHPStan coverage belongs here, not in recipe files.
- `docs/superpowers/`: design specs and plans for past features; read the relevant spec before changing that feature.

### Conventions that matter

- **Optional per-host settings** (`env_file`, `htaccess_file`, `robots_file`, `basic_auth_*`) are read with `get('x', null)` inside the task body, never declared with `set()`, so they can't shadow values from `hosts.yml` or per-host config.
- **`combell_hosting`** (default `true`) controls Combell-specific behaviour: `writable_mode` is `skip` on Combell, `chown` otherwise, and `statik:reload-phpfpm` checks it *inside the task body* so mixed Combell/non-Combell deploys work per host.
- **`public_path`** is the web root: `public` from Deployer's Laravel recipe, `web` for Craft. À la carte users must set it themselves.
- Deployer runs multiple `before()` hooks on the same task in reverse registration order.
- Tunables use the `statik_<task>_*` setting prefix (see the top of `reload-phpfpm.php`).

### statik:reload-phpfpm

The most complex task. It runs after `deploy:symlink`, waits for `current` to point at the new release, uploads a randomly named opcache probe (`recipe/tasks/stubs/opcache-probe.php`) into the new release and mirrors it into `previous_release`, fetches it over HTTPS (`statik_reload_phpfpm_host`, optional basic auth via `curl -u`, never in the URL), then calls `reloadPHP.sh` behind a `pgrep` mutex. A reload counts as validated only when the opcache `start_time` advanced, is fresh, and the probe was served from the new release (matched on release-relative path suffix). It debounces if a sibling deploy reloaded recently, and the `finally` block always removes the probes.

### Voight

`statik:voight` POSTs to `statik_voight_script_url` to download `voight.sh`, runs it in the release path and deletes it. Starters hook it `after('deploy')`, then re-fire `deploy:success` after it.

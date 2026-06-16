# Design: `statik:copy_env` + `statik:copy_htaccess` stage-file tasks

**Date:** 2026-06-16
**Status:** Implemented
**Owner:** Statik.be

## Purpose

Statik.be projects keep stage-specific `.env` and `.htaccess` files in the repo
(e.g. `.env.production`, `.htaccess.staging`) and copy the right one into place
during deploy. These two tasks formalize that copy-paste pattern so projects stop
redefining the tasks in every `deploy.php`.

## Scope

**In scope:**
- `statik:copy_env` — copy `{{release_path}}/<env_file>` to `{{deploy_path}}/shared/.env`.
- `statik:copy_htaccess` — copy `{{release_path}}/<htaccess_file>` to `{{release_path}}/{{public_path}}/.htaccess`.
- Wiring in the starter recipes via `before('deploy:shared', ...)`.
- `set('public_path', 'web')` in the Craft starter (the base craftcms recipe does not set it).
- Switching every `statik:reload-phpfpm` probe path (upload, on-disk diagnostic, and cleanup `rm`, for both the release and the `previous_release` mirror) from a hardcoded `public/` to `{{public_path}}` for the same webroot-correctness reason. These must stay consistent: an upload to `{{public_path}}` with a cleanup against `public/` would leak the probe on Craft (`web/`).

**Out of scope:**
- An rsync-based deploy flow. Projects that replace `deploy:update_code` with
  rsync keep doing so in their own `deploy.php`; these tasks compose with that
  (see Wiring).
- Wiring `statik:copy_env` into the Craft starter — Craft env handling is managed
  separately, so Craft wires only `statik:copy_htaccess`. The `copy_env` task is
  still defined (available à la carte), just not auto-run on Craft.

## Requirements

| # | Requirement |
|---|---|
| R1 | Both tasks no-op when their source var (`env_file` / `htaccess_file`) is unset, so they are safe to wire unconditionally. |
| R2 | Neither task may shadow a per-host or global value for `env_file` / `htaccess_file`. |
| R3 | `statik:copy_env` runs before `deploy:shared` so `shared/.env` is seeded before the shared symlink is created (matters on first deploy). |
| R4 | `statik:copy_htaccess` writes to the framework's real web root: `public/` on Laravel, `web/` on Craft. |
| R5 | The tasks compose with a project-level `deploy:prepare` override that injects rsync, without forcing rsync on other users. |

## Architecture

### Reading the source vars (R1, R2)

The tasks read `get('env_file', null)` / `get('htaccess_file', null)` with an
**inline default** rather than declaring the vars with `set()`. Deployer's
`Configuration::get()` (vendor `src/Configuration.php`):

- With a second argument, returns the default instead of throwing when the option
  does not exist — satisfies R1 without a global `set()`.
- A host's config has the global config as parent; the parent is only consulted
  when the host value is absent, and a parent value of `null` is explicitly
  skipped (`if ($rawValue !== null)`).

Because the tasks never call `set()` for these vars, there is nothing that can
overwrite a value defined per host (in `hosts.yml`) or globally — satisfying R2
regardless of recipe load order. This is the key correctness decision; an earlier
draft used `set('env_file', null)`, which could clobber a globally-set value if
the recipe loaded after the project's own `set()`.

### Web root (R4)

`copy_htaccess` targets `{{release_path}}/{{public_path}}/.htaccess`. `public_path`
is owned by the recipe layer:

- Laravel base recipe sets `public_path` = `public`.
- Craft base recipe does **not** set it, so the Craft starter sets `public_path` = `web`.

`public_path` is intentionally not defaulted inside the task file: it is a
recipe-level concern, and a task-file `set()` could clobber a per-recipe value
depending on require order. À-la-carte users without a framework recipe set it
themselves. The same `{{public_path}}` switch was applied to every probe path in
`statik:reload-phpfpm` (upload, on-disk diagnostic, and cleanup `rm`, for both
the release and the `previous_release` mirror), which previously hardcoded
`public/`.

### Wiring (R3, R5)

The starters wire `before('deploy:shared', 'statik:copy_env')` (Laravel only) and
`before('deploy:shared', 'statik:copy_htaccess')` (both). A `before()` hook is
attached to the `deploy:shared` task name, so it fires whenever `deploy:shared`
runs — including from inside a project's custom `deploy:prepare` that swaps
`deploy:update_code` for rsync. This composes with the rsync flow without the
package redefining `deploy:prepare` (which would force rsync on every user).

Migration note: a project moving to this package should **remove** explicit
`statik:copy_env` / `statik:copy_htaccess` entries from its own `deploy:prepare`
array, since the starter now wires them — otherwise they run twice (harmless, as
`cp` is idempotent, but redundant).

Deployer's `Task::addBefore()` uses `array_unshift`, so multiple `before()` hooks
on the same task run in reverse registration order. The two copy tasks write to
independent targets (`shared/.env` vs the web root), so their relative order is
irrelevant.

## Files changed

| File | Change |
|---|---|
| `recipe/tasks/copy-stage-files.php` | New — both tasks, reading `env_file` / `htaccess_file` with inline `null` defaults. |
| `recipe/laravel.php` | Require the file; `before('deploy:shared', ...)` for both copy tasks. |
| `recipe/craft.php` | Require the file; `before('deploy:shared', 'statik:copy_htaccess')`; `set('public_path', 'web')`. |
| `recipe/tasks/reload-phpfpm.php` | All six probe paths (upload/diagnostic/cleanup × release/mirror) `public/` → `{{public_path}}`. |
| `README.md` | Document both tasks, the `env_file` / `htaccess_file` / `public_path` vars, and the à-la-carte `public_path` caveat. |

## Verification

```bash
vendor/bin/dep --file=tests/fixtures/laravel-deploy.php list          # all four statik: tasks register
vendor/bin/dep --file=tests/fixtures/laravel-deploy.php tree deploy:shared  # both copy tasks hooked
vendor/bin/dep --file=tests/fixtures/craft-deploy.php tree deploy:shared    # only copy_htaccess hooked
```

The copy bodies require a live server (`run`) and are not unit-tested,
consistent with the package's current state.

# Design: `combell_hosting` toggle for `statik:reload-phpfpm`

**Date:** 2026-06-16
**Status:** Implemented
**Owner:** Statik.be

## Purpose

`statik:reload-phpfpm` drives `reloadPHP.sh`, the PHP-FPM reload script exposed by
the Combell hosting control panel. It is only meaningful on Combell hosts. Both
starter recipes wire it unconditionally, so projects deployed to non-Combell
infrastructure would attempt to run a command that does not exist. A toggle lets
those hosts opt out without forking the recipe.

## Scope

**In scope:**
- A `combell_hosting` Deployer var with a default, overridable globally or per-host.
- A guard in `statik:reload-phpfpm` that skips the task when the var is false.
- README documentation of the var.

**Out of scope:**
- Changing the `after('deploy:symlink', 'statik:reload-phpfpm')` wiring in the
  starter recipes — it stays unconditional; the task self-skips instead.
- Applying `combell_hosting` to any other task (none are Combell-specific today).
- Automated tests — the task body needs a live server (`run`/`upload`/`curl`);
  the existing fixture smoke tests cover recipe load only.

## Requirements

| # | Requirement |
|---|---|
| R1 | A `combell_hosting` var defaults to `true`, preserving current behavior (every existing Combell deploy keeps reloading without config changes). |
| R2 | A host opts out with `set('combell_hosting', false)` globally or `host(...)->set('combell_hosting', false)` per-host. |
| R3 | When `combell_hosting` is false, `statik:reload-phpfpm` does zero remote work (no path resolution, no probe upload, no `reloadPHP.sh`) and returns after printing a skip notice. |
| R4 | The decision is evaluated per-host at task run time, so a single deploy targeting a mix of Combell and non-Combell hosts behaves correctly. |

## Architecture

### The var

`set('combell_hosting', true);` lives in `recipe/tasks/reload-phpfpm.php`,
alongside the existing `statik_reload_phpfpm_*` defaults. It is defined in the
task file rather than the starter recipes because the var only governs this task
and that file is always loaded with it — keeping the toggle self-contained.

The name is intentionally generic (`combell_hosting`, a host-property fact)
rather than `statik_reload_phpfpm_*`-scoped, so a future Combell-only task can
reuse the same flag.

### The guard

The first statement in the `statik:reload-phpfpm` task body:

```php
if (! get('combell_hosting')) {
    writeln('<comment>statik:reload-phpfpm: skipping — not Combell hosting (combell_hosting=false)</comment>');

    return;
}
```

**Why guard-in-task, not conditional `after()`:** `after()` registers the hook
once at recipe-load time, globally. A guard inside the task body is evaluated
per-host when the task runs, so it satisfies R4 (mixed Combell / non-Combell
deploys). Conditional hook registration cannot — it would force one decision for
all hosts. This is the key design choice.

## Files changed

| File | Change |
|---|---|
| `recipe/tasks/reload-phpfpm.php` | Add `set('combell_hosting', true);` default and the early-return guard at the top of the task body. |
| `README.md` | Note in the `statik:reload-phpfpm` row that `combell_hosting => false` skips it on non-Combell hosts. |

`recipe/laravel.php` and `recipe/craft.php` are unchanged.

## Verification

The fixture smoke tests confirm the recipe still loads and the task registers:

```bash
vendor/bin/dep --file=tests/fixtures/laravel-deploy.php list
```

The skip path and the reload path require a live server and are not unit-tested,
consistent with the package's current "no task-body tests" state.
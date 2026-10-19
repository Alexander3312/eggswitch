# Egg Switch — technical reference

This is the detailed "how it currently works" reference. For the pitch, see [`README.md`](README.md); for *why* things are built this way, see [`architecture-eggswitch-addon.md`](architecture-eggswitch-addon.md); for known gaps, see [`TODO.md`](TODO.md).

## Developing & testing

There's no PHPUnit suite. Three ways to verify a change:

1. **On a dedicated development panel** — never on a production one: the extension changes servers' eggs and reinstalls them. To deploy a change, package the folder into a `.blueprint` (a plain zip of the repository, without `.git/`), then `blueprint -remove eggswitch` and `blueprint -i eggswitch.blueprint`.
2. **With a disposable server** for anything that actually reinstalls: every successful test wipes its files.
3. **With the checklist** at the end of this document, to replay after any change that touches `EggSwitchRequestService`, `EggSwitchExecutionService` or the job.

CI (`.github/workflows/check.yml`) only runs `php -l` on every PHP file.

## What's in the codebase

| Role | File(s) |
|---|---|
| Routes | `routers/web.php` |
| Level 1 guard (logged in and owner of at least one server) | `app/Http/Middleware/EnsureEggSwitchAllowed.php` |
| Level 2 guard (owner of the targeted server) | `app/Services/EggSwitchScopeService.php` |
| HTTP controller (thin layer) | `app/Http/Controllers/EggSwitchController.php` |
| Single entry point for a request (checks, request creation, safety backup, cancellation) | `app/Services/EggSwitchRequestService.php` |
| Execution: egg change, then reinstall | `app/Services/EggSwitchExecutionService.php` |
| Waiting for the backup in the background | `app/Jobs/WaitForBackupThenSwitchEggJob.php` |
| Requests in progress | `app/Models/EggSwitchPendingReinstall.php` |
| Whitelisted eggs: model, and the facade used by the rest of the code and by other extensions | `app/Models/AllowedEgg.php`, `app/Support/EggWhitelist.php` |
| Admin screen for whitelisted eggs | `admin/controller.php`, `admin/view.blade.php` |
| "Switch game" tab and console banner | `components/sections/EggSwitchSection.tsx`, `components/sections/EggSwitchBanner.tsx`, `components/Components.yml` |
| Tables | `database/migrations/` |

## Tables

- **`eggswitch_allowed_eggs`**: one row per whitelisted egg (unique `egg_id`, `label`, `description`, boolean `self_service` and `for_addon`). No foreign key to `eggs`: the entry of a deleted egg stays visible in the admin screen so it can be cleaned up, and the user-facing list ignores IDs that no longer exist. Created empty.
- **`eggswitch_pending_reinstalls`**: one row per request (`server_id`, `target_egg_id`, `docker_image`, `environment` as JSON, `with_backup`, `backup_id`, `status`, `requested_by`, `requested_at`, `cancelled_at`, `failed_reason`). It holds the current state; the history lives in the panel's activity log. Cascading foreign keys tie it to the server, the target egg and the requester: deleting any of them deletes the request (the linked backup is simply detached). Only one active row (`waiting_backup`, `ready_to_wipe` or `wiping`) per server; this rule is enforced by the code, not by an index.

## HTTP routes (`/extensions/eggswitch`)

All go through `auth`, `auth.session`, `EnsureTwoFactorWhenRequired` (the panel's mandatory two-factor setting: an account that must use 2FA and hasn't set it up gets the panel's JSON 400) and `EnsureEggSwitchAllowed`. On top of that, every controller action checks that the user owns the targeted server, reads included.

| Method and route | Throttle | Role |
|---|---|---|
| `GET /eggs?server={uuid}` | 60 per minute (group `eggswitch-read`) | Self-service eggs grouped by nest, with their user-editable and user-viewable variables; current egg; backup limit and count; node state (responding or not) |
| `GET /status?server={uuid}` | 60 per minute (group `eggswitch-read`) | The server's active request, or `null` |
| `POST /reinstall` | 10 per 60 minutes (group `eggswitch-write`) | Creates a request; replies 202 |
| `POST /cancel` | 10 per 60 minutes (group `eggswitch-write`) | Cancels the active request if it hasn't started yet |

The throttle's third parameter is a key prefix: without it, Laravel counts requests per user only, and the two groups would share the same counter.

`POST /reinstall` expects `server_uuid`, `egg_id`, `confirm_server_name` (exactly the server's name), and optionally `with_backup` (boolean) and `environment` (a `{VARIABLE: value}` object). There's no field for the Docker image: this route always takes the egg's default image.

The responses of `/status`, `/reinstall` and `/cancel` only return the request's `id`, `status`, `with_backup`, `failed_reason` and `target_egg_id`.

## How a request flows

Before the service is reached, the middleware rejects an account that owns no server (403). An unknown UUID gives 404, and a missing `server` on `/eggs` or `/status` gives 422.

Then everything goes through `EggSwitchRequestService::handleReinstallRequest()`, in this order:

1. The server belongs to the user (`assertOwnsServer`; 403 otherwise).
2. The server's state allows it (`validateCurrentState()`: not suspended, node not under maintenance, no install in progress or failed first install (`installing` / `install_failed`), no restore or transfer in progress; a server in `reinstall_failed` is allowed); otherwise 409.
3. The confirmation name matches (422).
4. The egg is in the right whitelist (422), and still exists on the panel (404 otherwise).
5. The Docker image is resolved (see below).
6. The server can be reinstalled (`canBeReinstalled()`, 409 otherwise: notably rejects a server configured to skip install scripts) and its node responds (503 otherwise, before any write).
7. The environment is filtered and completed (see below), then validated against the variables' rules (422).
8. In a transaction that locks the server's row: rejection if there's already an active request or if the server is still installing (409), then the request is created and the `eggswitch:reinstall-requested` event is written.

Then:

- **Without a safety backup**: the request is created as `ready_to_wipe` and `EggSwitchExecutionService::performSwitch()` is called within the same HTTP request, so a failure comes straight back in the response (422, 409 or a Wings error); the request is then already `failed` and, if the failure comes from Wings, the egg is already changed.
- **With a safety backup**: the request is created as `waiting_backup`. Backup slots are rechecked after the request is created. If there are none, the response is 422 and the request goes to `failed`; `eggswitch:reinstall-requested` is then the only event in the log. The backup is started under the name "Before game switch (eggswitch)" (the request goes to `failed` if it can't start), the `eggswitch:backup-started` event is written, then `WaitForBackupThenSwitchEggJob` is queued with a 15 s delay.

### The waiting job

`WaitForBackupThenSwitchEggJob` checks the backup's state and re-queues itself every 30 s until it's finished (20 attempts, about 10 minutes). Then:

- backup failed: request goes to `failed`, `eggswitch:backup-failed` event;
- backup succeeded: request goes to `ready_to_wipe`, then `performSwitch()`;
- attempts exhausted: a request still in `waiting_backup` or `ready_to_wipe` goes to `failed`, with no activity event.

Every transition made by the job is conditional on the expected status (`WHERE status = 'waiting_backup'`, or `'ready_to_wipe'` for the final fallback): a cancellation that arrived in the meantime is never overwritten. If a previous run stopped after the move to `ready_to_wipe`, the next one resumes with `performSwitch()`. If the user deletes the safety backup while the job waits, it keeps waiting until it times out, and the request then goes to `failed`.

### Execution

`EggSwitchExecutionService::performSwitch()`:

1. In a transaction, locks the request, does nothing if it was cancelled, moves it to `wiping`, then locks the server.
2. Rechecks the server's state and that its owner is still the request's requester.
3. Changes the egg, the Docker image and the startup command (admin level; the "skip install script" option is always set back to disabled), then, if there are variables, writes them (user level), both inside one nested transaction. `eggswitch:egg-changed` event.
4. Outside the transaction, asks Wings to reinstall the server. `eggswitch:reinstall` event, request goes to `done`.

If step 2 or 3 fails: request goes to `failed`, `eggswitch:egg-change-failed` event, nothing is modified on the server. If step 4 fails: request goes to `failed`, `eggswitch:reinstall-failed` event; the egg stays changed and a manual reinstall from the panel remains possible. The full error goes to the panel's log; `failed_reason` only keeps a message meant for the user.

`done` means "reinstall launched by Wings", not "install finished". That's why a new request is rejected while the server is still installing (step 2, rechecked under the server lock in step 8).

### Statuses

```
without backup  : ready_to_wipe ──► wiping ──► done
                        │              └─────► failed
                        └─► cancelled

with backup     : waiting_backup ──► ready_to_wipe ──► wiping ──► done
                        │                  │              └─────► failed
                        ├─► failed         └─► cancelled / failed
                        └─► cancelled
```

Cancellation is only accepted in `waiting_backup` or `ready_to_wipe` (409 afterwards; 404 if there's no active request). It doesn't delete the backup that was already started, which keeps using a slot until the user deletes it.

## Environment variables

- Only the target egg's variables flagged as **user-editable and user-viewable** are kept; everything else in the payload is ignored.
- Every kept variable missing from the payload gets its default value. A caller therefore only has to supply the variables it cares about.
- Values are validated at request time by the panel's validator (`VariableValidatorService`, user level), then written at execution by `StartupModificationService` at user level, which filters again based on the variables' state at that moment.
- `GET /eggs` only exposes those same variables, with their default value and rules.

## Docker image

With no image requested, it's the first image declared by the target egg, or the egg's legacy `docker_image` column if it declares none (422 if both are empty). A requested image must be one of the images declared by the egg (422 otherwise). Only `requestReinstallForAddon()` can request one.

## Calling from another extension

Blueprint extensions run in the same Laravel application. Another extension resolves `EggSwitchRequestService` from the container and calls:

```php
requestReinstallForAddon(
    User $user,               // the user actually authenticated for the current request
    Server $server,
    int $eggId,               // must be in the "For other addons" list
    string $confirmServerName,
    bool $withBackup,
    array $environment,
    ?string $dockerImage = null,
): EggSwitchPendingReinstall
```

No HTTP route leads to it. The checks are exactly those of the flow above; only the whitelist being checked changes. To know whether Egg Switch is usable, an extension can check its presence and version through Blueprint, then call `EggWhitelist::isAllowedForAddon($eggId)`. The returned model's `id` and `requested_at`, the `status` column of `eggswitch_pending_reinstalls`, the `EggSwitchPendingReinstall::STATUS_*` constants, and the names of the public classes and methods cited here are part of this contract.

## Activity log

Events written on the server, in the requester's name: `eggswitch:reinstall-requested`, `eggswitch:backup-started`, `eggswitch:backup-failed`, `eggswitch:egg-changed`, `eggswitch:egg-change-failed`, `eggswitch:reinstall`, `eggswitch:reinstall-failed`, `eggswitch:cancelled`.

The panel sorts this log by timestamp to the second, with no tiebreaker: events written within the same second may be displayed out of order. The extension therefore waits one second before writing an event that closely follows the previous one, which adds two to three seconds to a request.

## Admin screen

`/admin/extensions/eggswitch`, reserved for the panel's admins (checked by the panel and rechecked by every action). Entries (keyed by `egg_id`) can be added, edited and deleted; the table is sortable and filterable. The egg is picked from a dropdown grouped by nest, which pre-fills the label and description.

## Theme hooks

Every element of the tab and the banner carries a stable `eggswitch-*` class (`eggswitch-page`, `eggswitch-nest-card`, `eggswitch-egg-card`, `eggswitch-console-banner`…), so a theme can target them without depending on Tailwind classes.

## Manual test checklist

On a disposable server, with an owner account and a second account that isn't the owner:

- The second account can neither read (`/eggs`, `/status`) nor act (`/reinstall`, `/cancel`) on the server, even with its UUID, nor through `requestReinstallForAddon()`.
- An egg outside the whitelist is rejected, even if it exists on the panel. An egg that's only self-service is rejected by `requestReinstallForAddon()`, and the other way round.
- A wrong confirmation name is rejected.
- A non-editable or hidden variable sent in `environment` is never written; a value that doesn't respect its variable's rule is rejected at request time.
- With a safety backup: the request waits for the backup to finish, then the egg changes and the server reinstalls.
- With a safety backup but no free slot: rejected, and the request ends up `failed`, never stuck.
- Cancellation during `waiting_backup`: accepted; the backup finishes without the egg changing.
- Cancellation during `wiping`: 409.
- Two close requests on the same server: only one goes through, the other gets 409, including when the first one has just reached `done` but the install is still running.
- Suspended server: 409.
- Docker image outside the egg passed to `requestReinstallForAddon()`: rejected; with no image: the egg's default image.
- Admin screen: add, edit, delete, sort and filters.

The write routes are limited to 10 requests per hour per user, rejected ones included: use several accounts, or clear the rate limiter cache, between runs of this checklist.

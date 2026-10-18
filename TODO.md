# TODO / Known limitations

Things that are missing, incomplete, or worth revisiting. Not a promise of when (or whether) any of these will happen — just a place to keep track so nothing gets forgotten. Pull requests are welcome on any of these points.

## No automated test suite

There's no PHPUnit suite. Every feature has been verified by hand, and nothing re-runs automatically after a change. See the checklist in [`DEVELOPMENT.md`](DEVELOPMENT.md).

## CI only checks PHP syntax

The GitHub Actions workflow (`.github/workflows/check.yml`) only runs `php -l`. Worth adding:

- **TypeScript type-check**: the components import panel modules (`@/state/server`, `@/api/http`…), so they can't compile on their own. The job would fetch the panel at a pinned version, install its dependencies, copy `components/sections` into it and run `tsc` with its `tsconfig.json`.
- **YAML validation** of `conf.yml` and `components/Components.yml`.

## Events show up under their raw key

The Activity tab shows `eggswitch.reinstall` instead of a sentence: there's no `eggswitch` entry in `resources/lang/en/activity.php`. Blueprint has no way to add translations to that file durably, and a manual edit would be overwritten by the next panel update.

## Activity log order

The panel sorts the log by timestamp, to the second, with no tiebreaker. The extension spaces its events one second apart to keep them in order, which costs two to three seconds per request. The real fix (also sorting by `id` in the panel's two `ActivityLogController`s) is a panel patch, which an extension can't make durable.

## A failed request isn't shown to the user

`GET /status` only returns the active request. When a request fails in the background (backup failed, timeout, Wings unreachable), the "Switch game" tab simply goes back to its initial state: the reason (`failed_reason`) isn't visible anywhere to the user, and the Activity tab shows at best a failure event (none on a timeout or an impossible backup). Even a successful switch without a backup shows no message: the request moves to `done`, which isn't an active state, and the form reappears unchanged. Doing this properly means deciding how long a failure stays displayed, and how it goes away.

## No "restore" button after a switch

To go back after a switch, the user has to restore the safety backup themselves from the Backups tab, and can only get the old egg back through another switch (if it's offered) or through an admin. A button that puts the old egg back and restores the backup would be simpler.

## A request can get stuck in an active state

If the process stops abruptly, the request stays in its active state: in `wiping` (process killed between entering `wiping` and finishing), in `ready_to_wipe` without a backup (no job picks it up), or in `waiting_backup` (a stop, or an unavailable queue, between the request's creation and the job being queued). In all these cases, any new request on that server gets a 409 until the database is fixed. There's no maximum duration or scheduled task that marks these requests as `failed`.

## The egg whitelist isn't rechecked at execution time

The target egg is checked against the whitelist when the request is made. If an admin removes it from the whitelist while a backup is pending, the switch still happens once the backup finishes.

## 403 instead of 404 on someone else's server

A request on another user's server gets a 403. The panel's client API answers 404 in that case, so as not to confirm that a server exists. UUIDs are impossible to guess, but staying consistent with the panel would be cleaner.

## The panel's mandatory 2FA isn't enforced

When the panel requires two-factor authentication, its routes refuse an account that hasn't set it up. The extension's routes don't: the panel's middleware answers JSON requests in JSON but redirects the others, including the tab's `GET /eggs` and `/status`, which would break them. An equivalent that answers in JSON is needed.

## Restricting eggs by node

Every whitelisted egg is offered on every server. An egg that only works on some nodes (ARM architecture, resources, image availability) should be limitable to those nodes.

## Subusers

Only the owner can change the egg. Subusers see the tab but get an error, and a panel admin who doesn't own the server is refused the same way. Opening the action to a subuser would need a dedicated permission, checked in `EggSwitchScopeService::assertOwnsServer()`, and a tab hidden from those who don't have it.

## The "no game available" alert has no colour

When the whitelist has no self-service egg, the tab shows an alert of type `info`. The panel's `Alert` component only supports `warning` and `danger`, so the alert renders without a background or border colour (and TypeScript flags the type). Showing it properly means either using `warning`, or a small styled box of our own.

## To decide: switching to the egg already in use

The server's current egg stays in the list (with a "Current game" badge), and nothing prevents "switching" to it. The result is a full wipe and reinstall of the same game, which can be a feature (start over) or a trap (the user thinks nothing happens). To decide: keep it, label it clearly (for example "Reinstall"), or hide the current egg.

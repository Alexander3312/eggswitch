# Architecture — Egg Switch (`eggswitch`)

Why the extension is built the way it is: the decisions that needed making, and the reasons behind them. The current implementation details live in `DEVELOPMENT.md`, known limitations in `TODO.md`. This document describes the code as it actually is, not a plan to follow.

---

## 1. Goal

Let a server's owner, without being an admin, reinstall their server with a different egg, chosen from those an admin has picked, with an optional safety backup beforehand. Changing a server's egg is normally reserved for the panel's admins: the whole difficulty is opening this action to a user on their own servers, and only there.

The extension stays generic: it knows no game, no mod loader (Forge, Fabric…), no particular egg. It handles a target egg, the variables it declares, and the panel's native services. Creating or adapting an egg remains an admin task, done with Pterodactyl's own tools; making an egg available only takes adding it to a list in the admin screen.

---

## 2. Security model

### Two guard levels, never mixed up

**Level 1, every route**: `auth`, `auth.session`, then `EnsureEggSwitchAllowed`, which rejects a user who isn't logged in or who owns no server. This level says **nothing** about the right to act on a specific server: it only rejects irrelevant requests early. The `blueprint` middleware group of extension routes includes no authentication, hence the explicit `auth`; `auth.session` makes sure a session invalidated by the panel (after a password change, for example) is invalidated here too.

**Level 2, every action that touches a server, reads included**: `EggSwitchScopeService::assertOwnsServer()`, on the server reloaded from the database, never on an id from the payload. It's the only check that actually authorizes anything. It is never assumed that a middleware has already done it.

The extension reserves the egg change for the **owner**, without going through the panel's subuser permissions: changing the egg wipes the whole server, an action heavier than anything a subuser can do natively. Opening this action to subusers someday would be done in `assertOwnsServer()` only, with a dedicated permission.

### A single entry point, no shortcut

Every request, whether it comes from the HTTP route or from another extension, goes through `EggSwitchRequestService`. The two public methods (`requestReinstall()` and `requestReinstallForAddon()`) differ only in the whitelist being checked; everything else is one private method. Another extension gets no exception: same ownership check with the user actually authenticated, same variable filter, same state checks. A bug in a calling extension must never become a way around these rules, hence the centralization rather than duplication.

### What the user must never be able to do

- Act on a server that isn't theirs, even with a valid UUID.
- Pick an egg outside the whitelist, even if it exists on the panel.
- Write a variable the admin made non-editable or hidden.
- Pick a Docker image the egg doesn't declare.
- Start the reinstall before a requested safety backup has finished, or request a safety backup with no free slot.
- Cancel once the reinstall has started.
- Change the egg of a suspended server, or one with an install, restore or transfer in progress, or whose first install has failed.

Each of these points is rechecked server-side, never just greyed out in the interface. The confirmation by server name is rechecked too: a field filled in the interface doesn't protect against a direct call.

---

## 3. Privilege elevation limited to the strict minimum

`StartupModificationService` only changes a server's egg if it's called at the `USER_LEVEL_ADMIN` level. But that same level disables `VariableValidatorService`'s variable filter: in a single elevated call, a user could write any variable of the target egg, including those an admin has locked.

Execution therefore makes **two calls**:

1. Admin level, with only the egg, the Docker image and the startup command. No variable goes through this call. The panel also sets the "skip install script" option back to disabled there.
2. User level, with only the variables. The panel's own filter is what applies, on the variables' state at execution time: if an admin locks a variable while a backup is pending, the current state wins.

The startup command is passed explicitly: otherwise the panel keeps the old egg's, and the server would start with a command that no longer matches its game.

Both calls are grouped in one nested transaction. Without it, a validation failure on the variables would only roll back the second call: the egg would be changed without a reinstall, and the server would run the new egg on the old one's files.

On top of the panel's filter, the request itself only keeps the user-editable and user-viewable variables, and validates their values at request time. An invalid value is thus rejected right away, and not after a backup of several minutes.

---

## 4. Centralized defaults and Docker image

The panel's validator doesn't fill in missing variables with their default value: it relies on the interface to pre-fill every field. Making each caller (the tab, every other extension) responsible for that would be fragile; the request therefore completes every missing variable itself. A caller only supplies what it cares about.

Without an explicit Docker image, the server would keep the old egg's image, potentially incompatible with the new one (wrong Java version, for example). The image is therefore always resolved at request time: the first image declared by the target egg, or a requested image that must be one of those it declares. Only the path used by other extensions can request one; the tab always takes the default.

---

## 5. Reusing native services

The extension reimplements nothing the panel already knows how to do: `StartupModificationService` for the egg, image, command and variables; `ReinstallServerService` for the reinstall; `InitiateBackupService` for the backup; `VariableValidatorService` for the variables' rules; `Server::validateCurrentState()` for the server's state. A security rule that changes in the panel is thus followed automatically instead of silently diverging.

### Server state

Extension routes don't go through the client API middleware that rejects a suspended or busy server, and `canBeReinstalled()` only looks at the "skip install script" option (with an exception for servers whose install failed). `validateCurrentState()` is therefore called at request time, then again right before the egg change: with a safety backup, several minutes can separate the two. For the same reason, execution rechecks that the server still belongs to the request's requester.

### The node

Before any write, the request checks that the node responds (the same lightweight call the node's admin screen uses to display its state). Without that, the egg would be changed and then the reinstall would fail. This is only a point-in-time check: a failure of the call to Wings is still handled at execution.

---

## 6. Full wipe, delegated to the egg's script

An egg change starts from scratch: every file on the server is deleted, with no exception (no preserved `world/` folder). Keeping files from another game or another loader leads to servers that won't start.

The extension has no access to the server's disk, only to Wings' API, and `ReinstallServerService` deletes no file: it reruns the install script on the same volume. The wipe is therefore done by the target egg's install script, as its very first step, in the install container, which already has the right permissions on the server's files. This is a condition for offering an egg, documented in the README. `find /mnt/server -mindepth 1 -delete` rather than an `rm -rf` with globs: hidden files are handled without a fragile pattern, and the mount point itself is never removed.

---

## 7. Asynchronous backup, in a job

A backup is never synchronous: the panel asks Wings to start it, and its completion arrives later through a callback from Wings. Backup and reinstall therefore can't be chained within the same HTTP request.

The choice is a queued job (`WaitForBackupThenSwitchEggJob`) that polls the backup at regular intervals, rather than a wait in the browser: the reinstall must not depend on a tab left open. The job gives up after about ten minutes rather than waiting indefinitely.

Free backup slots are computed with the same rule as the panel uses to display the tab, then rechecked server-side when the request is made.

---

## 8. One active request per server, and states that never stay stuck

The `eggswitch_pending_reinstalls` table holds a request's current state; the history lives in the panel's activity log, not in a dedicated audit table.

**Only one active request per server.** MySQL has no portable partial unique index; the rule is therefore enforced by the code. The request locks the **server's** row, which always exists, before looking for an active request: locking an active request that doesn't exist yet would block nothing (or end in a deadlock depending on the isolation level). Execution locks the request, then the server. Cancellation locks only the request. Creation locks the server first but reads active requests without a lock, so no code path takes the two locks in the opposite order (which could deadlock).

**`done` doesn't mean "installed".** Wings doesn't signal the end of the script synchronously: `done` is set as soon as the reinstall is launched. A new request could then go through while the install is still running. The request therefore also checks the server's real state (install in progress), not just its own table.

**Active states must not stay stuck**, otherwise the server would be blocked on a 409. Every anticipated failure is handled, except a hard kill of the process (see `TODO.md`):

- a failure during the egg change marks the request `failed` inside the transaction (so that this status is recorded instead of being rolled back with the rest), then the error is rethrown;
- a failure of the call to Wings marks the request `failed`; the egg stays changed, and a manual reinstall remains possible;
- a backup that can't be started, or with no free slot, marks the request `failed`;
- when the job has exhausted its attempts, it marks a request still in `waiting_backup` or `ready_to_wipe` as `failed`, and a run of the job resumes a request left in `ready_to_wipe`.

**A cancellation is never overwritten.** The job reads the request without a lock; its transitions are therefore conditional (`WHERE status = 'waiting_backup'`). Without that, a cancellation confirmed to the user could be overwritten, and the server wiped anyway. Cancellation is only accepted before `wiping`: the wipe is irreversible, and the interface must leave no ambiguity about that.

**Failure messages are fixed.** `failed_reason` may be shown to the owner: an exception's raw message can contain SQL or internal details. Only messages written for the user (validation, explicit rejections) go through; the rest goes to the panel's log.

---

## 9. Two independent whitelists

`eggswitch_allowed_eggs` holds two independent lists:

- **Self-service**: the eggs offered in the tab, where the user fills in the variables themselves;
- **For other addons**: eggs that only make sense when driven by another extension that supplies reliable values itself (a modpack egg whose variables are project and version identifiers, impractical to type by hand).

`requestReinstallForAddon()`, which checks the second list, **is exposed by no HTTP route**. An egg that is only in this list is therefore unreachable from the tab, not just hidden.

The list is in the database, edited through an admin screen, rather than in a file: changing it requires neither a redeploy nor writing a PHP file from a web request, which would be a real code-execution risk. It's created empty, because egg IDs differ from one panel to another: pre-filled IDs would silently target the wrong eggs elsewhere. The admin screen has admins pick the egg from a dropdown rather than type an ID, for the same reason. No foreign key to eggs: deleting an egg from the panel isn't blocked by the extension, and its entry stays visible so it can be cleaned up.

---

## 10. Rate limits per route group

Read routes are limited to 60 requests per minute, routes that act to 10 per 60 minutes, per user; every attempt counts, rejected ones included. Laravel's throttle middleware builds its key from the user alone, without the route: without a prefix, the two limits would share one counter, and viewing the tab would consume the actions' quota. Each group therefore has its own prefix (`eggswitch-read`, `eggswitch-write`).

---

## 11. Native activity log, and its display order

History goes through the panel's activity log (`Activity::event('eggswitch:…')`), visible in the server's Activity tab, rather than through a dedicated audit table. Events written from the job have no HTTP request: the actor is always set explicitly there (the request's requester), otherwise they would have no actor (displayed as anonymous).

Two limitations come from the panel and can't be durably fixed by a Blueprint extension, which can only touch the files that Blueprint provides for extensions:

- events are displayed with their raw key, for lack of an entry in `resources/lang/en/activity.php`;
- the log is sorted by timestamp to the second, with no tiebreaker: events from the same second may be displayed out of order.

A patch of those files would be overwritten by the next panel update, without warning. The extension therefore waits one second before writing an event that closely follows the previous one: this fixes the order for its own events, at the cost of two to three seconds per request.

---

## 12. Interface

The tab and the console banner go through Blueprint's injection points (`Components.yml`: a server route and `Server.Terminal.BeforeContent`), without patching any panel file. The banner must never break the console: a loading error is silently ignored.

Eggs are grouped by normalized nest name (case and whitespace), server-side: two nests with the same name give a single category, and the interface doesn't need nest ids.

`GET /eggs` reports whether the node responds and how many backup slots remain, to grey out the button or checkbox before the click. These values are for display only: everything is rechecked when the request is made.

---

## 13. Detection by another extension

Another extension can't rely on a route to know whether Egg Switch is there, since the entry point reserved for extensions is exposed by no route. It checks the extension's presence and version through Blueprint, then `EggWhitelist::isAllowedForAddon()` for the egg it wants to use. This check queries the database on every call: an egg removed from the list in the meantime is rejected, at the latest by `requestReinstallForAddon()` itself.

---

## 14. No automated tests

The panel has no test suite usable by an extension. Each security behavior was verified by hand, on a dedicated panel and server; the checklist to replay is in `DEVELOPMENT.md`.

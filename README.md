# Egg Switch for Pterodactyl

**Egg Switch** is a [Blueprint](https://blueprint.zip/) extension that lets a server's owner reinstall it with a different egg (for example, going from a Vanilla server to a Forge one), from a list of eggs chosen by the admin, with an optional safety backup before the reinstall.

## Features

- **"Switch game" tab** on every server: the user picks a category (nest), then an egg, adjusts its variables if the egg exposes any, and confirms by typing the server's name.
- **Optional safety backup** before the reinstall, if the server has a free backup slot. The egg only changes once the backup has finished successfully.
- **Cancel** while the safety backup runs, or just before the egg change starts.
- **Banner on the server's console** while a game switch is pending or in progress.
- **Admin screen** to choose which eggs are offered, without touching the code or redeploying.
- **History** in the panel's native Activity tab (request, backup, egg change, reinstall, cancellation, and most failures).
- **Entry point for other addons**: [Addon Browser](https://github.com/Alexander3312/addonbrowser) uses it to install a Minecraft modpack in one click.

## Requirements

- A Pterodactyl panel with Blueprint (built against `beta-2026-08`).
- The panel's `pteroq` service must be running (it runs background tasks): waiting for the backup before the game switch starts happens in the background.
- For the safety backup option: a backup limit above zero on the servers concerned.
- Eggs whose install script **empties the server** before installing (see [Preparing eggs](#preparing-eggs)).

## Installation

1. Download the latest `.blueprint` from the releases, or build one from this repository.
2. Upload it to your Pterodactyl root.
3. Run `blueprint -i eggswitch.blueprint`. On Docker installs, Blueprint does not run migrations: run `php artisan migrate --force` yourself.
4. Open the extension's admin screen and add the eggs to offer (see [Configuration](#configuration)). The list is empty after installation.

## Updating

Install the new `.blueprint` the same way. The allowed eggs and any requests in progress are kept. Blueprint's install step already runs `php artisan queue:restart`, so the `pteroq` worker reloads the new code on its own.

## Uninstalling

`blueprint -remove eggswitch`. The extension's two tables are kept (Blueprint doesn't delete an extension's tables on removal): nothing is lost if you reinstall the extension later.

## Configuration

Everything is set in the extension's admin screen (panel admins only). For each egg, you choose a display name, a description, and which list(s) it appears in:

- **Self-service (server page)**: the egg appears in users' "Switch game" tab.
- **For other addons**: the egg can only be targeted by another addon that calls Egg Switch (for example Addon Browser's modpack eggs), never from the tab. Useful for an egg whose variables must be filled in by a program, not by hand (a project ID, a version ID…).

The two lists are independent: an egg can be in one, the other, both, or neither.

Egg IDs differ from one panel to another: always pick the egg from the admin screen's dropdown, and don't copy an ID noted elsewhere.

### Preparing eggs

Egg Switch changes the server's egg, then asks Wings to rerun the new egg's install script. **Neither Egg Switch nor Pterodactyl's native reinstall deletes any files**: without a precaution, the old game's files stay on the server, mixed with the new ones.

Every egg you offer must therefore wipe the server at the very start of its install script, right after the first line (`#!/bin/bash` or `#!/bin/ash`):

```bash
find /mnt/server -mindepth 1 -delete
```

The eggs shipped with Pterodactyl don't do this: add this line to each egg before offering it. It deletes every file on the server each time that egg is installed or reinstalled, including a reinstall started from the panel.

## Addon Browser integration

[Addon Browser](https://github.com/Alexander3312/addonbrowser) uses Egg Switch (1.1.0 or later) to install a full Minecraft modpack: it changes the server to a modpack egg and reinstalls it. Those eggs go in the "For other addons" list. How to set them up is detailed in Addon Browser's README.

## Security at a glance

- Only the server's **owner** can change its egg. Ownership is rechecked on every request, even just to display the tab.
- Only eggs in the matching list are accepted, even if the requested egg exists on the panel.
- Only the variables the egg declares as editable and visible to the user are taken into account, and their values are validated before anything happens.
- The switch is refused on a suspended server, a node in maintenance, or during an install, a restore or a transfer, as well as on a server whose first install failed or that is set to skip install scripts.
- Only one active request per server at a time.
- Other addons go through exactly the same checks, without exception.

## Limitations

- Only the owner can change the egg: subusers see the tab but get an error, even with every permission. A panel admin who doesn't own the server is refused the same way.
- The Activity tab shows the extension's events under their raw key (`eggswitch.reinstall` instead of a readable sentence), because there is no translation.
- A switch that fails in the background (backup failed, timeout, Wings unreachable) isn't reported in the "Switch game" tab: the Activity tab shows at best a failure event, without the reason, and nothing at all on a timeout or an impossible backup.

The full list is in [`TODO.md`](TODO.md).

## Development

There are no automated tests yet. See [`DEVELOPMENT.md`](DEVELOPMENT.md) for how it works in detail and how to verify a change, [`architecture-eggswitch-addon.md`](architecture-eggswitch-addon.md) for the reasoning behind the non-obvious decisions, and [`TODO.md`](TODO.md) for known gaps. Most of the code was written with heavy AI assistance (Claude).

## Contributing

Issues and pull requests are welcome. Read `DEVELOPMENT.md` and `architecture-eggswitch-addon.md` before touching the security checks or the request flow: they explain rules you can't guess from reading the code.

## License

Copyright (C) 2026 Alexander3312. Licensed under the GNU GPL, version 3 or (at your option) any later version — see [`LICENSE`](LICENSE).

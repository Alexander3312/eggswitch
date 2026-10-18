<?php

namespace Pterodactyl\BlueprintFramework\Extensions\eggswitch\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;

/**
 * The heart of this addon's security. A single method, called at the very
 * start of EVERY action that touches a specific server — read actions
 * included — never only mutations (directly in the controller, or in
 * EggSwitchRequestService for reinstall/cancel).
 *
 * Non-negotiable rule: the check is always done on the resource as reloaded
 * from the database (Server already loaded, not a raw id from the payload),
 * and the EnsureEggSwitchAllowed middleware never authorizes anything on its
 * own — it only rejects, early on, users who are not logged in or have no server.
 */
class EggSwitchScopeService
{
    /**
     * @throws AuthorizationException
     */
    public function assertOwnsServer(User $user, Server $server): void
    {
        // TODO: to one day open access to subusers (with a dedicated right for
        // the egg switch), this is where, and only where, the condition would
        // have to be widened — never in the controller directly.
        if ((int) $server->owner_id !== (int) $user->id) {
            throw new AuthorizationException('You do not own this server.');
        }
    }
}

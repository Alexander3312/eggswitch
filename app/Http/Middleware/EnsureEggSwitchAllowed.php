<?php

namespace Pterodactyl\BlueprintFramework\Extensions\eggswitch\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpFoundation\Response;

/**
 * Level 1 of 2 of the security model. Answers ONLY "is this user logged in
 * and does this user own at least one server?". Authorizes absolutely
 * nothing on a specific server — that is the exclusive role of
 * EggSwitchScopeService::assertOwnsServer(), called for EVERY action that
 * touches a server, reads included.
 *
 * Never remove the assertOwnsServer() call in a controller on the grounds
 * that this middleware "already checked" something about the targeted
 * server: it did not, and it must never be assumed to.
 */
class EnsureEggSwitchAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(401);
        }

        if (!Server::query()->where('owner_id', $user->id)->exists()) {
            abort(403, "You do not own any servers.");
        }

        return $next($request);
    }
}

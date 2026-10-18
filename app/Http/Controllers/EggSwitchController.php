<?php

namespace Pterodactyl\BlueprintFramework\Extensions\eggswitch\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\BlueprintFramework\Extensions\eggswitch\Models\EggSwitchPendingReinstall;
use Pterodactyl\BlueprintFramework\Extensions\eggswitch\Services\EggSwitchRequestService;
use Pterodactyl\BlueprintFramework\Extensions\eggswitch\Services\EggSwitchScopeService;
use Pterodactyl\BlueprintFramework\Extensions\eggswitch\Support\EggWhitelist;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Eloquent\BackupRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thin HTTP layer on top of EggSwitchRequestService: validates the shape of
 * the request, resolves the targeted server, then delegates all the
 * business/security logic to the service. See EggSwitchRequestService for the
 * reason behind this split (the service is also the entry point for direct
 * calls from another addon).
 */
class EggSwitchController extends Controller
{
    public function __construct(
        private EggSwitchScopeService $scope,
        private EggSwitchRequestService $requests,
        private BackupRepository $backupRepository,
    ) {
    }

    /**
     * GET /extensions/eggswitch/eggs — list of eggs eligible for self-service,
     * enriched with the variables declared by each egg (to build the form on
     * the client side if the target egg exposes editable ones).
     */
    public function eggs(Request $request): JsonResponse
    {
        $server = $this->resolveOwnedServer($request, 'server');

        $allowed = EggWhitelist::all();

        $eggs = Egg::query()
            ->whereIn('id', array_keys($allowed))
            ->with(['variables', 'nest'])
            ->get()
            ->map(function (Egg $egg) use ($allowed) {
                return [
                    'id' => $egg->id,
                    'label' => $allowed[$egg->id]['label'] ?? $egg->name,
                    'description' => $allowed[$egg->id]['description'] ?? $egg->description,
                    'nest_name' => $egg->nest->name,
                    // Editable AND viewable, like the core client API: the default
                    // value of a hidden variable may be a secret.
                    'variables' => $egg->variables
                        ->where('user_editable', true)
                        ->where('user_viewable', true)
                        ->map(fn ($v) => [
                            'name' => $v->name,
                            'env_variable' => $v->env_variable,
                            'default_value' => $v->default_value,
                            'rules' => $v->rules,
                        ])
                        ->values(),
                ];
            });

        // Grouping by nest done here, not on the client side: the front end only
        // needs the category name and the eggs it contains, never the nest_id —
        // this avoids exposing an implementation detail of no use to it.
        // Grouped by normalized name (case/whitespace insensitive) rather than
        // by nest_id: if two distinct nests have the same name (for example a
        // nest recreated by an admin under an already used name), the user
        // must not see two identical categories side by side — an admin
        // managing nests should not have to care about it for the display to
        // stay clean.
        $nests = $eggs
            ->groupBy(fn (array $egg) => mb_strtolower(trim($egg['nest_name'])))
            ->map(function ($eggsInNest) {
                return [
                    'name' => $eggsInNest->first()['nest_name'],
                    'eggs' => $eggsInNest->map(fn (array $egg) => array_diff_key($egg, ['nest_name' => null]))->values(),
                ];
            })
            ->values();

        return new JsonResponse([
            'data' => $nests,
            'current_egg_id' => $server->egg_id,
            // Lets the front end grey out the "safety backup" checkbox
            // without an extra call — rechecked anyway on the back end at the
            // time of POST /reinstall, never trusted as is.
            'server' => [
                'backup_limit' => $server->backup_limit,
                'backup_used' => $this->backupRepository->getNonFailedBackups($server)->count(),
                // Checked on every page load (not cached): the situation may change
                // between display and click, but this at least avoids inviting the
                // user to start a reinstall doomed to fail when the node is visibly
                // unreachable.
                'node_reachable' => $this->requests->isNodeReachable($server),
            ],
        ]);
    }

    /**
     * GET /extensions/eggswitch/status — current state (if any) for the given
     * server. Polled by the console banner and by the "Switch game" tab.
     */
    public function status(Request $request): JsonResponse
    {
        $server = $this->resolveOwnedServer($request, 'server');

        $pending = $this->requests->activePendingFor($server)->first();

        return new JsonResponse(['data' => self::publicFields($pending)]);
    }

    /**
     * POST /extensions/eggswitch/reinstall — the only route that really
     * triggers something destructive (directly, or via the backup job). See
     * EggSwitchRequestService for the logic itself.
     */
    public function reinstall(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'server_uuid' => 'required|string',
            'egg_id' => 'required|integer',
            'environment' => 'sometimes|array',
            // Values are text (variable names are matched against the target egg and
            // anything unknown is ignored); a nested array or a huge value would only
            // fail later, in the background.
            'environment.*' => 'nullable|string|max:4096',
            'with_backup' => 'sometimes|boolean',
            // No docker_image field here, deliberately: this public path does not
            // allow choosing the image, it is always the default image, resolved
            // by EggSwitchRequestService::resolveDockerImage().
            //
            // Application-level confirmation in addition to any checkbox on the
            // front end: never trust a UI-only confirmation for a destructive
            // action.
            'confirm_server_name' => 'required|string',
        ]);

        $server = Server::query()->where('uuid', $validated['server_uuid'])->firstOrFail();

        $pending = $this->requests->requestReinstall(
            $request->user(),
            $server,
            (int) $validated['egg_id'],
            $validated['confirm_server_name'],
            (bool) ($validated['with_backup'] ?? false),
            (array) ($validated['environment'] ?? [])
        );

        return new JsonResponse(['data' => self::publicFields($pending)], 202);
    }

    /**
     * POST /extensions/eggswitch/cancel — only possible as long as the wipe
     * has not started (see EggSwitchPendingReinstall::CANCELLABLE_STATUSES).
     * Does not cancel a backup already started: it stays, harmlessly.
     */
    public function cancel(Request $request): JsonResponse
    {
        $validated = $request->validate(['server_uuid' => 'required|string']);

        $server = Server::query()->where('uuid', $validated['server_uuid'])->firstOrFail();

        $pending = $this->requests->cancel($request->user(), $server);

        return new JsonResponse(['data' => self::publicFields($pending)]);
    }

    /**
     * Fields of a pending row returned to the client: only what the UI
     * displays. The full row also contains the internal id of the user who
     * made the request, that of the backup and the resolved environment, which
     * the client does not need.
     */
    private static function publicFields(?EggSwitchPendingReinstall $pending): ?array
    {
        return $pending?->only(['id', 'status', 'with_backup', 'failed_reason', 'target_egg_id']);
    }

    /**
     * Resolves the server from a UUID parameter of the request (query or
     * payload depending on the HTTP method) and systematically applies
     * security level 2: any method of this controller that does not delegate
     * to EggSwitchRequestService (which does it itself) must go through here,
     * reads included.
     */
    private function resolveOwnedServer(Request $request, string $param): Server
    {
        $uuid = $request->input($param);

        // Must be a non-empty string: a request like ?server[]=x would otherwise
        // reach the query as an array and end in a 500.
        if (!is_string($uuid) || $uuid === '') {
            throw new HttpException(422, "The '{$param}' parameter is missing.");
        }

        $server = Server::query()->where('uuid', $uuid)->firstOrFail();
        $this->scope->assertOwnsServer($request->user(), $server);

        return $server;
    }
}

<?php

namespace Pterodactyl\BlueprintFramework\Extensions\eggswitch\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Pterodactyl\BlueprintFramework\Extensions\eggswitch\Jobs\WaitForBackupThenSwitchEggJob;
use Pterodactyl\BlueprintFramework\Extensions\eggswitch\Models\EggSwitchPendingReinstall;
use Pterodactyl\BlueprintFramework\Extensions\eggswitch\Support\EggWhitelist;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Repositories\Eloquent\BackupRepository;
use Pterodactyl\Repositories\Wings\DaemonConfigurationRepository;
use Pterodactyl\Services\Backups\InitiateBackupService;
use Pterodactyl\Services\Servers\VariableValidatorService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * SINGLE entry point for any egg switch request made through eggswitch,
 * whether it comes from this addon's HTTP controller or from another addon on
 * the same panel (e.g. addonbrowser for one-click modpack installation).
 *
 * Non-negotiable rule: this class makes NO distinction between a normal HTTP
 * call and a direct call from another addon. Every caller MUST go through here
 * rather than calling StartupModificationService/ReinstallServerService
 * directly, and gets absolutely no shortcut: same ownership check
 * (assertOwnsServer, always with the user ACTUALLY authenticated for the
 * current request, never an id supplied by the caller), same whitelist control
 * (EggWhitelist, with a list dedicated to addons — a third-party addon can
 * therefore only target eggs allowed here, it cannot extend the list from the
 * outside), same user_editable/user_viewable filter and same validation on
 * the environment, same "one active request per server" safeguard. A bug or
 * flaw in another addon must never be able to turn into a bypass of these
 * rules — that is the whole point of centralizing here instead of duplicating
 * the logic.
 */
class EggSwitchRequestService
{
    public function __construct(
        private EggSwitchScopeService $scope,
        private EggSwitchExecutionService $execution,
        private InitiateBackupService $initiateBackupService,
        private BackupRepository $backupRepository,
        private DaemonConfigurationRepository $daemonConfigurationRepository,
        private VariableValidatorService $variableValidatorService,
    ) {
    }

    /**
     * Checks that the server's node responds before committing to an egg switch
     * — avoids changing the egg only to find out that Wings is unreachable.
     * Same lightweight call (`/api/system`) as the one used by the native node
     * admin screen (`SystemInformationController`).
     */
    public function isNodeReachable(Server $server): bool
    {
        try {
            $this->daemonConfigurationRepository->setNode($server->node)->getSystemInformation();

            return true;
        } catch (DaemonConnectionException $exception) {
            return false;
        }
    }

    public function activePendingFor(Server $server)
    {
        return EggSwitchPendingReinstall::query()
            ->where('server_id', $server->id)
            ->whereIn('status', EggSwitchPendingReinstall::ACTIVE_STATUSES);
    }

    /**
     * Requests an egg switch for $server, on behalf of $user — PUBLIC path,
     * exposed by the HTTP controller (`POST /reinstall`). Checks the egg
     * against `EggWhitelist::all()`, the classic self-service whitelist.
     *
     * @see handleReinstallRequest() for the non-negotiable rules shared
     *      with requestReinstallForAddon() ($user always actually
     *      authenticated, $eggId never trusted as is, etc.).
     *
     * @throws HttpException if the confirmation name does not match, if the
     *                        egg is not whitelisted, or if no backup slot is
     *                        available while $withBackup is requested.
     * @throws ConflictHttpException if the server cannot be reinstalled in its
     *                                current state, or if a request is
     *                                already active for this server.
     * @throws ServiceUnavailableHttpException if the node is unreachable.
     * @throws \Illuminate\Auth\Access\AuthorizationException if $user does not
     *                                                          own $server.
     */
    public function requestReinstall(
        User $user,
        Server $server,
        int $eggId,
        string $confirmServerName,
        bool $withBackup,
        array $environment
    ): EggSwitchPendingReinstall {
        // No Docker image choice on this path: always the default image
        // resolved by resolveDockerImage() (first image declared by the target
        // egg). Unlike requestReinstallForAddon(), no parameter lets a caller
        // of this path choose another one.
        return $this->handleReinstallRequest(
            $user,
            $server,
            $eggId,
            $confirmServerName,
            $withBackup,
            $environment,
            null,
            EggWhitelist::isAllowed($eggId)
        );
    }

    /**
     * Same as requestReinstall(), for a direct call from ANOTHER addon of the
     * panel (e.g. addonbrowser for modpack installation) — never exposed by an
     * HTTP route, unlike requestReinstall(). Checks the egg against
     * `EggWhitelist::forAddons()`, a list separate from `EggWhitelist::all()`:
     * an egg that is not in it is structurally unreachable through this path,
     * even if it is whitelisted for self-service (and vice versa). See
     * EggWhitelist for the reason behind this separation.
     *
     * @see handleReinstallRequest() for the non-negotiable rules shared
     *      with requestReinstall() ($user always actually authenticated,
     *      $eggId never trusted as is, etc.).
     *
     * @throws HttpException|ConflictHttpException|ServiceUnavailableHttpException|\Illuminate\Auth\Access\AuthorizationException
     *         same cases as requestReinstall().
     */
    public function requestReinstallForAddon(
        User $user,
        Server $server,
        int $eggId,
        string $confirmServerName,
        bool $withBackup,
        array $environment,
        ?string $dockerImage = null
    ): EggSwitchPendingReinstall {
        return $this->handleReinstallRequest(
            $user,
            $server,
            $eggId,
            $confirmServerName,
            $withBackup,
            $environment,
            $dockerImage,
            EggWhitelist::isAllowedForAddon($eggId)
        );
    }

    /**
     * Business logic shared by requestReinstall() and
     * requestReinstallForAddon() — the only difference between the two: which
     * whitelist has already been checked to produce $isEggAllowed. Everything
     * else (ownership, variable filtering, node, backup, anti-double-request
     * safeguard) is strictly identical whatever the entry path.
     *
     * Non-negotiable rules, valid for both callers: $user must ALWAYS be the
     * user actually authenticated for the current action (never rebuilt from
     * an id supplied by the caller, that would strip assertOwnsServer() of all
     * its meaning); $eggId is never taken as is: the calling public method
     * checks it against ITS whitelist and passes the result in $isEggAllowed;
     * the caller (HTTP controller or other addon) remains solely responsible
     * for ITS own confirmation UX — here only security is rechecked, never
     * ergonomics.
     */
    private function handleReinstallRequest(
        User $user,
        Server $server,
        int $eggId,
        string $confirmServerName,
        bool $withBackup,
        array $environment,
        ?string $dockerImage,
        bool $isEggAllowed
    ): EggSwitchPendingReinstall {
        $this->scope->assertOwnsServer($user, $server);

        // Server state: suspended, node in maintenance, installation in
        // progress, restore or transfer → 409, like the panel's client API
        // (AuthenticateServerAccess), which extension routes do not go through.
        // canBeReinstalled() further down only looks at skip_scripts.
        $server->validateCurrentState();

        if (!hash_equals($server->name, $confirmServerName)) {
            throw new HttpException(422, 'The confirmation name does not match the server name.');
        }

        if (!$isEggAllowed) {
            // egg_id never trusted as is: refused even if it is a perfectly
            // valid egg elsewhere in the panel, and even if the caller is
            // another addon of the panel rather than a direct HTTP request.
            throw new HttpException(422, "This game is not available for switching.");
        }

        $egg = Egg::query()->findOrFail($eggId);

        // Resolved here (not in EggSwitchExecutionService) to reject an invalid
        // image BEFORE any mutation, like the rest of the checks in this
        // method — see resolveDockerImage().
        $dockerImage = $this->resolveDockerImage($egg, $dockerImage);

        if (!$server->canBeReinstalled()) {
            throw new ConflictHttpException('This server cannot be reinstalled in its current state.');
        }

        if (!$this->isNodeReachable($server)) {
            // Rejected before any mutation: no pending row created, no egg
            // changed, no backup started — nothing to clean up, unlike a
            // failure discovered along the way.
            throw new ServiceUnavailableHttpException(null, 'This server\'s node is currently unreachable. Try again later.');
        }

        // StartupModificationService is called further down (in
        // EggSwitchExecutionService) with USER_LEVEL_ADMIN to change egg_id —
        // which incidentally disables the user_editable filter of
        // VariableValidatorService::handle(). Without the filter below, a
        // caller (direct HTTP or another addon) could write any variable
        // existing on the target egg, including those an admin deliberately
        // marked as not editable.
        //
        // Every editable variable missing from the payload is also completed
        // with its default_value: the panel core (VariableValidatorService)
        // does NOT do it itself — it relies on the calling front end to
        // pre-fill all fields, defaults included, before submitting (see the
        // "Switch game" tab itself, which does exactly that). Reproducing this
        // responsibility in every caller (the "Switch game" tab, other addons...)
        // would be fragile; it is therefore done once here. A caller only has
        // to provide the variables that really matter to it (e.g. a project or
        // version identifier), never to repeat the default values of an egg it
        // should not have to know about.
        //
        // Editable AND viewable, like the core filter at user level
        // (VariableValidatorService): an editable but hidden variable would be
        // ignored at execution anyway, and its default value (which may be a
        // secret set by an admin) must not end up in
        // `environment`.
        $editableVariables = $egg->variables()
            ->where('user_editable', true)
            ->where('user_viewable', true)
            ->get(['env_variable', 'default_value']);
        $environment = $editableVariables->mapWithKeys(function ($variable) use ($environment) {
            return [
                $variable->env_variable => array_key_exists($variable->env_variable, $environment)
                    ? $environment[$variable->env_variable]
                    : $variable->default_value,
            ];
        })->all();

        // Validates the values against the target egg's variable rules at request
        // time, with the core validator at user level (the same one
        // that applies at execution): an invalid value is refused right away
        // (422), before any mutation, instead of failing after the backup, up
        // to ~10 min later.
        $this->variableValidatorService
            ->setUserLevel(User::USER_LEVEL_USER)
            ->handle($egg->id, $environment);

        $pending = DB::transaction(function () use ($server, $egg, $environment, $dockerImage, $withBackup, $user) {
            // Locks the server row before deciding: prevents two concurrent
            // requests (or an HTTP request + a call from another addon at the
            // same time) from creating two requests in parallel on the same
            // server. The lock is on the server, which always exists, and not
            // on an active row that does not exist yet: a SELECT ... FOR UPDATE
            // with no result locks nothing under READ COMMITTED, and ends in a
            // deadlock (500) rather than a 409 under REPEATABLE READ. The read
            // of active rows that follows needs no lock (every request goes
            // through the server one), and takes none so as not to deadlock
            // with performSwitch(), which locks the pending row and then the
            // server.
            Server::query()->whereKey($server->id)->lockForUpdate()->first();

            $existing = $this->activePendingFor($server)->first();

            if ($existing) {
                throw new ConflictHttpException('A reinstall is already pending or in progress for this server.');
            }

            // STATUS_DONE is set as soon as the HTTP call to Wings to start the
            // reinstall has not thrown — not when the install script has really
            // finished (Wings does not report it synchronously).
            // ACTIVE_STATUSES therefore does not cover the window where Wings is
            // still actually running after a row has already moved to "done".
            // Wings has its own per-server installation lock and does reject a
            // concurrent second attempt ("cannot obtain installation lock") — so no
            // file corruption nor parallel double install — but this
            // Wings-internal rejection does not bubble up here, and without this
            // check the second pending row would still end up marked "done" while
            // in reality nothing happened for it. We therefore also recheck the
            // REAL state of the server (status column, not our own tracking
            // table): if it is still installing following a previous request, we
            // refuse here, before even calling Wings a second time.
            if ($server->fresh()->status === Server::STATUS_INSTALLING) {
                throw new ConflictHttpException('This server is already being installed.');
            }

            $pending = EggSwitchPendingReinstall::query()->create([
                'server_id' => $server->id,
                'target_egg_id' => $egg->id,
                'docker_image' => $dockerImage,
                'environment' => $environment,
                'with_backup' => $withBackup,
                'status' => $withBackup
                    ? EggSwitchPendingReinstall::STATUS_WAITING_BACKUP
                    : EggSwitchPendingReinstall::STATUS_READY_TO_WIPE,
                'requested_by' => $user->id,
                'requested_at' => now(),
            ]);

            Activity::event('eggswitch:reinstall-requested')
                ->subject($server)
                ->property(['target_egg_id' => $egg->id, 'with_backup' => $withBackup])
                ->log();

            return $pending;
        });

        if ($withBackup) {
            // Rechecked here, never trusting a checkbox ticked on the client side
            // or what another addon claims to have already checked.
            $hasSlot = $server->backup_limit > 0
                && $this->backupRepository->getNonFailedBackups($server)->count() < $server->backup_limit;

            if (!$hasSlot) {
                $pending->update([
                    'status' => EggSwitchPendingReinstall::STATUS_FAILED,
                    'failed_reason' => 'No backup slot is available on this server.',
                ]);

                throw new HttpException(422, 'No backup slot is available on this server.');
            }

            // The row is already committed as waiting_backup: if the backup
            // cannot be started (panel backup rate limit, native backup started
            // in the meantime, Wings unreachable), no job will ever be dispatched
            // for it. Mark it FAILED, otherwise it would stay active and block
            // any new request (409).
            try {
                $backup = $this->initiateBackupService->handle($server, 'Before game switch (eggswitch)');
            } catch (\Throwable $exception) {
                $pending->update([
                    'status' => EggSwitchPendingReinstall::STATUS_FAILED,
                    'failed_reason' => 'Unable to start the safety backup.',
                ]);

                throw $exception;
            }

            $pending->update(['backup_id' => $backup->id]);

            // Safety net for the activity log ordering — see the equivalent
            // comment in EggSwitchExecutionService::performSwitch().
            sleep(1);

            Activity::event('eggswitch:backup-started')
                ->subject($server)
                ->property(['backup_id' => $backup->id])
                ->log();

            WaitForBackupThenSwitchEggJob::dispatch($pending->id)->delay(now()->addSeconds(15));
        } else {
            $this->execution->performSwitch($pending->id);
        }

        return $pending->fresh();
    }

    /**
     * Resolves the Docker image to use for the egg switch. Without it, the
     * server would keep the image of the OLD egg after a switch, potentially
     * incompatible with the new one (e.g. wrong Java version).
     *
     * - If $dockerImage is provided, it must be one of the images DECLARED by
     *   the target egg (`$egg->docker_images`, a [label => image] array) —
     *   never passed as is to StartupModificationService without this check,
     *   even if the caller is another addon.
     * - If it is absent (normal case for a caller that does not care, e.g. the
     *   classic "Switch game" tab where the user does not choose an image), we
     *   fall back to the first image declared by the target egg — correct
     *   behavior in the vast majority of cases.
     *
     * @throws HttpException if $dockerImage is provided but matches no image
     *                        declared by the target egg, or if the target egg
     *                        declares no image.
     */
    private function resolveDockerImage(Egg $egg, ?string $dockerImage): string
    {
        $allowedImages = array_values($egg->docker_images ?? []);

        if ($dockerImage !== null) {
            if (!in_array($dockerImage, $allowedImages, true)) {
                throw new HttpException(422, "This Docker image is not offered by the target egg.");
            }

            return $dockerImage;
        }

        $default = Arr::first($allowedImages) ?? $egg->docker_image;

        if (!$default) {
            throw new HttpException(422, "The target egg does not declare any Docker image.");
        }

        return $default;
    }

    /**
     * Cancels a pending reinstall for $server, on behalf of $user.
     *
     * @throws HttpException if no request is active for this server.
     * @throws ConflictHttpException if the wipe has already started.
     * @throws \Illuminate\Auth\Access\AuthorizationException if $user does not
     *                                                          own $server.
     */
    public function cancel(User $user, Server $server): EggSwitchPendingReinstall
    {
        $this->scope->assertOwnsServer($user, $server);

        $pending = DB::transaction(function () use ($server) {
            $pending = $this->activePendingFor($server)->lockForUpdate()->first();

            if (!$pending) {
                throw new HttpException(404, 'No pending request for this server.');
            }

            if (!$pending->isCancellable()) {
                throw new ConflictHttpException('This request can no longer be cancelled because the server files are already being deleted.');
            }

            $pending->update([
                'status' => EggSwitchPendingReinstall::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);

            return $pending;
        });

        Activity::event('eggswitch:cancelled')->subject($server)->log();

        return $pending->fresh();
    }
}

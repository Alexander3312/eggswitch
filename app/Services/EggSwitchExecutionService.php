<?php

namespace Pterodactyl\BlueprintFramework\Extensions\eggswitch\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Pterodactyl\BlueprintFramework\Extensions\eggswitch\Models\EggSwitchPendingReinstall;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Servers\ReinstallServerService;
use Pterodactyl\Services\Servers\StartupModificationService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Actually performs the egg change + the reinstall for a given
 * eggswitch_pending_reinstalls row. Called from two places:
 *  - EggSwitchRequestService, directly, when with_backup=false;
 *  - WaitForBackupThenSwitchEggJob, once the backup is confirmed successful.
 *
 * NEVER called with data coming directly from a request — only with the id of
 * a row already created and validated (whitelisted egg, server ownership
 * already checked when the row was created). This service therefore does not
 * redo these access checks; it only rechecks what may have changed while
 * waiting for the backup: the state of the server and the fact that it still
 * belongs to the author of the request.
 */
class EggSwitchExecutionService
{
    public function __construct(
        private StartupModificationService $startupModificationService,
        private ReinstallServerService $reinstallServerService,
    ) {
    }

    /**
     * @throws \Throwable
     */
    public function performSwitch(int $pendingId): void
    {
        // Safety net for the display order of the panel's native activity log:
        // its `timestamp` column only has one-second precision, and its sort
        // (app/Http/Controllers/Api/Client/Servers/ActivityLogController.php)
        // has no second criterion on `id`. Fixing it would require patching a
        // core file, overwritten on every panel update. This deliberate
        // one-second spacing before each closely following event prevents two
        // eggswitch events from falling in the same second.
        self::staggerActivityLog();

        // Exception captured if the egg/environment change fails inside the
        // transaction below — the inner try/catch comment explains why it is
        // not rethrown from the closure.
        $failure = null;

        DB::transaction(function () use ($pendingId, &$failure) {
            /** @var EggSwitchPendingReinstall $pending */
            $pending = EggSwitchPendingReinstall::query()->lockForUpdate()->findOrFail($pendingId);

            // Cancelled between the decision to proceed (e.g. backup finished) and
            // the actual execution: do nothing. This is not an error, just a
            // normal race.
            if ($pending->status === EggSwitchPendingReinstall::STATUS_CANCELLED) {
                return;
            }

            if ($pending->status !== EggSwitchPendingReinstall::STATUS_READY_TO_WIPE) {
                throw new ConflictHttpException("The request has an unexpected status for execution: {$pending->status}.");
            }

            $pending->update(['status' => EggSwitchPendingReinstall::STATUS_WIPING]);

            $server = $pending->server()->lockForUpdate()->firstOrFail();

            try {
                // Rechecked here: with a backup, up to ~10 min separate the
                // request from the execution (the server may have been suspended
                // in the meantime). An exception marks the row FAILED, see the
                // catch below.
                $server->validateCurrentState();

                // Same reason: the server may have changed owner while waiting for
                // the backup. Never reinstall one user's server on another user's
                // request.
                if ((int) $server->owner_id !== (int) $pending->requested_by) {
                    throw new ConflictHttpException('The server has changed owner since the request.');
                }

                // ADMIN elevation reduced to the strict minimum: ONLY the change of
                // egg, Docker image and startup command (the only things that
                // justify it, see StartupModificationService::updateAdministrativeSettings()).
                // No "environment" key here, so no variable write goes through
                // this elevated call. docker_image has already been resolved/validated
                // against the images declared by the target egg in
                // EggSwitchRequestService::resolveDockerImage() before the creation
                // of this row — never recomputed here.
                //
                // 'startup' key: without it, updateAdministrativeSettings() does
                // `$data['startup'] ?? $server->startup` and KEEPS the old startup
                // command — the previous egg's. It goes unnoticed between eggs whose
                // command is identical (e.g. several Minecraft eggs that all run
                // {{SERVER_JARFILE}}), but breaks as soon as the new command really
                // differs: the server would still start with the old egg's command.
                //
                // Both calls are grouped in a nested transaction (savepoint): each
                // StartupModificationService::handle() call opens its own savepoint inside
                // the outer transaction. Without this grouping, a failure
                // when writing the variables (e.g. a value that does not respect
                // the validation rule) would only roll back the second call; since
                // the catch then lets the outer transaction finish, the egg change
                // would be committed WITHOUT a reinstall — the server would
                // run with the new egg on its old files.
                $server = DB::transaction(function () use ($server, $pending) {
                    $server = $this->startupModificationService
                        ->setUserLevel(User::USER_LEVEL_ADMIN)
                        ->handle($server, [
                            'egg_id' => $pending->target_egg_id,
                            'docker_image' => $pending->docker_image,
                            'startup' => $pending->targetEgg->startup,
                        ]);

                    // Writing the environment variables in a second call,
                    // explicitly set back to USER_LEVEL_USER (EggSwitchRequestService
                    // already filters the payload when the row is created, but we do
                    // not rely only on that: at this level,
                    // VariableValidatorService::handle() applies the user_editable/
                    // user_viewable filter ITSELF, rechecked live against the database
                    // at execution time — so even if a variable's flag changed between
                    // the request and the execution (e.g. an admin makes it
                    // non-editable while a backup is pending), the current state wins,
                    // never a stale snapshot. It also avoids duplicating a security
                    // rule that already lives in the core: if it changes there, this
                    // code follows automatically instead of silently diverging.
                    if (!empty($pending->environment)) {
                        $this->startupModificationService
                            ->setUserLevel(User::USER_LEVEL_USER)
                            ->handle($server, [
                                'environment' => $pending->environment,
                            ]);
                    }

                    return $server;
                });
            } catch (\Throwable $exception) {
                // An exception here (e.g. a required variable missing from the
                // payload) would roll back the "wiping" status update WITHOUT touching
                // the pending row itself (created in a separate, already committed
                // transaction) — it would stay stuck in "ready_to_wipe" forever, a
                // systematic 409 on this server. We therefore catch here and mark
                // FAILED explicitly, THEN let the closure end normally (no rethrow
                // here) so that this FAILED status is actually committed rather
                // than rolled back by DB::transaction()'s automatic rollback. The
                // original exception is rethrown afterwards, once outside the
                // transaction (see below).
                //
                // Fixed message in the database (it may be shown to the server
                // owner): an exception's raw message may contain SQL or internal
                // details. The full detail goes to the panel log. Except for
                // variable validation errors and explicit refusals (e.g. owner
                // change), whose message is written for the user.
                $pending->update([
                    'status' => EggSwitchPendingReinstall::STATUS_FAILED,
                    'failed_reason' => $exception instanceof ValidationException || $exception instanceof HttpExceptionInterface
                        ? 'The game switch failed: ' . $exception->getMessage()
                        : 'The game switch failed while applying the new settings.',
                ]);

                Log::error('eggswitch: egg change failed', ['pending_id' => $pending->id, 'exception' => $exception]);

                Activity::event('eggswitch:egg-change-failed')
                    ->subject($server)
                    ->actor($pending->requestedBy)
                    ->log();

                $failure = $exception;

                return;
            }

            Activity::event('eggswitch:egg-changed')
                ->subject($server)
                ->actor($pending->requestedBy)
                ->property(['new_egg_id' => $pending->target_egg_id])
                ->log();
        });

        if ($failure) {
            throw $failure;
        }

        // ReinstallServerService makes its own network call to Wings — deliberately
        // outside the transaction above so as to never hold a DB lock during a
        // potentially slow external HTTP call.
        $pending = EggSwitchPendingReinstall::query()->findOrFail($pendingId);

        if ($pending->status !== EggSwitchPendingReinstall::STATUS_WIPING) {
            // Cancelled during the window above (theoretically impossible:
            // EggSwitchRequestService::cancel() already refuses the wiping status,
            // but nothing is assumed).
            return;
        }

        $server = $pending->server;

        self::staggerActivityLog();

        try {
            $this->reinstallServerService->handle($server);
        } catch (\Throwable $exception) {
            // A network failure towards Wings must never leave the row stuck in
            // "wiping" indefinitely (it would count as an "active request" and
            // prevent any new attempt on this server).
            // The egg change (StartupModificationService) has already been applied
            // at this stage: the server therefore does point to the new egg even if
            // Wings could not restart the installation; this is deliberate, not a
            // half-done state — a manual reinstall from the native panel remains
            // possible once Wings is back.
            $pending->update([
                'status' => EggSwitchPendingReinstall::STATUS_FAILED,
                'failed_reason' => 'The server\'s node could not be reached while starting the reinstall.',
            ]);

            Log::error('eggswitch: reinstall failed', ['pending_id' => $pending->id, 'exception' => $exception]);

            Activity::event('eggswitch:reinstall-failed')
                ->subject($server)
                ->actor($pending->requestedBy)
                ->log();

            throw $exception;
        }

        Activity::event('eggswitch:reinstall')
            ->subject($server)
            ->actor($pending->requestedBy)
            ->log();

        $pending->update(['status' => EggSwitchPendingReinstall::STATUS_DONE]);
    }

    private static function staggerActivityLog(): void
    {
        sleep(1);
    }
}

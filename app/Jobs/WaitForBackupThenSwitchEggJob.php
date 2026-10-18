<?php

namespace Pterodactyl\BlueprintFramework\Extensions\eggswitch\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Pterodactyl\BlueprintFramework\Extensions\eggswitch\Models\EggSwitchPendingReinstall;
use Pterodactyl\BlueprintFramework\Extensions\eggswitch\Services\EggSwitchExecutionService;
use Pterodactyl\Facades\Activity;

/**
 * Waits for the safety backup to finish (successfully or not) before
 * triggering the real egg change + reinstall. The backup is never
 * synchronous on the Wings side: this job therefore polls its state at regular
 * intervals rather than assuming it has already finished.
 */
class WaitForBackupThenSwitchEggJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** 20 attempts spaced 30 s apart ≈ 10 minutes of waiting at most before giving up. */
    public int $tries = 20;

    public function __construct(private int $pendingId)
    {
    }

    public function handle(EggSwitchExecutionService $execution): void
    {
        $pending = EggSwitchPendingReinstall::query()->find($this->pendingId);

        if (!$pending || $pending->status === EggSwitchPendingReinstall::STATUS_CANCELLED) {
            return;
        }

        if ($pending->status === EggSwitchPendingReinstall::STATUS_READY_TO_WIPE) {
            // A previous run of this job moved the row to ready_to_wipe and then
            // stopped before the egg change (worker killed, crash): resume where
            // it left off rather than leaving the row stuck.
            // performSwitch() relocks the row and rechecks its status.
            $execution->performSwitch($pending->id);

            return;
        }

        if ($pending->status !== EggSwitchPendingReinstall::STATUS_WAITING_BACKUP) {
            // Nothing to do: already handled by a previous run of this job
            // (should not happen with a single-worker queue, but nothing is
            // assumed about the deployment configuration).
            return;
        }

        $backup = $pending->backup;

        if (!$backup || is_null($backup->completed_at)) {
            $this->release(30);

            return;
        }

        // The transitions below are conditional (WHERE status =
        // waiting_backup): the row was read without a lock, and a cancellation
        // may be committed between that read and the write. An unconditional
        // update would then overwrite "cancelled" and the server would be wiped
        // despite the cancellation confirmed to the user.
        if (!$backup->is_successful) {
            $failed = $this->transitionFromWaitingBackup([
                'status' => EggSwitchPendingReinstall::STATUS_FAILED,
                'failed_reason' => 'The safety backup failed, so the game switch was not started.',
            ]);

            if ($failed) {
                Activity::event('eggswitch:backup-failed')
                    ->subject($pending->server)
                    ->actor($pending->requestedBy)
                    ->log();
            }

            return;
        }

        if (!$this->transitionFromWaitingBackup(['status' => EggSwitchPendingReinstall::STATUS_READY_TO_WIPE])) {
            return;
        }

        $execution->performSwitch($pending->id);
    }

    /**
     * The job has exhausted its attempts (backup never finished after ~10
     * minutes): do not leave the row stuck indefinitely in waiting_backup.
     */
    public function failed(\Throwable $exception): void
    {
        // Conditional transitions, never on a row that is wiping or already
        // finished: if the last attempt failed in performSwitch() after the
        // wipe started, the row already carries the right reason (or Wings has
        // already started the reinstall).
        $this->transitionFromWaitingBackup([
            'status' => EggSwitchPendingReinstall::STATUS_FAILED,
            'failed_reason' => 'The safety backup did not finish in time.',
        ]);

        // Last attempt stopped between ready_to_wipe and the start of the wipe
        // (lock unavailable, connection lost, worker killed): no retry will
        // ever happen, the row must not stay active.
        EggSwitchPendingReinstall::query()
            ->whereKey($this->pendingId)
            ->where('status', EggSwitchPendingReinstall::STATUS_READY_TO_WIPE)
            ->update([
                'status' => EggSwitchPendingReinstall::STATUS_FAILED,
                'failed_reason' => 'The game switch could not start after the safety backup.',
            ]);
    }

    /**
     * Applies $attributes only if the row is still in waiting_backup.
     * Returns true if the transition took place.
     */
    private function transitionFromWaitingBackup(array $attributes): bool
    {
        return EggSwitchPendingReinstall::query()
            ->whereKey($this->pendingId)
            ->where('status', EggSwitchPendingReinstall::STATUS_WAITING_BACKUP)
            ->update($attributes) === 1;
    }
}

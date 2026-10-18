<?php

namespace Pterodactyl\BlueprintFramework\Extensions\eggswitch\Models;

use Illuminate\Database\Eloquent\Model;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;

/**
 * "In flight" state of an egg switch request — distinct from the native
 * activity log (Activity::event('eggswitch:...')), which keeps the history.
 * This table only keeps the current state, a single active row (status other
 * than done/failed/cancelled) per server_id at a time — constraint checked at
 * the application level (no portable partial unique index under MySQL), see
 * EggSwitchRequestService::handleReinstallRequest().
 */
class EggSwitchPendingReinstall extends Model
{
    public const STATUS_WAITING_BACKUP = 'waiting_backup';
    public const STATUS_READY_TO_WIPE = 'ready_to_wipe';
    public const STATUS_WIPING = 'wiping';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const ACTIVE_STATUSES = [
        self::STATUS_WAITING_BACKUP,
        self::STATUS_READY_TO_WIPE,
        self::STATUS_WIPING,
    ];

    public const CANCELLABLE_STATUSES = [
        self::STATUS_WAITING_BACKUP,
        self::STATUS_READY_TO_WIPE,
    ];

    protected $table = 'eggswitch_pending_reinstalls';

    protected $guarded = ['id'];

    // Never serialized: whoever returns this model as JSON (an HTTP reply of this
    // extension or of another addon) would otherwise expose the id of the user who
    // made the request, the backup id and the resolved environment. Reading these
    // attributes in PHP is not affected.
    protected $hidden = ['requested_by', 'backup_id', 'environment'];

    protected $casts = [
        'environment' => 'array',
        'with_backup' => 'bool',
        'requested_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    public function targetEgg()
    {
        return $this->belongsTo(Egg::class, 'target_egg_id');
    }

    public function backup()
    {
        return $this->belongsTo(Backup::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, self::CANCELLABLE_STATUSES, true);
    }
}

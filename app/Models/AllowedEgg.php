<?php

namespace Pterodactyl\BlueprintFramework\Extensions\eggswitch\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row = an egg allowed for at least one of the two eggswitch uses
 * (self_service and/or for_addon — see Support\EggWhitelist, the only facade
 * used elsewhere in the code). The table is managed from
 * /admin/extensions/eggswitch (admin/controller.php + admin/view.blade.php),
 * root_admin only.
 *
 * @property int $id
 * @property int $egg_id
 * @property string $label
 * @property string|null $description
 * @property bool $self_service
 * @property bool $for_addon
 */
class AllowedEgg extends Model
{
    protected $table = 'eggswitch_allowed_eggs';

    protected $fillable = ['egg_id', 'label', 'description', 'self_service', 'for_addon'];

    protected $casts = [
        'egg_id' => 'int',
        'self_service' => 'bool',
        'for_addon' => 'bool',
    ];
}

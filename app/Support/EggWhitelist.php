<?php

namespace Pterodactyl\BlueprintFramework\Extensions\eggswitch\Support;

use Pterodactyl\BlueprintFramework\Extensions\eggswitch\Models\AllowedEgg;

/**
 * Whitelists of the eggs eligible for a switch through eggswitch, stored in
 * the database (table `eggswitch_allowed_eggs`, AllowedEgg model) and managed
 * from the /admin/extensions/eggswitch admin screen (see admin/controller.php
 * and admin/view.blade.php): editing the list requires neither touching the
 * code nor redeploying.
 *
 * `EggWhitelist` is the only facade used by the rest of the code
 * (EggSwitchRequestService, EggSwitchController) and by other addons
 * (addonbrowser queries it to know whether modpack installation is possible)
 * — no caller knows the underlying Eloquent model. The two lists are
 * INDEPENDENT: an egg can be in one, the other, both, or neither:
 *
 * - `all()`/`isAllowed()`: classic self-service, exposed in the "Switch game" tab
 *   (the HTTP controller, `EggSwitchRequestService::requestReinstall()`).
 *   A user chooses the egg and fills in the variables themselves.
 * - `forAddons()`/`isAllowedForAddon()`: reserved for a direct call from
 *   ANOTHER addon of the panel (`EggSwitchRequestService::requestReinstallForAddon()`,
 *   e.g. addonbrowser for modpack installation) — never exposed by an HTTP
 *   route.
 */
class EggWhitelist
{
    /**
     * @return array<int, array{label: string, description: string}>
     */
    public static function all(): array
    {
        return self::mapRows(AllowedEgg::query()->where('self_service', true));
    }

    public static function isAllowed(int $eggId): bool
    {
        return AllowedEgg::query()->where('egg_id', $eggId)->where('self_service', true)->exists();
    }

    /**
     * @return int[]
     */
    public static function allowedIds(): array
    {
        return AllowedEgg::query()->where('self_service', true)->orderBy('egg_id')->pluck('egg_id')->all();
    }

    /**
     * @return array<int, array{label: string, description: string}>
     */
    public static function forAddons(): array
    {
        return self::mapRows(AllowedEgg::query()->where('for_addon', true));
    }

    public static function isAllowedForAddon(int $eggId): bool
    {
        return AllowedEgg::query()->where('egg_id', $eggId)->where('for_addon', true)->exists();
    }

    /**
     * @return int[]
     */
    public static function forAddonsIds(): array
    {
        return AllowedEgg::query()->where('for_addon', true)->orderBy('egg_id')->pluck('egg_id')->all();
    }

    /**
     * @return array<int, array{label: string, description: string}>
     */
    private static function mapRows(\Illuminate\Database\Eloquent\Builder $query): array
    {
        return $query->orderBy('egg_id')->get()
            ->mapWithKeys(fn (AllowedEgg $egg) => [
                $egg->egg_id => ['label' => $egg->label, 'description' => $egg->description ?? ''],
            ])
            ->all();
    }
}

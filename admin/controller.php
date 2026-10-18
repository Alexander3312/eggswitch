<?php

namespace Pterodactyl\Http\Controllers\Admin\Extensions\eggswitch;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\View;
use Pterodactyl\BlueprintFramework\Extensions\eggswitch\Models\AllowedEgg;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary as BlueprintExtensionLibrary;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Egg;

/**
 * Admin screen for Egg Switch's allowed-egg whitelist (eggswitch_allowed_eggs,
 * read via app/Support/EggWhitelist.php). Reachable only by a real root_admin
 * (AdminAuthenticate middleware already enforces root_admin on /admin/*).
 *
 * All DB queries are made here, never in the view: the view only
 * displays what the controller passes to it.
 */
class eggswitchExtensionController extends Controller
{
    public function __construct(
        private BlueprintExtensionLibrary $blueprint,
        private ViewFactory $view,
    ) {
    }

    public function index(): View
    {
        $realEggs = Egg::query()->with('nest:id,name')->orderBy('id')->get(['id', 'name', 'nest_id', 'description']);

        return $this->view->make('admin.extensions.eggswitch.index', [
            // Required by Blueprint's shared wrapper (resources/views/blueprint/admin/template.blade.php,
            // $blueprint->extensionMetadata(...)) — without it: "Undefined variable $blueprint".
            'blueprint' => $this->blueprint,
            'root' => '/admin/extensions/eggswitch',
            'entries' => AllowedEgg::query()->orderBy('egg_id')->get(),
            // Feeds the form's dropdown (selecting an egg fills in
            // label/description automatically) — never an id guessed or
            // typed by hand: ids differ from one panel to another.
            'realEggs' => $realEggs,
            // Grouped by nest (alphabetical), ascending id within each group:
            // a plain alphabetical sort on the egg name would mix all the
            // games together. Built here, not in the view (no query or
            // sorting logic in the blade).
            'eggsByNest' => $realEggs
                ->groupBy(fn ($e) => $e->nest->name ?? ('Nest #' . $e->nest_id))
                ->sortKeys(),
            // egg_id => real name, to show the "display name" (label) AND the "real
            // name" side by side in the entries table — the view never queries
            // the DB itself (see above), so this mapping is built here rather
            // than in the blade.
            'eggNames' => $realEggs->pluck('name', 'id'),
            // egg_id => nest name, to show a sortable/filterable "Nest" column
            // in the entries table (same reasoning as eggNames: built here,
            // the view only displays it).
            'eggNests' => $realEggs->mapWithKeys(fn ($e) => [$e->id => $e->nest->name ?? ('Nest #' . $e->nest_id)]),
        ]);
    }

    /** POST /admin/extensions/eggswitch — create (or overwrite) an entry, keyed by egg_id. */
    public function post(Request $request): JsonResponse
    {
        $this->assertRoot($request);

        $data = $this->validated($request);

        $egg = AllowedEgg::query()->updateOrCreate(
            ['egg_id' => $data['egg_id']],
            [
                'label' => $data['label'],
                'description' => $data['description'] ?? null,
                'self_service' => $data['self_service'],
                'for_addon' => $data['for_addon'],
            ],
        );

        return new JsonResponse(['ok' => true, 'id' => $egg->id]);
    }

    /** PATCH /admin/extensions/eggswitch — edit an existing entry (Blueprint routes PATCH -> update()). */
    public function update(Request $request): JsonResponse
    {
        $this->assertRoot($request);

        $request->validate(['allowed_egg_id' => ['required', 'integer']]);
        $data = $this->validated($request);

        $egg = AllowedEgg::query()->findOrFail($request->integer('allowed_egg_id'));
        $egg->update([
            'label' => $data['label'],
            'description' => $data['description'] ?? null,
            'self_service' => $data['self_service'],
            'for_addon' => $data['for_addon'],
        ]);

        return new JsonResponse(['ok' => true]);
    }

    public function put(Request $request): JsonResponse
    {
        return $this->post($request);
    }

    /** DELETE /admin/extensions/eggswitch/egg/{id} — remove an entry. */
    public function delete(Request $request, string $target, string $id): JsonResponse
    {
        $this->assertRoot($request);

        // $id as a string: typed as int, a non-numeric segment would throw a
        // TypeError (500) instead of a 404.
        abort_unless($target === 'egg' && ctype_digit($id), 404);

        AllowedEgg::query()->findOrFail((int) $id)->delete();

        return new JsonResponse(['ok' => true]);
    }

    private function assertRoot(Request $request): void
    {
        abort_unless($request->user() && $request->user()->root_admin, 403);
    }

    /**
     * @return array{egg_id: int, label: string, description: string|null, self_service: bool, for_addon: bool}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'egg_id' => ['required', 'integer', 'min:1', 'exists:eggs,id'],
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'self_service' => ['required', 'boolean'],
            'for_addon' => ['required', 'boolean'],
        ]);
    }
}

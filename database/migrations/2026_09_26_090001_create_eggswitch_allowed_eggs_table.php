<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('eggswitch_allowed_eggs')) {
            return;
        }

        Schema::create('eggswitch_allowed_eggs', function (Blueprint $table) {
            $table->id();
            // No foreign key on eggs.id: an egg id deleted from the panel must be
            // able to remain visible/cleanable from the admin screen rather than
            // block the deletion of the egg or vanish by cascade (same logic as
            // the GET /extensions/eggswitch/eggs endpoint, which already
            // cross-references the real eggs and ignores a vanished id).
            $table->unsignedInteger('egg_id')->unique();
            $table->string('label');
            $table->text('description')->nullable();
            // An egg can be in one, the other, both, or neither of the two lists
            // — see app/Support/EggWhitelist.php for the reason behind the
            // distinction.
            $table->boolean('self_service')->default(false);
            $table->boolean('for_addon')->default(false);
            $table->timestamps();
        });

        // Deliberately NO pre-filled data here: egg ids are not the same from
        // one panel to another — hardcoding the ids of a given panel would
        // silently break on another. After installation, entries are created
        // by hand via /admin/extensions/eggswitch.
    }

    public function down(): void
    {
        Schema::dropIfExists('eggswitch_allowed_eggs');
    }
};

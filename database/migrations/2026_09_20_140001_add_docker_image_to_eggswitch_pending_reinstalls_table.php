<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('eggswitch_pending_reinstalls', 'docker_image')) {
            return;
        }

        Schema::table('eggswitch_pending_reinstalls', function (Blueprint $table) {
            // Docker image resolved at the time of the request (see
            // EggSwitchRequestService::resolveDockerImage()) — either explicitly
            // provided by the caller (validated against the images declared by
            // the target egg), or chosen by default (first image of the egg) if
            // the caller does not care. Nullable for rows created before this
            // column was added.
            $table->string('docker_image')->nullable()->after('target_egg_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('eggswitch_pending_reinstalls', 'docker_image')) {
            return;
        }

        Schema::table('eggswitch_pending_reinstalls', function (Blueprint $table) {
            $table->dropColumn('docker_image');
        });
    }
};

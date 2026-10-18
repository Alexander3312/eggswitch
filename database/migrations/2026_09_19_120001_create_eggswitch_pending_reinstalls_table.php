<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('eggswitch_pending_reinstalls')) {
            return;
        }

        Schema::create('eggswitch_pending_reinstalls', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('target_egg_id');
            // Environment variables to apply at the time of the egg change. JSON
            // to stay generic whatever the target egg, without an extra migration
            // per egg.
            $table->json('environment')->nullable();
            $table->boolean('with_backup')->default(false);
            // unsignedBigInteger, not unsignedInteger: backups.id is a bigIncrements
            // (unlike servers/eggs/users, which are all classic increments).
            $table->unsignedBigInteger('backup_id')->nullable();
            $table->enum('status', [
                'waiting_backup',
                'ready_to_wipe',
                'wiping',
                'done',
                'failed',
                'cancelled',
            ]);
            $table->unsignedInteger('requested_by');
            $table->timestamp('requested_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->text('failed_reason')->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('target_egg_id')->references('id')->on('eggs')->cascadeOnDelete();
            $table->foreign('backup_id')->references('id')->on('backups')->nullOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eggswitch_pending_reinstalls');
    }
};

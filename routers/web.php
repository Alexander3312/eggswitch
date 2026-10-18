<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\BlueprintFramework\Extensions\eggswitch\Http\Controllers\EggSwitchController;
use Pterodactyl\BlueprintFramework\Extensions\eggswitch\Http\Middleware\EnsureEggSwitchAllowed;

// The panel's "blueprint" middleware group (app/Http/Kernel.php) includes no
// authentication check — without "auth" here, these endpoints would be
// reachable anonymously. EnsureEggSwitchAllowed is security level 1: it only
// rejects, early on, users without any server, it never authorizes access to a
// specific server — that is EggSwitchScopeService::assertOwnsServer(), called
// for EVERY action that touches a server, reads included.
// "auth.session" (like the panel's routes): a session invalidated by a
// password change ("log out other devices") is also refused here, not only on
// the panel pages.
Route::middleware(['auth', 'auth.session', EnsureEggSwitchAllowed::class])->group(function () {
    // third parameter = explicit throttle prefix: without it, the Laravel rate
    // limiter key depends ONLY on the authenticated user, never on the route
    // (see ThrottleRequests::resolveRequestSignature()) — the two groups below
    // would then share the same counter for a given user, and exhausting one
    // would exhaust the other.
    Route::middleware('throttle:60,1,eggswitch-read')->group(function () {
        Route::get('/eggs', [EggSwitchController::class, 'eggs'])->name('extension.eggswitch.eggs');
        Route::get('/status', [EggSwitchController::class, 'status'])->name('extension.eggswitch.status');
    });

    // Destructive actions or ones triggering a Wings action: much stricter
    // limit than plain reading above.
    Route::middleware('throttle:10,60,eggswitch-write')->group(function () {
        Route::post('/reinstall', [EggSwitchController::class, 'reinstall'])->name('extension.eggswitch.reinstall');
        Route::post('/cancel', [EggSwitchController::class, 'cancel'])->name('extension.eggswitch.cancel');
    });
});

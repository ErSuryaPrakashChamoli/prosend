<?php

use App\Http\Controllers\Api\V1\EmailController;
use App\Http\Controllers\Api\V1\EmailEventController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Middleware\HandleIdempotency;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Server-to-server API authenticated by ProSend API keys. Registered in
| bootstrap/app.php without sessions, cookies or CSRF, under /api/v1.
|
*/

Route::get('me', MeController::class)->name('me');

Route::post('emails', [EmailController::class, 'store'])
    ->middleware(['api.scope:emails:send', HandleIdempotency::class])
    ->name('emails.store');

Route::middleware('api.scope:emails:read')->group(function (): void {
    Route::get('emails', [EmailController::class, 'index'])->name('emails.index');
    Route::get('emails/{email:uuid}', [EmailController::class, 'show'])->name('emails.show');
    Route::get('emails/{email:uuid}/events', [EmailEventController::class, 'index'])->name('emails.events.index');
});

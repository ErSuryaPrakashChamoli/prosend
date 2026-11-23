<?php

use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\EmailController;
use App\Http\Controllers\Api\V1\EmailEventController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ProspectController;
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

Route::middleware('api.scope:prospects:read')->group(function (): void {
    Route::get('prospects', [ProspectController::class, 'index'])->name('prospects.index');
    Route::get('prospects/{prospect}', [ProspectController::class, 'show'])->name('prospects.show');
});

Route::middleware('api.scope:prospects:write')->group(function (): void {
    Route::put('prospects', [ProspectController::class, 'upsert'])->name('prospects.upsert');
    Route::post('prospects/{prospect}/unsubscribe', [ProspectController::class, 'unsubscribe'])->name('prospects.unsubscribe');
});

Route::middleware('api.scope:campaigns:read')->group(function (): void {
    Route::get('campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
    Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
});

Route::post('campaigns/{campaign}/prospects', [CampaignController::class, 'addProspects'])
    ->middleware('api.scope:campaigns:write')
    ->name('campaigns.prospects.store');

Route::middleware('api.scope:conversations:read')->group(function (): void {
    Route::get('conversations', [ConversationController::class, 'index'])->name('conversations.index');
    Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
});

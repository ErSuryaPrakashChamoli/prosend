<?php

use App\Http\Controllers\BrevoWebhookController;
use App\Http\Controllers\UnsubscribeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Mail Routes
|--------------------------------------------------------------------------
|
| Endpoints reached by recipients and email providers. They run without
| sessions or CSRF protection; signed URLs and secret tokens protect them.
|
*/

Route::middleware('signed')->group(function (): void {
    Route::get('unsubscribe/{message:uuid}', [UnsubscribeController::class, 'show'])->name('unsubscribe');
    Route::post('unsubscribe/{message:uuid}', [UnsubscribeController::class, 'store'])->name('unsubscribe.store');
});

Route::post('webhooks/brevo/{provider}/{token}', BrevoWebhookController::class)
    ->middleware('throttle:6000,1')
    ->name('webhooks.brevo');

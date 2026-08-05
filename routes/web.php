<?php

use AxelFerdinand\StatamicSecretary\Http\Controllers\Web\PostmarkInboundController;
use AxelFerdinand\StatamicSecretary\Http\Controllers\Web\RelayInboundController;
use AxelFerdinand\StatamicSecretary\Http\Middleware\EnsureSecretaryDatabase;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

Route::prefix('_secretary')->name('secretary.web.')->middleware(EnsureSecretaryDatabase::class)->group(function (): void {
    Route::post('/webhooks/postmark/inbound', PostmarkInboundController::class)
        ->withoutMiddleware([PreventRequestForgery::class, VerifyCsrfToken::class])
        ->middleware('throttle:120,1')
        ->name('postmark.inbound');

    Route::post('/webhooks/relay/inbound', RelayInboundController::class)
        ->withoutMiddleware([PreventRequestForgery::class, VerifyCsrfToken::class])
        ->middleware('throttle:120,1')
        ->name('relay.inbound');
});

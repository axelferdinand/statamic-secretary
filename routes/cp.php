<?php

use AxelFerdinand\StatamicSecretary\Http\Controllers\Cp\SecretaryController;
use AxelFerdinand\StatamicSecretary\Http\Controllers\Cp\SecretaryPanelController;
use Illuminate\Support\Facades\Route;

Route::prefix('secretary')->name('secretary.')->group(function (): void {
    Route::get('/', [SecretaryController::class, 'index'])->name('index');
    Route::post('/conversations', [SecretaryController::class, 'store'])->middleware('throttle:30,1')->name('store');
    Route::post('/setup/postmark', [SecretaryController::class, 'connectPostmark'])->middleware('throttle:10,1')->name('setup.postmark');
    Route::post('/setup/relay/request-code', [SecretaryController::class, 'requestRelayCode'])->middleware('throttle:5,1')->name('setup.relay.request-code');
    Route::post('/setup/relay', [SecretaryController::class, 'connectRelay'])->middleware('throttle:10,1')->name('setup.relay');
    Route::get('/panel/data', [SecretaryPanelController::class, 'index'])->middleware('throttle:120,1')->name('panel.data');
    Route::post('/panel/conversations', [SecretaryPanelController::class, 'store'])->middleware('throttle:30,1')->name('panel.store');
    Route::post('/panel/{conversation}/messages', [SecretaryPanelController::class, 'send'])->middleware('throttle:20,1')->name('panel.messages.store');
    Route::get('/{conversation}', [SecretaryController::class, 'index'])->name('show');
    Route::post('/{conversation}/messages', [SecretaryController::class, 'send'])->middleware('throttle:20,1')->name('messages.store');
    Route::post('/{conversation}/changes/{changeSet}/publish', [SecretaryController::class, 'publish'])->middleware('throttle:30,1')->name('changes.publish');
});

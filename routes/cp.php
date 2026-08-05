<?php

use AxelFerdinand\StatamicSecretary\Http\Controllers\Cp\SecretaryController;
use AxelFerdinand\StatamicSecretary\Http\Controllers\Cp\SecretaryPanelController;
use AxelFerdinand\StatamicSecretary\Http\Middleware\EnsureSecretaryDatabase;
use Illuminate\Support\Facades\Route;

Route::prefix('secretary')->name('secretary.')->middleware(EnsureSecretaryDatabase::class)->group(function (): void {
    Route::get('/', [SecretaryController::class, 'index'])->name('index');
    Route::post('/conversations', [SecretaryController::class, 'store'])->middleware('throttle:30,1')->name('store');
    Route::post('/setup/postmark', [SecretaryController::class, 'connectPostmark'])->middleware('throttle:10,1')->name('setup.postmark');
    Route::post('/setup/postmark/confirm-forwarding', [SecretaryController::class, 'confirmPostmarkForwarding'])->middleware('throttle:10,1')->name('setup.postmark.confirm-forwarding');
    Route::post('/setup/openai', [SecretaryController::class, 'saveOpenAIKey'])->middleware('throttle:10,1')->name('setup.openai');
    Route::post('/setup/safe-drafts', [SecretaryController::class, 'enableSafeDrafting'])->middleware('throttle:10,1')->name('setup.safe-drafts');
    Route::post('/setup/skip-email', [SecretaryController::class, 'skipEmailSetup'])->middleware('throttle:10,1')->name('setup.skip-email');
    Route::post('/setup/relay/request-code', [SecretaryController::class, 'requestRelayCode'])->middleware('throttle:5,1')->name('setup.relay.request-code');
    Route::post('/setup/relay', [SecretaryController::class, 'connectRelay'])->middleware('throttle:10,1')->name('setup.relay');
    Route::post('/settings/editorial', [SecretaryController::class, 'saveEditorialGuide'])->middleware('throttle:20,1')->name('settings.editorial');
    Route::post('/diagnostics/run', [SecretaryController::class, 'runDiagnostics'])->middleware('throttle:20,1')->name('diagnostics.run');
    Route::get('/panel/data', [SecretaryPanelController::class, 'index'])->middleware('throttle:120,1')->name('panel.data');
    Route::get('/panel/references', [SecretaryPanelController::class, 'references'])->middleware('throttle:120,1')->name('panel.references');
    Route::post('/panel/conversations', [SecretaryPanelController::class, 'store'])->middleware('throttle:30,1')->name('panel.store');
    Route::post('/panel/{conversation}/messages', [SecretaryPanelController::class, 'send'])->middleware('throttle:20,1')->name('panel.messages.store');
    Route::post('/panel/{conversation}/changes/{changeSet}/review', [SecretaryPanelController::class, 'review'])->middleware('throttle:60,1')->name('panel.changes.review');
    Route::get('/panel/{conversation}/changes/{changeSet}/preview', [SecretaryPanelController::class, 'preview'])->middleware('throttle:60,1')->name('panel.changes.preview');
    Route::post('/panel/{conversation}/changes/{changeSet}/publish', [SecretaryPanelController::class, 'publish'])->middleware('throttle:30,1')->name('panel.changes.publish');
    Route::get('/{conversation}', [SecretaryController::class, 'index'])->name('show');
    Route::post('/{conversation}/messages', [SecretaryController::class, 'send'])->middleware('throttle:20,1')->name('messages.store');
    Route::post('/{conversation}/changes/{changeSet}/review', [SecretaryController::class, 'review'])->middleware('throttle:60,1')->name('changes.review');
    Route::get('/{conversation}/changes/{changeSet}/preview', [SecretaryController::class, 'preview'])->middleware('throttle:60,1')->name('changes.preview');
    Route::post('/{conversation}/changes/{changeSet}/publish', [SecretaryController::class, 'publish'])->middleware('throttle:30,1')->name('changes.publish');
});

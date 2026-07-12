<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Padosoft\AskMyDocsConnectorApi\Http\Controllers\ApiAuthProfileController;
use Padosoft\AskMyDocsConnectorApi\Http\Controllers\ApiConnectorController;
use Padosoft\AskMyDocsConnectorApi\Http\Controllers\ApiRouteController;
use Padosoft\AskMyDocsConnectorApi\Http\Controllers\ApiRouteRelationController;

/*
|--------------------------------------------------------------------------
| API Connector — Admin HTTP routes
|--------------------------------------------------------------------------
|
| Loaded by ApiConnectorServiceProvider::registerRoutes() inside a group that
| applies `connector-api.routes.prefix` (default `api/admin/api-connectors`) +
| `connector-api.routes.middleware` (the HOST overrides this with its
| authenticated admin stack — R32). Do NOT re-apply prefix/middleware here.
|
| Route params are plain ids ({connector}/{route}/{profile}); every controller
| loads the model tenant-scoped (R30) — no implicit route-model binding, so a
| guessed id from another tenant 404s.
|
| Publish to customise:
|   php artisan vendor:publish --tag=api-connector-routes
|
*/

Route::name('api-connectors.')->group(function (): void {
    // Playground — ad-hoc live probe of a FREE (no-auth) endpoint. Persists
    // nothing (no connector/route rows), infers no schema; a read-only
    // diagnostic. No {route} path param, so it inherits the group's authenticated
    // admin stack + can:manageConnectors (R32) like every sibling.
    Route::post('probe', [ApiRouteController::class, 'probe'])->name('probe');

    // Connectors
    Route::get('/', [ApiConnectorController::class, 'index'])->name('index');
    Route::post('/', [ApiConnectorController::class, 'store'])->name('store');
    Route::get('{connector}', [ApiConnectorController::class, 'show'])
        ->whereNumber('connector')->name('show');
    Route::patch('{connector}', [ApiConnectorController::class, 'update'])
        ->whereNumber('connector')->name('update');
    Route::delete('{connector}', [ApiConnectorController::class, 'destroy'])
        ->whereNumber('connector')->name('destroy');

    // Auth profiles
    Route::post('{connector}/auth-profiles', [ApiAuthProfileController::class, 'store'])
        ->whereNumber('connector')->name('auth-profiles.store');
    Route::patch('auth-profiles/{profile}', [ApiAuthProfileController::class, 'update'])
        ->whereNumber('profile')->name('auth-profiles.update');
    Route::delete('auth-profiles/{profile}', [ApiAuthProfileController::class, 'destroy'])
        ->whereNumber('profile')->name('auth-profiles.destroy');

    // Routes (Rotte)
    Route::post('{connector}/routes', [ApiRouteController::class, 'store'])
        ->whereNumber('connector')->name('routes.store');
    Route::get('routes/{route}', [ApiRouteController::class, 'show'])
        ->whereNumber('route')->name('routes.show');
    Route::patch('routes/{route}', [ApiRouteController::class, 'update'])
        ->whereNumber('route')->name('routes.update');
    Route::delete('routes/{route}', [ApiRouteController::class, 'destroy'])
        ->whereNumber('route')->name('routes.destroy');
    Route::post('routes/{route}/test', [ApiRouteController::class, 'test'])
        ->whereNumber('route')->name('routes.test');
    Route::post('routes/{route}/regenerate-description', [ApiRouteController::class, 'regenerateDescription'])
        ->whereNumber('route')->name('routes.regenerate-description');
    Route::post('routes/{route}/activate', [ApiRouteController::class, 'activate'])
        ->whereNumber('route')->name('routes.activate');
    Route::post('routes/{route}/disable', [ApiRouteController::class, 'disable'])
        ->whereNumber('route')->name('routes.disable');
    Route::post('routes/{route}/try', [ApiRouteController::class, 'tryTool'])
        ->whereNumber('route')->name('routes.try');
    // Workbench "Analisi" — fire the route + return a reduced structure (item 3).
    Route::post('routes/{route}/analyze', [ApiRouteController::class, 'analyze'])
        ->whereNumber('route')->name('routes.analyze');
    // Workbench "Paginazione" — detect (items 4) + test (item 5).
    Route::post('routes/{route}/detect-pagination', [ApiRouteController::class, 'detectPagination'])
        ->whereNumber('route')->name('routes.detect-pagination');
    Route::post('routes/{route}/test-pagination', [ApiRouteController::class, 'testPagination'])
        ->whereNumber('route')->name('routes.test-pagination');

    // Relations (List → Detail) — spec Obj 3
    Route::get('{connector}/relations', [ApiRouteRelationController::class, 'index'])
        ->whereNumber('connector')->name('relations.index');
    Route::post('{connector}/relations', [ApiRouteRelationController::class, 'store'])
        ->whereNumber('connector')->name('relations.store');
    Route::get('relations/{relation}', [ApiRouteRelationController::class, 'show'])
        ->whereNumber('relation')->name('relations.show');
    Route::patch('relations/{relation}', [ApiRouteRelationController::class, 'update'])
        ->whereNumber('relation')->name('relations.update');
    Route::delete('relations/{relation}', [ApiRouteRelationController::class, 'destroy'])
        ->whereNumber('relation')->name('relations.destroy');
    Route::post('relations/{relation}/drill', [ApiRouteRelationController::class, 'drill'])
        ->whereNumber('relation')->name('relations.drill');
});

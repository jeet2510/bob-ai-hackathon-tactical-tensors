<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BodyController;
use App\Http\Controllers\Api\EvaluationController;
use App\Http\Controllers\Api\IncidentController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\SupportController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'success' => true,
    'message' => 'DVI Coordinator API is running',
]));

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/incidents', [IncidentController::class, 'index']);
    Route::get('/incidents/{incident}', [IncidentController::class, 'show']);

    Route::prefix('incidents/{incident}')->group(function () {

        /*
        |------------------------------------------------------------------
        | Post-mortem: the triage board and the body review screen
        |------------------------------------------------------------------
        */
        Route::get('/bodies', [BodyController::class, 'index']);
        Route::get('/bodies/{pmId}', [BodyController::class, 'show']);

        /*
        |------------------------------------------------------------------
        | Ante-mortem: family reports
        |------------------------------------------------------------------
        */
        Route::get('/profiles', [SupportController::class, 'profiles']);
        Route::get('/profiles/{amId}', [SupportController::class, 'profile']);

        /*
        |------------------------------------------------------------------
        | Review. Append-only: there is no update or delete route.
        |------------------------------------------------------------------
        */
        Route::get('/bodies/{pmId}/decisions', [ReviewController::class, 'index']);
        Route::post('/bodies/{pmId}/decisions', [ReviewController::class, 'store']);
        Route::get('/audit', [ReviewController::class, 'audit']);

        Route::get('/assignment', [SupportController::class, 'assignment']);
        Route::get('/report', [SupportController::class, 'report']);
        Route::get('/photos', [SupportController::class, 'photos']);

        /*
        |------------------------------------------------------------------
        | Evaluation. The only route that reads ground truth, and it reads it
        | solely to compare against — nothing here feeds the pipeline.
        |------------------------------------------------------------------
        */
        Route::get('/evaluation', [EvaluationController::class, 'show']);
    });

    // Human-in-the-loop correction of a mis-read item.
    Route::patch('/observation-items/{item}', [SupportController::class, 'reviewItem']);

    Route::get('/photos/{photo}/file', [SupportController::class, 'photo']);
});

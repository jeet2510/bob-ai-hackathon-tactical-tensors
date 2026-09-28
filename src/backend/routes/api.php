<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BodyController;
use App\Http\Controllers\Api\EvaluationController;
use App\Http\Controllers\Api\IncidentController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\SupportController;
use App\Http\Controllers\Api\UploadController;
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
    Route::post('/incidents', [IncidentController::class, 'store']);
    Route::get('/incidents/{incident}', [IncidentController::class, 'show']);

    Route::prefix('incidents/{incident}')->group(function () {

        /*
        |------------------------------------------------------------------
        | Post-mortem: the triage board, the body review screen, and the
        | Incident Pipeline intake form (manual entry and both AI-assist
        | scan endpoints funnel into the same POST /bodies).
        |------------------------------------------------------------------
        */
        Route::get('/bodies', [BodyController::class, 'index']);
        Route::post('/bodies', [BodyController::class, 'store']);
        Route::get('/bodies/{pmId}', [BodyController::class, 'show']);
        Route::post('/bodies/scan-pdf', [BodyController::class, 'scanPdf']);
        Route::post('/bodies/scan-photo', [BodyController::class, 'scanPhoto']);

        Route::post('/uploads', [UploadController::class, 'store']);

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

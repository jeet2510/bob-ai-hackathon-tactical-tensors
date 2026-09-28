<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BodyController;
use App\Http\Controllers\Api\EvaluationController;
use App\Http\Controllers\Api\IncidentController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PublicReportController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\SupportController;
use App\Http\Controllers\Api\UploadController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'success' => true,
    'message' => 'DVI Coordinator API is running',
]));

Route::post('/login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| Public: the ante-mortem family-report share link.
|--------------------------------------------------------------------------
|
| The only unauthenticated write path in this API. No Sanctum token, no
| session — each request instead carries a `token` (query string on the GET,
| body on the POSTs) that PublicReportController verifies per-request
| against ShareToken. Deliberately its own small controller, not folded into
| SupportController/ProfileController, so the entire public attack surface
| is one file someone can review in one sitting.
|
*/
Route::prefix('public/incidents/{incident}')->group(function () {
    Route::get('/report-context', [PublicReportController::class, 'context']);
    Route::post('/reports', [PublicReportController::class, 'store']);
    Route::post('/uploads', [PublicReportController::class, 'upload']);
});

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

        // AI-assisted match refinement: Gemini re-ranks a body's existing
        // rules-based shortlist using photographs neither side's text
        // description alone captures. Advisory only — see GeminiMatcher.
        Route::post('/bodies/{pmId}/gemini-match', [BodyController::class, 'geminiMatch']);
        Route::get('/bodies/{pmId}/gemini-match', [BodyController::class, 'latestGeminiMatch']);

        Route::post('/uploads', [UploadController::class, 'store']);

        /*
        |------------------------------------------------------------------
        | Ante-mortem: family reports. `store` is a coordinator entering a
        | phoned-in report themselves; `share-link` mints the public link a
        | family can use to submit one without logging in at all (see the
        | public route group above, and PublicReportController).
        |------------------------------------------------------------------
        */
        Route::get('/profiles', [SupportController::class, 'profiles']);
        Route::post('/profiles', [ProfileController::class, 'store']);
        Route::get('/profiles/{amId}', [SupportController::class, 'profile']);
        Route::post('/profiles/share-link', [ProfileController::class, 'shareLink']);

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

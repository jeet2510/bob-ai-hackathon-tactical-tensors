<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google Gemini
    |--------------------------------------------------------------------------
    |
    | Powers the two AI-assist panels on the post-mortem intake form: reading
    | a scanned copy of the paper form, and reading a live-scan photo of a
    | recovered body. Entirely optional, the same way watsonx is: with no
    | credentials the intake form is still fully usable by hand.
    |
    */

    'api_key' => env('GEMINI_API_KEY'),

    'url' => env('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta'),

    // The PDF form-scan model.
    'model' => env('GEMINI_MODEL', 'gemini-flash-latest'),

    // The live-scan (body photo) model.
    'vision_model' => env('GEMINI_VISION_MODEL', 'gemini-flash-latest'),

    'temperature' => 0.0,

    'max_tokens' => env('GEMINI_MAX_TOKENS', 8192),

    'timeout' => env('GEMINI_TIMEOUT', 60),

    'max_upload_mb' => env('GEMINI_MAX_UPLOAD_MB', 15),

    /*
    | Bump when a prompt or response schema changes, so cached completions
    | from the previous version are never silently reused.
    */
    'prompt_version' => 'gemini-pm-form-v1',

];

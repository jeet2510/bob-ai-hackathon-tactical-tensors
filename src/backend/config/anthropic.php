<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Anthropic Claude
    |--------------------------------------------------------------------------
    |
    | Primary provider for the intake form's two AI-assist panels (Gemini is
    | the fallback — see FormScanCoordinator). Optional, the same way every
    | AI integration in this app is: with no credentials the intake form is
    | still fully usable by hand.
    |
    */

    'api_key' => env('ANTHROPIC_API_KEY'),

    'url' => env('ANTHROPIC_API_URL', 'https://api.anthropic.com/v1/messages'),

    'version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),

    'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),

    'max_tokens' => env('ANTHROPIC_MAX_TOKENS', 8192),

    'timeout' => env('ANTHROPIC_TIMEOUT', 60),

    'max_upload_mb' => env('ANTHROPIC_MAX_UPLOAD_MB', 15),

    /*
    | Bump when a prompt or schema changes, so cached completions from the
    | previous version are never silently reused.
    */
    'prompt_version' => 'anthropic-pm-form-v1',

];

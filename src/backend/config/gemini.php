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

    // The body -> family candidate-refinement model (multimodal: examiner
    // data, family data, and photos from both sides).
    'match_model' => env('GEMINI_MATCH_MODEL', 'gemini-flash-latest'),

    // The DVI Assistant: dashboard insight + the chat sidebar. Text only —
    // it never sees photographs, only the same aggregate numbers and
    // reconciliation-report data already shown on screen.
    'assistant_model' => env('GEMINI_ASSISTANT_MODEL', 'gemini-flash-latest'),

    'temperature' => 0.0,

    'max_tokens' => env('GEMINI_MAX_TOKENS', 8192),

    'timeout' => env('GEMINI_TIMEOUT', 60),

    'max_upload_mb' => env('GEMINI_MAX_UPLOAD_MB', 15),

    /*
    | Bump when a prompt or response schema changes, so cached completions
    | from the previous version are never silently reused.
    */
    'prompt_version' => 'gemini-pm-form-v1',

    // Independent prompt-version line for the match-refinement prompt/schema
    // (structurally unrelated to the form-scan prompt above) — bump this one
    // when that prompt or schema changes, without touching form-scan's cache.
    'match_prompt_version' => 'gemini-match-v1',

    // How many of a body's/candidate's own matchable photos to send per
    // record. Bounds payload size and latency when refining a shortlist of
    // several candidates at once, well within gemini.timeout.
    'match_photos_per_record' => env('GEMINI_MATCH_PHOTOS_PER_RECORD', 3),

    // How many of the deterministic shortlist's top candidates Gemini is
    // asked to refine. RETAINED_PER_BODY keeps up to 10; capping further here
    // keeps a single match request's image count reasonable.
    'match_shortlist_size' => env('GEMINI_MATCH_SHORTLIST_SIZE', 6),

    // Independent prompt-version lines for the assistant's two call shapes —
    // bump either on a prompt/persona change without invalidating the other.
    'assistant_prompt_version' => 'gemini-assistant-v1',

];

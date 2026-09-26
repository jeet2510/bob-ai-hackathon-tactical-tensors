<?php

return [

    /*
    |--------------------------------------------------------------------------
    | IBM watsonx.ai
    |--------------------------------------------------------------------------
    |
    | Runtime model access for Stage-1 normalisation. Entirely optional: with
    | no credentials the application runs on the deterministic rules extractor
    | and every screen still works. That is a deliberate property — a tool for
    | a disaster site cannot assume connectivity.
    |
    */

    'api_key' => env('WATSONX_API_KEY'),

    'project_id' => env('WATSONX_PROJECT_ID'),

    'url' => env('WATSONX_URL', 'https://us-south.ml.cloud.ibm.com'),

    'iam_url' => env('WATSONX_IAM_URL', 'https://iam.cloud.ibm.com/identity/token'),

    'version' => env('WATSONX_API_VERSION', '2024-05-31'),

    /*
    | Granite is IBM's instruction-tuned family. A small model is sufficient
    | here: the task is constrained extraction into a closed codebook, not
    | open-ended generation.
    */
    'model' => env('WATSONX_MODEL', 'ibm/granite-3-3-8b-instruct'),

    'vision_model' => env('WATSONX_VISION_MODEL', 'ibm/granite-vision-3-2-2b'),

    /*
    | Deterministic decoding. Extraction has a right answer, and sampling
    | would make two runs over the same incident disagree.
    */
    'temperature' => 0.0,

    'max_tokens' => 700,

    'timeout' => env('WATSONX_TIMEOUT', 30),

    /*
    | Bump when the prompt changes so cached completions from the previous
    | wording are not silently reused.
    */
    'prompt_version' => 'granite-extract-v1',

];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dataset location
    |--------------------------------------------------------------------------
    |
    | Where the incident's source records and image files live. `dvi:ingest`
    | reads the CSVs from here and photographs are served relative to it.
    |
    */

    'data_path' => env('DVI_DATA_PATH', base_path('../data/data')),

    /*
    |--------------------------------------------------------------------------
    | Ground truth
    |--------------------------------------------------------------------------
    |
    | Read by the evaluation module and by nothing else. Set DVI_GROUND_TRUTH
    | to an empty value in a deployment where truth should not be present at
    | all — the evaluation screen then reports itself unavailable rather than
    | failing, and every other part of the system is unaffected.
    |
    */

    'ground_truth_path' => env('DVI_GROUND_TRUTH_PATH', base_path('../data/data/ground_truth')),

];

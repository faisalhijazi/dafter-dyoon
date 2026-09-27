<?php

return [
    /*
    |--------------------------------------------------------------------------
    | مساعد AI — local Python NLU engine (no external AI provider)
    |--------------------------------------------------------------------------
    |
    | The web server's PATH often lacks Python, so point ASSISTANT_PYTHON at the
    | interpreter explicitly in production (e.g. /usr/bin/python3).
    */

    'python' => env('ASSISTANT_PYTHON', 'python'),

    'script' => base_path('ai/assistant.py'),

    'timeout' => (int) env('ASSISTANT_TIMEOUT', 15),

    // Messages per user per minute.
    'rate_limit' => (int) env('ASSISTANT_RATE_LIMIT', 30),

    // How many past messages the chat page shows.
    'history' => 60,

    /*
    |--------------------------------------------------------------------------
    | Self-learning
    |--------------------------------------------------------------------------
    |
    | A masked phrase enters the shared model once it was used (and not disputed)
    | at `min_tenants` different stores, or as soon as the platform admin approves
    | it. Retraining runs nightly (`assistant:learn`) and a candidate model is only
    | activated when it passes every acceptance phrase and its hold-out accuracy
    | does not drop by more than `accuracy_tolerance`.
    */
    'learning' => [
        'min_tenants' => (int) env('ASSISTANT_LEARN_MIN_TENANTS', 3),
        'accuracy_tolerance' => (float) env('ASSISTANT_LEARN_TOLERANCE', 0.01),
        'keep_versions' => 5,
        'path' => storage_path('app/assistant'),
        'schedule' => env('ASSISTANT_LEARN_AT', '03:00'),
    ],
];

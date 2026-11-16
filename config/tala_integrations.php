<?php

return [
    'scheduling_solver' => [
        'driver' => env('TALA_SCHEDULING_SOLVER_DRIVER', 'local_stub'),
        'url' => env('TALA_SCHEDULING_SOLVER_URL'),
        'audience' => env('TALA_SCHEDULING_SOLVER_AUDIENCE'),
        'credentials_path' => env('TALA_SCHEDULING_SOLVER_CREDENTIALS'),
        'timeout_seconds' => env('TALA_SCHEDULING_SOLVER_HTTP_TIMEOUT_SECONDS', 330),
        'connect_timeout_seconds' => env('TALA_SCHEDULING_SOLVER_CONNECT_TIMEOUT_SECONDS', 10),
    ],
];

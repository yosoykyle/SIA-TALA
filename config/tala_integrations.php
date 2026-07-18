<?php

return [
    'scheduling_solver' => [
        'driver' => env('TALA_SCHEDULING_SOLVER_DRIVER', 'local_stub'),
        'url' => env('TALA_SCHEDULING_SOLVER_URL'),
        'audience' => env('TALA_SCHEDULING_SOLVER_AUDIENCE'),
        'credentials_path' => env('TALA_SCHEDULING_SOLVER_CREDENTIALS'),
        'timeout_seconds' => env('TALA_SCHEDULING_SOLVER_TIMEOUT_SECONDS', 300),
        'connect_timeout_seconds' => env('TALA_SCHEDULING_SOLVER_CONNECT_TIMEOUT_SECONDS', 10),
        'benchmark' => [
            'revision' => env('TALA_SCHEDULING_BENCHMARK_REVISION'),
            'image_digest' => env('TALA_SCHEDULING_BENCHMARK_IMAGE_DIGEST'),
            'profile' => env('TALA_SCHEDULING_BENCHMARK_PROFILE'),
            'cpu' => env('TALA_SCHEDULING_BENCHMARK_CPU'),
            'memory' => env('TALA_SCHEDULING_BENCHMARK_MEMORY'),
            'concurrency' => env('TALA_SCHEDULING_BENCHMARK_CONCURRENCY'),
            'request_timeout_seconds' => env('TALA_SCHEDULING_BENCHMARK_REQUEST_TIMEOUT_SECONDS'),
            'solver_limit_seconds' => env('TALA_SCHEDULING_BENCHMARK_SOLVER_LIMIT_SECONDS'),
            'worker_count' => env('TALA_SCHEDULING_BENCHMARK_WORKER_COUNT'),
            'random_seed' => env('TALA_SCHEDULING_BENCHMARK_RANDOM_SEED'),
            'min_instances' => env('TALA_SCHEDULING_BENCHMARK_MIN_INSTANCES'),
            'max_instances' => env('TALA_SCHEDULING_BENCHMARK_MAX_INSTANCES'),
        ],
    ],

    'payments' => [
        'driver' => env('TALA_PAYMENT_GATEWAY_DRIVER', 'mock'),
        'mock' => [
            'provider' => env('TALA_PAYMENT_MOCK_PROVIDER', 'mock'),
            'checkout_base_url' => env('TALA_PAYMENT_MOCK_CHECKOUT_URL', 'https://mock-payments.test/checkout'),
        ],
        'paymongo' => [
            'base_url' => env('PAYMONGO_BASE_URL', 'https://api.paymongo.com'),
            'public_key' => env('PAYMONGO_PUBLIC_KEY'),
            'secret_key' => env('PAYMONGO_SECRET_KEY'),
            'webhook_signature' => env('PAYMONGO_WEBHOOK_SIG'),
            'signature_header_name' => env('PAYMONGO_SIG_HEADER', 'paymongo-signature'),
            'signature_max_age_seconds' => env('PAYMONGO_WEBHOOK_MAX_AGE_SECONDS', 300),
            'livemode' => env('PAYMONGO_LIVEMODE', false),
            'max_payload_bytes' => env('PAYMONGO_WEBHOOK_MAX_BYTES', 1_048_576),
            'payment_method_types' => array_values(array_filter(array_map(
                'trim',
                explode(',', env('PAYMONGO_PAYMENT_METHOD_TYPES', 'gcash,card')),
            ))),
        ],
    ],
];

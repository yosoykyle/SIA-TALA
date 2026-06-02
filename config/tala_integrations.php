<?php

return [
    'ocr' => [
        'driver' => env('TALA_OCR_DRIVER', 'mock'),
        'confidence_threshold' => env('TALA_OCR_CONFIDENCE_THRESHOLD', '80.00'),
        'mock' => [
            'engine' => env('TALA_OCR_MOCK_ENGINE', 'mock_vision'),
            'text' => env('TALA_OCR_MOCK_TEXT', 'Mock OCR text extracted from the uploaded document.'),
            'confidence' => env('TALA_OCR_MOCK_CONFIDENCE', '95.00'),
        ],
        'google_vision' => [
            'project_id' => env('GOOGLE_CLOUD_PROJECT_ID'),
            'credentials_path' => env('GOOGLE_APPLICATION_CREDENTIALS'),
            'monthly_call_limit' => env('TALA_OCR_MONTHLY_CALL_LIMIT', 2000),
        ],
    ],

    'payments' => [
        'driver' => env('TALA_PAYMENT_GATEWAY_DRIVER', 'mock'),
        'mock' => [
            'provider' => env('TALA_PAYMENT_MOCK_PROVIDER', 'mock'),
            'checkout_base_url' => env('TALA_PAYMENT_MOCK_CHECKOUT_URL', 'https://mock-payments.test/checkout'),
        ],
        'paymongo' => [
            'base_url' => env('PAYMONGO_BASE_URL', 'https://api.paymongo.com/v1'),
            'public_key' => env('PAYMONGO_PUBLIC_KEY'),
            'secret_key' => env('PAYMONGO_SECRET_KEY'),
            'webhook_signature' => env('PAYMONGO_WEBHOOK_SIG'),
            'livemode' => env('PAYMONGO_LIVEMODE', false),
            'payment_method_types' => array_values(array_filter(array_map(
                'trim',
                explode(',', env('PAYMONGO_PAYMENT_METHOD_TYPES', 'gcash,card')),
            ))),
        ],
    ],
];

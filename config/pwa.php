<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Would you like the install button to appear on all pages?
      Set true/false
    |--------------------------------------------------------------------------
    */

    'install-button' => true,

    /*
    |--------------------------------------------------------------------------
    | PWA Manifest Configuration
    |--------------------------------------------------------------------------
    |  php artisan erag:update-manifest
    */

    'manifest' => [
        'name' => 'T.A.L.A. School Information System',
        'short_name' => 'T.A.L.A. SIS',
        'background_color' => '#f4f4f5',
        'display' => 'standalone',
        'description' => 'College student portal for enrollment, schedule, COR, grades, financial standing, and help.',
        'theme_color' => '#0369a1',
        'icons' => [
            [
                'src' => 'talalogo.jpg',
                'sizes' => '512x512',
                'type' => 'image/jpeg',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug Configuration
    |--------------------------------------------------------------------------
    | Toggles the application's debug mode based on the environment variable
    */

    'debug' => env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Livewire Integration
    |--------------------------------------------------------------------------
    | Set to true if you're using Livewire in your application to enable
    | Livewire-specific PWA optimizations or features.
    */

    'livewire-app' => true,
];

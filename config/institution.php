<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Institution Identity
    |--------------------------------------------------------------------------
    |
    | Shared by the public landing page and official source-derived outputs.
    | The campus address supplied by the owner is the default; an environment
    | override, including an explicitly blank address, takes precedence.
    |
    */

    'name' => env('INSTITUTION_NAME', 'SERVITECH INSTITUTE ASIA INC.'),

    'address' => env('INSTITUTION_ADDRESS', '162 R. Magsaysay Ave, San Pedro, Laguna, Philippines'),

    'public' => [
        'map_embed_url' => env('INSTITUTION_MAP_EMBED_URL', 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3865.5802070946584!2d121.03139289999999!3d14.3358!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3397d7a36f29214b%3A0xad9cc8e497685b8!2sServitech%20Institute%20Asia%2C%20Inc.!5e0!3m2!1sen!2sph!4v1791370878609!5m2!1sen!2sph'),
        'support_facebook_url' => env('INSTITUTION_SUPPORT_FACEBOOK_URL', 'https://www.facebook.com/servitechinstituteasiaph'),
        'support_phone' => env('INSTITUTION_SUPPORT_PHONE', '0947 737 9208'),
        'support_phone_uri' => env('INSTITUTION_SUPPORT_PHONE_URI', 'tel:+639477379208'),
        'map_url' => env('INSTITUTION_MAP_URL', 'https://www.google.com/maps?cid=781880921815418296&g_mp=CiVnb29nbGUubWFwcy5wbGFjZXMudjEuUGxhY2VzLkdldFBsYWNlEAMYASAF&hl=en&gl=PH&source=embed'),
    ],

];

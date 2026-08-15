<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Institution Identity
    |--------------------------------------------------------------------------
    |
    | Displayed on official source-derived outputs (SOA, COR). The address is
    | configurable per PRD 8.9.1 and left blank until the institution sets it,
    | so no placeholder address is printed on finance outputs by default.
    |
    */

    'name' => env('INSTITUTION_NAME', 'SERVITECH INSTITUTE ASIA INC.'),

    'address' => env('INSTITUTION_ADDRESS', ''),

    'public' => [
        'support_facebook_url' => env('INSTITUTION_SUPPORT_FACEBOOK_URL', 'https://www.facebook.com/servitechinstituteasiaph'),
        'support_phone' => env('INSTITUTION_SUPPORT_PHONE', '0947 737 9208'),
        'support_phone_uri' => env('INSTITUTION_SUPPORT_PHONE_URI', 'tel:+639477379208'),
        'map_url' => env('INSTITUTION_MAP_URL', 'https://www.google.com/maps?cid=781880921815418296&g_mp=CiVnb29nbGUubWFwcy5wbGFjZXMudjEuUGxhY2VzLkdldFBsYWNlEAMYASAF&hl=en&gl=PH&source=embed'),
    ],

];

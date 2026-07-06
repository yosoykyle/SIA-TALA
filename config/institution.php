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

];

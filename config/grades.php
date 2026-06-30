<?php

return [
    'servitech_v1' => [
        'key' => 'servitech_v1',
        'version' => 1,
        'formula' => [
            'prelim' => 0.30,
            'midterm' => 0.30,
            'final' => 0.40,
        ],
        'passing_average' => 75.0,
        'passing_grade' => '3.00',
        'inc_deadline_days' => 365,
        'lapsed_inc_result' => '5.00',
        'scale' => [
            ['code' => '1.00', 'category' => 'Passing', 'min' => 98, 'max' => 100],
            ['code' => '1.25', 'category' => 'Passing', 'min' => 95, 'max' => 97.9999],
            ['code' => '1.50', 'category' => 'Passing', 'min' => 92, 'max' => 94.9999],
            ['code' => '1.75', 'category' => 'Passing', 'min' => 89, 'max' => 91.9999],
            ['code' => '2.00', 'category' => 'Passing', 'min' => 86, 'max' => 88.9999],
            ['code' => '2.25', 'category' => 'Passing', 'min' => 83, 'max' => 85.9999],
            ['code' => '2.50', 'category' => 'Passing', 'min' => 80, 'max' => 82.9999],
            ['code' => '2.75', 'category' => 'Passing', 'min' => 77, 'max' => 79.9999],
            ['code' => '3.00', 'category' => 'Passing', 'min' => 75, 'max' => 76.9999],
            ['code' => '5.00', 'category' => 'Failed', 'min' => 0, 'max' => 74.9999],
        ],
        'temporary_outcomes' => [
            'P' => 'Pending Grade',
            'INC' => 'Incomplete',
        ],
    ],
];

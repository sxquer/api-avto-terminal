<?php

return [
    'client_id' => env('AMOCRM_CLIENT_ID'),
    'client_secret' => env('AMOCRM_CLIENT_SECRET'),
    'subdomain' => env('AMOCRM_SUBDOMAIN'),
    'long_lived_token' => env('AMOCRM_LONG_LIVED_TOKEN'),
    
    // Тестовый режим для API метода updateDtStatus
    // В тестовом режиме всегда используется сделка с ID 25147637
    'test_mode' => env('AMOCRM_TEST_MODE', false),
    
    'fields' => [
        'vin_field_id' => [
            'id' => 808681
        ],

        'color_field_id' => [
            'id' => 974799,
            'values' => [
                "Синий" => 1231085,
                "Красный" => 1231087,
                "Зеленый" => 1231089
            ]
        ] ,
        
        'nomer_dt' => [
            'id' => 979423
        ],

        'status_dt' => [
            'id' =>  979425,
            'values' => [
                "регистрация ПТД" => 1234079,
                "выпуск без уплаты (10)" => 1234081,
                "требуется уплата (31)" => 1234083,
                "выпуск с уплатой (32)" => 1234085,
                "выпуск разрешен, ожидание решения по временному ввозу (33)" => 1234087,
                "отказ в разрешении (40)" => 1234089,
                "выпуск товаров аннулирован при отзыве ПТД (50)" => 1234091,
                "отказ в выпуске товаров (90)"=> 1234093
            ],
        ],

        'registration_date' => [
            'id' => 979427
        ],

        'vipusk_date' => [
            'id' => 979429
        ],

        'refuse_date' => [
            'id' => 979431
        ],

        'history' => [
            'id' => 979433
        ],

    ],

    'statuses' => [
        'ptd/dt' => 62360974,
        'vipusk' => 81192786,
        'svh' => 64976646
    ],
];

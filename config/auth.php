<?php

return [

    'defaults' => [
        'guard' => 'api',
        'passwords' => 'users',
    ],

    'guards' => [
        'api' => [
            'driver' => 'jwt',              //  ต้องเป็น jwt
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,   // ชี้ไปที่ model ที่สร้างเมื่อกี้
            // 'model' => App\Models\LoginSuccess::class,   // ชี้ไปที่ model ที่สร้างเมื่อกี้
        ],
    ],

];

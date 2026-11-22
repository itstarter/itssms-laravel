<?php

return [
    'api_key' => env('ITSSMS_API_KEY'),
    'sender' => env('ITSSMS_SENDER'),
    'base_url' => env('ITSSMS_BASE_URL', 'https://my.itstarter.cc/api/v1'),
    'timeout' => 15,
    'max_retries' => 2,
    'webhook' => [
        'secret' => env('ITSSMS_WEBHOOK_SECRET'),
        'path' => null,
        'tolerance' => 300,
    ],
    'accounts' => [
        // 'teamcomrade' => ['api_key' => env('TEAMCOMRADE_ITSSMS_API_KEY'), 'sender' => 'TeamComrade'],
    ],
];

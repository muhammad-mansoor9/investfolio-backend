<?php

return [
    'api_key' => env('PADDLE_API_KEY'),
    'webhook_secret' => env('PADDLE_WEBHOOK_SECRET'),
    'environment' => env('PADDLE_ENVIRONMENT', 'sandbox'),
];

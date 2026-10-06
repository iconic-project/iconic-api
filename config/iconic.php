<?php

declare(strict_types=1);

return [

    'panel_url' => env('FRONTEND_PANEL_URL', 'http://localhost:3001'),

    'engine_url' => env('FRONTEND_ENGINE_URL', 'http://localhost:3000'),

    'portal_url' => env('FRONTEND_PORTAL_URL', 'http://localhost:3002'),

    'business_timezone' => 'Pacific/Galapagos',

    'report_attachment_bytes' => 7_340_032,

    'inbox' => [
        'driver' => env('INBOX_DRIVER', 'mailpit'),
        'mailpit_url' => env('MAILPIT_URL', 'http://mailpit:8025'),
        'page_size' => 50,
    ],

];

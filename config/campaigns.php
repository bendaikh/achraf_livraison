<?php

return [
    /*
    | Force fake WhatsApp sender (no Graph API calls). Automatically true in testing.
    */
    'fake_sender' => (bool) env('CAMPAIGNS_FAKE_SENDER', false),

    'defaults' => [
        'batch_size' => 25,
        'rate_limit_per_minute' => 30,
        'max_campaigns_per_client' => 3,
        'max_campaigns_window_days' => 7,
        'exclude_refused_consent' => true,
        'require_allowed_consent_for_marketing' => false,
    ],
];

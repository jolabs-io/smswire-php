<?php

return [
    // The project's API key (smswire_live_…) and slug (the part after /p/ in its endpoint).
    'key' => env('SMSWIRE_KEY'),
    'project' => env('SMSWIRE_PROJECT'),

    // Your server, if you host SMSWire yourself.
    'url' => env('SMSWIRE_URL', 'https://api.smswire.app'),

    // Signing secret (whsec_…) for the VerifySMSWireSignature middleware.
    'webhook_secret' => env('SMSWIRE_WEBHOOK_SECRET'),

    'max_retries' => 2,
    'timeout' => 30,
];

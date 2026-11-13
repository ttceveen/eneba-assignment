<?php

return [
    // Independent of APP_KEY. Keep stable and secret; rotation requires a planned backfill.
    'key_fingerprint_secret' => env('DIGITAL_KEY_FINGERPRINT_SECRET'),
];

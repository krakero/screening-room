<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Trusted Reverse Proxies
    |--------------------------------------------------------------------------
    |
    | Only list the immediate proxies that are allowed to supply trusted
    | X-Forwarded-* headers. Use a comma-separated list of IP addresses or
    | CIDR ranges. Do not add an entire private network for convenience.
    |
    */
    'proxies' => env('TRUSTED_PROXIES', '127.0.0.1,::1'),
];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Screening Room is usually reached through something that terminates
    | HTTPS in front of the container: Tailscale Funnel/Serve, Cloudflare
    | Tunnel, Caddy, Nginx, Traefik, etc. Those proxies talk to the app over
    | plain HTTP and describe the original request in X-Forwarded-* headers.
    | Laravel only honours those headers from proxies listed here; otherwise
    | it thinks the request was HTTP and builds http:// asset URLs, which
    | browsers block as mixed content on an https:// page.
    |
    | The default trusts loopback and private address ranges, which covers a
    | proxy on the same host (it reaches the container from Docker's gateway
    | address) or on the same LAN. Set TRUSTED_PROXIES to "*" to trust any
    | caller, or to a comma-separated list of IPs/CIDRs to narrow it down.
    |
    */

    'proxies' => env(
        'TRUSTED_PROXIES',
        '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,100.64.0.0/10,fc00::/7'
    ),

];

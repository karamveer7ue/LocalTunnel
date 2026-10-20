<?php

// Copy this file to config.php and fill in your own token.

return [
    // Shared secret for tunnel.py and for signing in to the dashboard.
    // Use at least 16 random characters, for example the output of:
    //   php -r 'echo bin2hex(random_bytes(24)), PHP_EOL;'
    'token' => 'replace-with-a-long-random-secret',

    // Optional. Serve tunnels on their own subdomains, like https://myapp.tunnel.example.com.
    // Needs a wildcard DNS record (*.tunnel.example.com) pointing at this server, and that
    // wildcard host served from this same directory. Without it, tunnels are still
    // reachable at https://<this server>/t/<name>/.
    'base_domain' => null,

    // Optional. The public address of this app, used in the links shown to clients.
    // Detected from each request when left empty.
    'public_url' => null,

    // Optional. The URL path this app is installed under, like '/tunnel'. Detected
    // automatically; set it only if links come out wrong.
    'base_path' => null,

    // Where tunnels, queued requests, and request bodies are kept. PHP must be able to
    // write here. Placing it outside the web root is safest.
    'data_dir' => __DIR__ . '/data',

    // Set to true when this app sits behind a reverse proxy or CDN (Cloudflare, nginx,
    // a load balancer) that sets X-Forwarded-For and X-Forwarded-Proto.
    'trust_proxy' => false,

    // Seconds to wait for your local app to start answering a request, and the longest
    // pause allowed between two pieces of a streamed response. Keep it below your web
    // server's own timeout (often 60 seconds) so visitors get Tunnel's explanation.
    'response_timeout' => 50,

    // Largest request body (an upload, say) a visitor may send through a tunnel, in MB.
    // Bodies are kept on disk while they pass through, so this also caps disk use.
    'max_upload_mb' => 1024,
];

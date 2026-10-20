# LocalTunnel

Share the ports on your computer through your own web server, like ngrok, but self-hosted and free.

You run a small Python script on your computer, put a small PHP app on any web host, and your local app becomes reachable at an address like `https://tunnel.example.com/t/myapp/` or `https://myapp.tunnel.example.com/`.

- **Server:** a PHP app that runs on ordinary PHP hosting, shared hosting included.
  It needs no daemon, no root access, no Composer, and no extensions beyond `pdo_sqlite`, which nearly every host has.
- **Client:** `tunnel.py`, a single Python file that uses only the standard library.
  It runs on macOS, Linux, and Windows with Python 3.8 or newer.
- **Dashboard:** a web page on the server that shows open tunnels and live requests, and lets you close tunnels.

The server's pages and the dashboard call the app simply "Tunnel".

## Contents

- [What you can do with it](#what-you-can-do-with-it)
- [How it works](#how-it-works)
- [Requirements](#requirements)
- [Try it on your computer](#try-it-on-your-computer)
- [Put the server online](#put-the-server-online)
  - [Option A: shared hosting (cPanel, Plesk, DirectAdmin)](#option-a-shared-hosting-cpanel-plesk-directadmin)
  - [Option B: your own server with nginx](#option-b-your-own-server-with-nginx)
  - [Option C: your own server with Apache](#option-c-your-own-server-with-apache)
  - [Subdomain tunnels](#subdomain-tunnels)
  - [Behind Cloudflare or another proxy](#behind-cloudflare-or-another-proxy)
- [Use the client](#use-the-client)
- [Use the dashboard](#use-the-dashboard)
- [What your app sees](#what-your-app-sees)
- [Configuration reference](#configuration-reference)
- [Limits](#limits)
- [Security](#security)
- [Troubleshooting](#troubleshooting)
- [Under the hood](#under-the-hood)
- [Development](#development)
- [Project layout](#project-layout)

## What you can do with it

- Show a website or app you are building to someone else, from your laptop, without deploying it.
- Receive webhooks (Stripe, GitHub, Slack, payment gateways) on your local machine while you develop.
- Test your site on a phone or tablet over the internet.
- Reach a service on your home network (a NAS, a Raspberry Pi, a router page) from anywhere, protected by a password.
- Share several local ports at once, each under its own name.

## How it works

Hosted tunnels like ngrok keep a permanent connection open between your computer and their servers.
Plain PHP hosting cannot do that: PHP only runs while it answers a request.
LocalTunnel turns the connection around, so your computer does all the reaching out:

```
                       1. visitor asks for a page
  visitor's browser  ─────────────────────────────▶  PHP server  (your web host)
                                                         │
                                                         │ 2. the request is stored in a queue
                                                         ▼
                                                   SQLite + spool files
                                                         ▲
                       3. tunnel.py asks "anything for me?" (long poll) and picks it up
  tunnel.py on your  ────────────────────────────────────┘
  computer           ─────▶  4. forwards it to http://localhost:3000
                     ◀─────     and reads the answer
                     ─────────────────────────────────────▶ 5. uploads the answer in pieces
                                                         │
  visitor's browser  ◀─────────────────────────────────── 6. the server streams the pieces
                                                            to the visitor as they arrive
```

Step by step:

1. A visitor opens `https://tunnel.example.com/t/myapp/products?page=2`.
   The web server hands the request to `index.php`, like every other request.
2. The server looks up the tunnel `myapp`.
   It checks that the tunnel is online and, if the tunnel has a password, that the visitor sent it.
   It saves the request body to a file, records the request in its SQLite database as "queued", and waits.
3. `tunnel.py` keeps one request open to the server at all times (a long poll).
   The server answers it the moment work arrives, handing over up to 32 queued requests at once.
   Small request bodies travel inside that answer; large ones are downloaded separately.
4. `tunnel.py` sends each request to your local app, for example `http://localhost:3000/products?page=2`, from a pool of worker threads.
5. As the answer comes back from your app, `tunnel.py` uploads it to the server in pieces of up to 512 KB.
   Whatever arrives while one piece is uploading goes out with the next, so streams stay responsive and big downloads travel in big pieces.
   A small answer usually goes up in a single call.
6. The waiting PHP request sends the status and headers to the visitor as soon as they arrive, then streams each piece as it lands.

This round trip adds about 15 ms on a local machine, plus the network distance between your computer and the server.

Some details that make it robust:

- **Streaming end to end.** Bodies are never held in memory on the server; they pass through files in a spool folder.
  Server-sent events and other slow streams reach the visitor piece by piece.
- **Backpressure.** If a visitor downloads slowly, the server stops accepting new pieces once 8 MB is waiting, and `tunnel.py` pauses.
  Disk use stays bounded even for multi-gigabyte downloads.
- **Retries without duplicates.** Every uploaded piece carries a sequence number, so a piece retried after a network hiccup is recognized and never stored twice.
- **Reconnects.** If the connection to the server drops (Wi-Fi change, laptop sleep), `tunnel.py` keeps retrying with growing pauses and re-registers its tunnels under the same names.
- **Clean shutdown.** Ctrl+C, `kill`, or closing the terminal window takes the tunnels offline at once, so visitors see "Tunnel not found" instead of waiting.
- **Housekeeping.** Once a minute, the server cleans up after crashed PHP workers, trims the request log, and forgets old tunnels, without any cron job.

## Requirements

**Server**

- PHP 8.1 or newer with the `pdo_sqlite` extension.
- A web server that sends every request to `index.php`: Apache with `.htaccess` (`AllowOverride All`), nginx, LiteSpeed, or PHP's built-in server for local testing.
- A folder PHP can write to (the data directory).
- HTTPS for anything beyond local testing.

**Client**

- Python 3.8 or newer.
  macOS and most Linux distributions include it; on Windows, install it from python.org.
- Nothing else: no `pip install`.

## Try it on your computer

You can run the whole thing on one machine in a few minutes, before involving any hosting.
You need PHP 8.1+ and Python 3.8+ (on macOS: `brew install php`).

**1. Get the code.**

```sh
git clone https://github.com/karamveer7ue/LocalTunnel.git
cd LocalTunnel
```

**2. Create the configuration with a secret token.**

```sh
cp server/config.example.php server/config.php
php -r 'echo bin2hex(random_bytes(24)), PHP_EOL;'     # prints a random token
```

Open `server/config.php` and put that token in place of `replace-with-a-long-random-secret`.
To also try subdomain tunnels locally, set `'base_domain' => 'localhost'`.

**3. Start the server.**

```sh
PHP_CLI_SERVER_WORKERS=16 php -d enable_post_data_reading=0 -S 127.0.0.1:8080 server/index.php
```

- `PHP_CLI_SERVER_WORKERS=16` lets PHP's built-in server handle several requests at once, which tunnels need.
- `-d enable_post_data_reading=0` lets uploads pass through untouched (on real hosting, the bundled `.user.ini` does this).

Open http://127.0.0.1:8080/ to see the dashboard sign-in page.

**4. Start something to share.**
Any local web app works.
If you have none at hand, this serves the current folder on port 3000:

```sh
python3 -m http.server 3000
```

**5. Connect the client** (in another terminal):

```sh
python3 client/tunnel.py login http://127.0.0.1:8080    # paste the token when asked
python3 client/tunnel.py myapp=3000
```

You will see something like:

```
Tunnel  connected to http://127.0.0.1:8080

  myapp  http://myapp.localhost:8080/  →  http://localhost:3000
         http://127.0.0.1:8080/t/myapp/

Ctrl+C stops sharing.

18:04:52  GET      200      6 ms      16 B  /
```

**6. Visit your app through the tunnel** at http://127.0.0.1:8080/t/myapp/, or at http://myapp.localhost:8080/ if you set `base_domain` (browsers send every `*.localhost` name to your own computer).
Each request appears in the terminal and on the dashboard.

PHP's built-in server is meant for trying things out; for real use, put the server on a proper web server as described next.

## Put the server online

Pick the option that matches your hosting.
In every case, the files to put online are the **contents of the `server` folder**.
Copy the `client` folder next to it as well if you want the dashboard to offer `tunnel.py` for download.

### Option A: shared hosting (cPanel, Plesk, DirectAdmin)

This works on typical PHP hosting where you have a control panel and a file manager or FTP, but no command line.

**1. Check the PHP version.**
In the control panel, find "Select PHP Version", "MultiPHP Manager", or similar, and choose PHP 8.1 or newer.
Make sure the `pdo_sqlite` extension is ticked (it usually is).

**2. Create a subdomain for the tunnel server**, for example `tunnel.example.com`.
In cPanel: Domains (or Subdomains), create `tunnel`, and note its document root, for example `/home/you/tunnel.example.com`.
A dedicated subdomain is best.
Installing into a folder of an existing site, such as `example.com/tunnel/`, also works.

**3. Upload the files.**
Upload everything inside the `server` folder into the subdomain's document root, so that `index.php` sits directly in it.
Include the hidden files `.htaccess` and `.user.ini`; in cPanel's File Manager, turn on "Show Hidden Files" to check they arrived.

The result should look like this:

```
tunnel.example.com/          (document root)
├── .htaccess
├── .user.ini
├── index.php
├── config.example.php
├── assets/
├── src/
└── views/
```

**4. Create the configuration.**
Open the subdomain in your browser.
Because there is no `config.php` yet, you get a setup page with a freshly generated token.
In the file manager, copy `config.example.php` to `config.php`, edit it, and paste that token into `'token' => '...'`.

**5. Turn on HTTPS.**
Most hosts issue certificates automatically (cPanel: SSL/TLS Status, "Run AutoSSL").
Wait until `https://tunnel.example.com` loads without a warning.

**6. Sign in.**
Reload the page, sign in with the token, and follow "Connect a computer" on the dashboard.

**7. Check the dashboard's notes.**
If PHP still parses uploads itself because the host ignores `.user.ini`, the dashboard says so.
Uploads still work in that case, but files are re-encoded and limited by the host's `upload_max_filesize`.
`.user.ini` changes can take up to 5 minutes to apply.

Optional: the data directory.
By default, LocalTunnel keeps its database and temporary files in a `data` folder inside the document root, protected by an automatically written `.htaccess`.
If your account has a folder outside the web root (like `/home/you/tunnel-data`), set `'data_dir'` in `config.php` to it.

### Option B: your own server with nginx

These steps are for Ubuntu or Debian; other distributions differ only in package names and paths.

**1. Install nginx, PHP-FPM, and SQLite support.**

```sh
sudo apt update
sudo apt install nginx php-fpm php-sqlite3 certbot python3-certbot-nginx
php -v                          # must be 8.1 or newer
ls /run/php/                    # note the socket name, for example php8.3-fpm.sock
```

**2. Put the code on the server.**

```sh
sudo git clone https://github.com/karamveer7ue/LocalTunnel.git /var/www/tunnel
sudo cp /var/www/tunnel/server/config.example.php /var/www/tunnel/server/config.php
sudo mkdir -p /var/lib/tunnel
sudo chown www-data:www-data /var/lib/tunnel
```

Edit `/var/www/tunnel/server/config.php`: set the token, and set `'data_dir' => '/var/lib/tunnel'` to keep data outside the web root.

**3. Point a domain at the server.**
Create a DNS `A` record for `tunnel.example.com` with your server's IP address (and an `AAAA` record for IPv6 if you use it).

**4. Get a certificate.**

```sh
sudo certbot certonly --nginx -d tunnel.example.com
```

For subdomain tunnels you need a wildcard certificate instead; see [Subdomain tunnels](#subdomain-tunnels).

**5. Install the site configuration.**

```sh
sudo cp /var/www/tunnel/deploy/nginx.conf /etc/nginx/sites-available/tunnel
sudo nano /etc/nginx/sites-available/tunnel     # set your domain, paths, and PHP-FPM socket
sudo ln -s /etc/nginx/sites-available/tunnel /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

[deploy/nginx.conf](deploy/nginx.conf) sends every request to `index.php`, turns off response buffering so streams flow, raises timeouts for slow apps, and lets LocalTunnel enforce the upload limit itself.

**6. Give PHP enough workers.**
Every request waiting on your computer occupies one PHP worker.
In `/etc/php/8.3/fpm/pool.d/www.conf` (adjust the version), raise `pm.max_children` to at least 20, then `sudo systemctl restart php8.3-fpm`.

**7. Open `https://tunnel.example.com`**, sign in, and connect your computer.

### Option C: your own server with Apache

**1. Install Apache, PHP-FPM, and SQLite support.**

```sh
sudo apt update
sudo apt install apache2 php-fpm php-sqlite3 certbot python3-certbot-apache
sudo a2enmod ssl rewrite proxy proxy_fcgi env
```

**2. Put the code on the server, and create the configuration**, exactly as in steps 2 to 4 of Option B (use `certbot certonly --apache` for the certificate).

**3. Install the site configuration.**

```sh
sudo cp /var/www/tunnel/deploy/apache.conf /etc/apache2/sites-available/tunnel.conf
sudo nano /etc/apache2/sites-available/tunnel.conf   # set your domain, paths, and PHP-FPM socket
sudo a2ensite tunnel
sudo apachectl configtest && sudo systemctl reload apache2
```

[deploy/apache.conf](deploy/apache.conf) hands PHP files to PHP-FPM and sets `flushpackets=on`, without which Apache holds back small streamed responses until they finish.
The `.htaccess` in the `server` folder does the rest: it routes every request to `index.php` and passes the `Authorization` header and chunked uploads through to PHP.

**4. Give PHP enough workers** (step 6 of Option B), then open `https://tunnel.example.com`.

### Subdomain tunnels

By default every tunnel lives under a path: `https://tunnel.example.com/t/myapp/`.
That works everywhere and suits APIs and webhooks perfectly.

Web apps, however, often link to absolute paths like `/assets/app.js` or `/login`, expecting to sit at the root of a host.
LocalTunnel rewrites redirects and cookies to stay under `/t/myapp/`, and when the server is installed at the root of its host, it also redirects stray absolute links back into the right tunnel, based on the page they came from.
That covers most apps, but giving each tunnel its own host is the reliable way:

1. **DNS:** add a wildcard record `*.tunnel.example.com` pointing to your server (an `A` record, or a `CNAME` to `tunnel.example.com`).
2. **Web server:** serve `*.tunnel.example.com` from the same folder as `tunnel.example.com`.
   - cPanel: create a subdomain named `*` (under the parent domain `tunnel.example.com`, or `*.example.com` style, depending on the panel) with the same document root.
   - nginx and Apache: the configurations in `deploy/` already include `*.tunnel.example.com`.
3. **Certificate:** you need a wildcard certificate for `*.tunnel.example.com`.
   With Let's Encrypt, wildcard certificates require a DNS challenge:

   ```sh
   sudo certbot certonly --manual --preferred-challenges dns \
     -d tunnel.example.com -d '*.tunnel.example.com'
   ```

   Certbot asks you to add a TXT record to your DNS.
   A DNS plugin for your provider (for example `python3-certbot-dns-cloudflare`) makes renewals automatic.
   Some cPanel hosts' AutoSSL also covers wildcard subdomains.
4. **Configuration:** set `'base_domain' => 'tunnel.example.com'` in `config.php`.

Tunnels then answer at `https://myapp.tunnel.example.com/` as well, and `tunnel.py` and the dashboard list that address first.
The name `www` is reserved and keeps showing the dashboard.

**No domain at all?**
Services like [sslip.io](https://sslip.io) give any IP address a name with wildcard subdomains: `1-2-3-4.sslip.io` and `myapp.1-2-3-4.sslip.io` both resolve to `1.2.3.4`.
Use `1-2-3-4.sslip.io` as your server name and `base_domain`.

### Behind Cloudflare or another proxy

If a CDN, load balancer, or reverse proxy sits in front of the server, set `'trust_proxy' => true`.
LocalTunnel then takes the visitor's IP address from `X-Forwarded-For` and the scheme from `X-Forwarded-Proto`, so logs show real visitors and links use `https`.
Only enable it when such a proxy is really in front, since otherwise visitors could fake those headers.

Cloudflare's proxy works with both path and subdomain tunnels (it supports wildcard DNS records) and closes requests after 100 seconds, which is above LocalTunnel's default timeout.

## Use the client

Copy `client/tunnel.py` to the computer whose ports you want to share.
If the `client` folder was deployed next to `server`, the dashboard offers it for download, and you can fetch it with:

```sh
curl -fsSLO https://tunnel.example.com/_tunnel/tunnel.py
```

### Sign in once

```sh
python3 tunnel.py login https://tunnel.example.com
```

It asks for the token (you can also pass it as a second argument, but then it ends up in your shell history), checks it with the server, and stores the server address and token in `~/.config/tunnel/config.json` (or `$XDG_CONFIG_HOME/tunnel/config.json`), readable only by you.
`python3 tunnel.py logout` deletes that file.

On Windows, use `py tunnel.py ...` or `python tunnel.py ...` instead of `python3`.

### Share ports

```sh
python3 tunnel.py 3000                          # localhost:3000 under a random name, like brave-otter-42
python3 tunnel.py myapp=3000                    # choose the name
python3 tunnel.py web=3000 api=8080             # several tunnels at once
python3 tunnel.py nas=192.168.1.20:5000         # another machine on your network
python3 tunnel.py dev=https://localhost:8443    # a local HTTPS server (its certificate is not checked)
```

Each argument is a target:

| Target | Meaning |
| --- | --- |
| `PORT` | `http://localhost:PORT`, under a random name. |
| `NAME=PORT` | `http://localhost:PORT`, under `NAME`. |
| `NAME=HOST:PORT` | `http://HOST:PORT`, for other machines or `127.0.0.1`. |
| `NAME=http://HOST:PORT` or `NAME=https://HOST:PORT` | The same with an explicit scheme. |

Names use lowercase letters, digits, and dashes (up to 63 characters), because they double as subdomains.

### Options

| Option | Effect |
| --- | --- |
| `--auth USER:PASSWORD` | Visitors must sign in with this username and password (HTTP Basic authentication), checked by the server before anything reaches your computer. |
| `--host-header rewrite` | Send your app `Host: localhost:3000` instead of the public host name. Use it for dev servers that reject unknown hosts, such as Vite ("Blocked request. This host is not allowed"). The public name stays available in `X-Forwarded-Host`. |
| `--server URL` | Use another server than the saved one (also `$TUNNEL_SERVER`). |
| `--token TOKEN` | Use another token than the saved one (also `$TUNNEL_TOKEN`). |
| `--workers N` | Requests handled at the same time (default 16). |
| `--insecure` | Accept an invalid HTTPS certificate on the tunnel server, for testing. |
| `-q`, `--quiet` | Do not print each request. |
| `--version` | Print the version. |

### What it prints

```
Tunnel  connected to https://tunnel.example.com

  web  https://web.tunnel.example.com/  →  http://localhost:3000
       https://tunnel.example.com/t/web/
  api  https://api.tunnel.example.com/  →  http://localhost:8080
       https://tunnel.example.com/t/api/

Ctrl+C stops sharing.

18:04:52  web  GET      200      6 ms      16 B  /
18:04:52  web  GET      404      3 ms     469 B  /nope.css
18:04:53  api  POST     502      2 ms         -  /orders  Nothing is listening on localhost:8080.
```

Each request line shows the time, the tunnel (when there are several), the method, the status, how long it took, the size of the response, and the path.
Problems appear in red at the end of the line.

### Behavior worth knowing

- **Reconnecting.** When the connection to the server drops, `tunnel.py` prints "Lost the connection to the server" once, retries with pauses that grow up to 30 seconds, and prints "Reconnected" when it is back.
  After a long sleep the server may have forgotten the tunnels; `tunnel.py` then registers them again under the same names.
- **Moving a tunnel.** Starting the same name on another computer (or in another terminal) moves the tunnel there; the first `tunnel.py` reports it and stops serving that name.
- **Closing from the dashboard.** If you close a tunnel on the dashboard, `tunnel.py` reports it and stops serving it; when nothing is left, it exits.
- **Stopping.** Ctrl+C, `kill`, or closing the terminal takes the tunnels offline immediately.
- **Your app is down.** Visitors get a clear "Bad gateway" page saying nothing is listening on the port, and `tunnel.py` keeps running, so starting your app again is enough.

## Use the dashboard

Open the server's address (for example `https://tunnel.example.com/`) and sign in with the token.
The session lasts 30 days.

- **Tunnels:** every open tunnel with its addresses (with copy buttons), the local target and computer it comes from, whether it is online, whether it has a password, and its numbers: requests, data received and sent, requests in flight, and how long it has been open.
  The Close button takes a tunnel offline and tells its `tunnel.py` to stop serving it.
- **Requests:** the most recent 200 requests across all tunnels, updated every two seconds, with time, tunnel, method, path, status, duration, and size.
  Failed requests show the reason underneath.
  With several tunnels, chips at the top filter by tunnel.
- **Connect a computer:** the exact commands to download `tunnel.py`, sign in, and share a port, with copy buttons.
- **Notes:** warnings about the server setup, such as plain HTTP or PHP settings that the host did not apply.

The dashboard follows your system's light or dark mode and works on phones.

## What your app sees

Requests arrive at your app the way a reverse proxy would deliver them:

- The original method, path, query string, headers, and body, byte for byte.
- `Host`: the public host name (like `myapp.tunnel.example.com`), unless you use `--host-header rewrite`.
- `X-Forwarded-For`: the visitor's IP address.
- `X-Forwarded-Host`: the public host name.
- `X-Forwarded-Proto`: `https` or `http`, as the visitor used.
- `X-Forwarded-Prefix`: the path prefix for path-based tunnels, like `/t/myapp`.
- For path-based tunnels the prefix is removed: a visit to `/t/myapp/login` reaches your app as `/login`.
- On password-protected tunnels, the visitor's `Authorization` header is removed, because that password belongs to the tunnel and not to your app.

On the way back, LocalTunnel:

- passes the status, headers, and body through unchanged, including compressed bodies and multiple `Set-Cookie` headers;
- rewrites redirects that point at your local address (like `http://localhost:3000/dashboard`) to the public address;
- for path-based tunnels, keeps redirects (`/login` becomes `/t/myapp/login`) and cookie paths inside the tunnel's prefix;
- drops a cookie `Domain` that the browser would reject on the public host (like `Domain=localhost`).

## Configuration reference

All settings live in `server/config.php`, a PHP file that returns an array.
[server/config.example.php](server/config.example.php) explains each one.
The server reads the file from `TUNNEL_CONFIG` instead when that environment variable is set.

| Setting | Default | Purpose |
| --- | --- | --- |
| `token` | (required) | Shared secret for `tunnel.py` and for signing in to the dashboard. At least 16 characters; use a long random one. |
| `base_domain` | none | Turns on subdomain tunnels, like `myapp.tunnel.example.com`. Needs wildcard DNS and a wildcard certificate. |
| `public_url` | detected | Public address of the server, used in links shown to clients. Set it if the server is reached under several names. |
| `base_path` | detected | URL path the app is installed under, like `/tunnel`. Set it only if links come out wrong. |
| `data_dir` | `server/data` | Where the SQLite database and spool files live. PHP must be able to write here. Outside the web root is safest. |
| `trust_proxy` | `false` | Trust `X-Forwarded-For` and `X-Forwarded-Proto` from a proxy or CDN in front of the server. |
| `response_timeout` | `50` | Seconds your app has to start answering, and the longest pause allowed in a streamed response. Keep it below your web server's own timeout, often 60 seconds. |
| `max_upload_mb` | `1024` | Largest request body a visitor may send through a tunnel. Bodies are kept on disk while they pass through, so this also caps disk use. |

Changes take effect on the next request; nothing needs restarting.
Changing the token signs everyone out and disconnects every `tunnel.py` until you run `login` again.

## Limits

- **No WebSockets.** Plain PHP cannot hold a connection open, so WebSocket upgrade requests get a clear "501 WebSockets are not supported" page.
  Regular requests, uploads, downloads, and streamed responses such as server-sent events work.
  Dev servers with live reload (Vite, webpack, Next.js) still serve their pages; only the live reload connection fails.
- **HTTP only.** Raw TCP services such as SSH, databases, or game servers cannot be tunneled.
- **One PHP worker per request in flight.** A visitor's request keeps a PHP worker busy until your app answers.
  A page that loads 40 files at once needs enough workers (`pm.max_children` for PHP-FPM, or your host's process limit), or some files wait their turn.
- **Streaming on Apache with PHP-FPM** needs `flushpackets=on` in the server configuration ([deploy/apache.conf](deploy/apache.conf) has it).
  On shared hosts that do not set it, small streamed responses arrive all at once when they finish; large downloads are unaffected.
- **Apache with PHP-FPM drops `Content-Length`** from PHP responses and sends them chunked, so browsers may not know a download's total size in advance.
- **Apache refuses encoded slashes (`%2F`) in paths** unless the server sets `AllowEncodedSlashes`.
- **Timeouts.** Your app has `response_timeout` seconds (default 50) to start answering; your web server and any proxy in front have their own limits.
- **Uploads** are limited to `max_upload_mb` (default 1 GB).
- **Latency.** Each request costs the trip from the visitor to the server, plus the trip between the server and your computer, plus about 15 ms of processing.

## Security

- **The token is the key to everything.**
  Anyone who has it can open tunnels on your server and use the dashboard.
  Use a long random token, keep it out of shared files, and serve the server over HTTPS so it never travels in the clear.
- **Tunnels are public.**
  Anyone who learns or guesses a tunnel's address can reach your local app through it.
  Protect anything sensitive with `--auth USER:PASSWORD`; the server checks the password before the request reaches your computer.
  Random names are easy to read, not secret.
- **The dashboard** signs you in with an HTTP-only, same-site cookie scoped to its API, sends a strict Content Security Policy, refuses cross-site requests, and slows down repeated wrong tokens.
- **Path-based tunnels share an origin with the dashboard** (same scheme, host, and port), so in principle a page served through a tunnel could talk to the dashboard's API in your browser.
  Subdomain tunnels give each app its own origin; prefer them for apps you do not fully trust.
- **Stored data:** the server stores tunnel names, the computer names and IP addresses of clients, and a log of recent requests (method, path, status, visitor IP).
  Request and response bodies exist on disk only while they pass through.
  Tunnel passwords are stored only as keyed hashes.
- **Data directory:** keep `data_dir` outside the web root when you can.
  Inside it, LocalTunnel writes a deny-all `.htaccess` (honored by Apache) and routes every request through `index.php`, so the files are not served.

## Troubleshooting

**The browser shows "Tunnel not found" (404).**
No tunnel with that name is open.
Check the name in the address and that `tunnel.py` is running and shows it in its banner.

**"Tunnel offline" (503).**
The tunnel exists, but its computer has not checked in for 45 seconds.
`tunnel.py` was stopped without a clean exit, or its computer lost the network or went to sleep.
Start it again, or wait for it to reconnect.

**"Bad gateway: Nothing is listening on localhost:3000" (502).**
`tunnel.py` is connected, but your app is not running on that port.
Start your app, or check the port.
If your app only listens on IPv4 or IPv6, try `127.0.0.1:3000` or `[::1]:3000` as the target.

**"Gateway timeout" (504).**
Your app took longer than `response_timeout` to start answering.
Raise the setting if your app is legitimately slow (and the web server's timeout with it).

**Vite or another dev server says "Blocked request. This host is not allowed".**
Run `tunnel.py` with `--host-header rewrite`, or add the public host name to the dev server's allowed hosts.

**`tunnel.py` says "The server rejected the token".**
The token changed or was mistyped.
Run `python3 tunnel.py login https://tunnel.example.com` again.

**`tunnel.py` says "CERTIFICATE_VERIFY_FAILED".**
The server's HTTPS certificate is not valid (expired, self-signed, or for another name).
Fix the certificate; `--insecure` bypasses the check for testing.
With Python from python.org on macOS, also run "Install Certificates.command" from the Python folder in Applications.

**Every tunnel address shows the host's own 404 page.**
The web server is not routing requests to `index.php`.
On Apache, `.htaccess` must be uploaded and allowed (`AllowOverride All`).
As a quick check, `https://tunnel.example.com/index.php/t/myapp/` works even without rewriting.

**The page says "Storage unavailable".**
PHP cannot write to the data directory, or `pdo_sqlite` is missing.
The message says which; fix the folder permissions or enable the extension.

**Uploads of files fail or arrive changed.**
The host ignores `.user.ini`, and PHP parses uploads itself (the dashboard shows a note).
Ask the host to set `enable_post_data_reading = Off` for the site, or raise `upload_max_filesize` and `post_max_size`.

**Streamed responses (server-sent events) arrive all at once.**
On Apache with PHP-FPM, the server configuration needs `flushpackets=on`; see [Limits](#limits).
Proxies that buffer responses have the same effect.

**Some files on a busy page load slowly or time out.**
The server is running out of PHP workers; raise `pm.max_children` (or ask your host about process limits).

**Where are the logs?**
`tunnel.py` prints every request.
The dashboard keeps the last 1000 requests and shows the latest 200.
PHP errors go to the PHP error log, never into responses.

## Under the hood

This section describes the internals, for the curious and for contributors.

### Addresses and routing

Every request enters `server/index.php`, which hands it to `App`:

| Request | Handled as |
| --- | --- |
| Any path on `<name>.<base_domain>` | Tunnel `<name>`, whole path forwarded. |
| `/t/<name>/...` | Tunnel `<name>`, path after the prefix forwarded. |
| `/t/<name>` | Redirect to `/t/<name>/`. |
| `/` | Dashboard page. |
| `/_tunnel/api/...` | Dashboard API. |
| `/_tunnel/client/...` | Client API, used by `tunnel.py`. |
| `/_tunnel/assets/...` | Dashboard script, styles, and icon. |
| `/_tunnel/tunnel.py` | Download of the client, when deployed alongside. |
| Any other path, coming from a page under `/t/<name>/` | Redirect into that tunnel (absolute links of apps under a path). |

Paths are relative to where the app is installed, so `example.com/tunnel/t/myapp/` works too.

### The client protocol

`tunnel.py` talks to the server over ordinary HTTPS requests, all under `/_tunnel/client/`, each carrying the token in an `X-Tunnel-Token` header and the protocol version in `X-Tunnel-Protocol`:

| Call | Purpose |
| --- | --- |
| `GET ping` | Check the token and get the server version (used by `login`). |
| `POST register` | Open tunnels: `{client_id, client_name, tunnels: [{name, target, auth}]}`. Returns each tunnel's granted name and public addresses, or an error. |
| `GET poll?client=ID&tunnels=a,b` | Long poll. Answers within 25 seconds with queued requests, tunnels that are gone (closed or moved), and tunnels the server has forgotten. Sends a space every second to keep proxies from cutting it off and to notice a client that hung up. |
| `GET body?id=ID` | Download a large request body. |
| `POST respond?id=ID&seq=N[&head=LEN][&end=1]` | Upload one piece of the response. The first piece starts with `LEN` bytes of JSON holding the status and headers, or an error message. |
| `POST unregister` | Take tunnels offline. |

### Request lifecycle

Each visitor request is a row in the `requests` table and moves through these states:

```
queued ──▶ claimed ──▶ streaming ──▶ done
  │           │            │
  └───────────┴────────────┴──▶ failed      (timeout, app unreachable, tunnel closed)
                           └──▶ abandoned   (the visitor left)
```

- **queued:** waiting for `tunnel.py` to pick it up; after 30 seconds the visitor gets "Tunnel not responding".
- **claimed:** `tunnel.py` has it; after `response_timeout` without an answer the visitor gets "Gateway timeout".
- **streaming:** the status and headers arrived and pieces flow; a pause longer than `response_timeout` ends it.
- **done**, **failed**, **abandoned:** settled; the tunnel's totals are updated and its files deleted.

The PHP worker serving the visitor checks the row every few milliseconds right after progress, backing off to 25 ms while nothing happens.

### Storage

- **SQLite** (`data/tunnel.sqlite`, in write-ahead logging mode where the file system allows it) holds the `tunnels` and `requests` tables.
  Writes take a lock up front, so concurrent PHP workers wait their turn instead of failing.
- **Spool files** (`data/spool/`) hold request bodies (`<id>.body`) and response pieces (`<id>.<seq>`).
  A piece is written under a temporary name and renamed into place before it is announced, so readers never see half a piece.
- **Housekeeping** runs at most once a minute inside a poll: it fails requests whose PHP worker died, keeps the newest 1000 finished requests, forgets tunnels offline for a day (closed ones after a week), and removes stray spool files older than six hours.

### Timings

| What | Value |
| --- | --- |
| Long poll duration | 25 seconds (1 second on PHP's built-in server) |
| A tunnel counts as offline after | 45 seconds without a poll |
| Queued request gives up after | 30 seconds |
| Your app must start answering within | `response_timeout` (50 seconds) |
| Response pieces | up to 512 KB |
| Request bodies sent inside the poll answer | up to 256 KB each, 2 MB per poll |
| Backpressure starts at | 8 MB waiting for a slow visitor |
| `tunnel.py` connection timeout to your app | 10 seconds |
| `tunnel.py` read timeout from your app | 300 seconds of silence |
| Reconnect pauses | 1, 2, 4, ... up to 30 seconds, with jitter |
| Dashboard refresh | every 2 seconds while visible |
| Dashboard session | 30 days |

### Error pages

When LocalTunnel itself answers instead of your app, the response carries an `X-Tunnel-Error` header, so programs can tell its errors from your app's.
Browsers get a small HTML page; other clients get plain text.

| `X-Tunnel-Error` | Status | Meaning |
| --- | --- | --- |
| `tunnel_not_found` | 404 | No open tunnel by that name. |
| `tunnel_offline` | 503 | The tunnel's computer is not connected. |
| `tunnel_closed` | 503 | The tunnel was closed while the request waited. |
| `unauthorized` | 401 | The tunnel needs a username and password. |
| `upgrade_unsupported` | 501 | WebSocket requests cannot be tunneled. |
| `body_too_large` | 413 | The upload exceeds `max_upload_mb`. |
| `length_required` | 411 | The web server did not pass a chunked upload on to PHP. |
| `pickup_timeout` | 504 | `tunnel.py` did not pick up the request. |
| `response_timeout` | 504 | Your app did not answer in time. |
| `local_unreachable` | 502 | `tunnel.py` could not reach your app. |

## Development

The server has no dependencies and no build step; edit the files and reload.
The dashboard is plain JavaScript and CSS in `server/assets/`.

### Tests

The end-to-end tests start a real PHP server, a real local app, and real `tunnel.py` processes, then exercise everything through HTTP: forwarding, both address styles, encoded paths, small, large, and chunked bodies, uploads, downloads, streaming, redirects and cookies, compression, passwords, timeouts, upload limits, offline and closed tunnels, moving tunnels, reconnects, Ctrl+C, the `login` command, and the dashboard API.

```sh
python3 -m unittest discover -s tests -v                          # on PHP's built-in server
TUNNEL_E2E_STACK=apache python3 -m unittest discover -s tests -v  # on Apache with PHP-FPM
TUNNEL_E2E_STACK=nginx python3 -m unittest discover -s tests -v   # on nginx with PHP-FPM
```

The Apache and nginx runs use the same settings as the files in `deploy/` and need `php-fpm` plus `httpd` or `nginx` installed.
They only use the Python standard library.
`KEEP_TUNNEL_E2E=1` keeps the temporary folder with all server logs for inspection.

### Static checks

```sh
uvx ruff check      # Python lint (settings in ruff.toml)
phpstan             # PHP static analysis at level 8 (settings in phpstan.neon)
```

Install PHPStan with `composer global require phpstan/phpstan`, and ruff with `pip install ruff` if you do not use `uvx`.

## Project layout

```
LocalTunnel/
├── client/
│   └── tunnel.py            the client: run it on your computer
├── server/                  the PHP app: put its contents on your web host
│   ├── index.php            single entry point for every request
│   ├── .htaccess            routing and settings for Apache
│   ├── .user.ini            PHP settings for PHP-FPM and CGI hosts
│   ├── config.example.php   copy to config.php and set the token
│   ├── src/
│   │   ├── App.php          routing
│   │   ├── Proxy.php        visitor requests: queue, wait, stream back
│   │   ├── ClientApi.php    the API tunnel.py talks to
│   │   ├── Dashboard.php    dashboard page, sign-in, and state API
│   │   ├── Store.php        SQLite database and spool files
│   │   ├── Request.php      the incoming request, normalized across servers
│   │   ├── Multipart.php    rebuilds uploads on hosts that parse them
│   │   └── ...              configuration, links, names, responses, views
│   ├── views/               dashboard shell, error page, setup page
│   └── assets/              dashboard script, styles, and icon
├── deploy/
│   ├── nginx.conf           site configuration for nginx with PHP-FPM
│   └── apache.conf          site configuration for Apache with PHP-FPM
├── tests/
│   └── test_e2e.py          end-to-end tests
├── phpstan.neon             PHP static analysis settings
└── ruff.toml                Python lint settings
```


<?php
/**
 * @var Tunnel\ConfigError $error
 * @var string $configPath
 * @var string $token a freshly generated suggestion
 */

use Tunnel\View;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="color-scheme" content="light dark">
<title>Set up Tunnel</title>
<style>
:root {
    --bg: #f7f7f8; --surface: #ffffff; --border: #e4e4e8; --text: #17171c; --muted: #6b6b76;
    --code: #f1f1f4; --accent: #4f46e5; --warn: #b45309; --warn-soft: #fdf4e3;
}
@media (prefers-color-scheme: dark) {
    :root {
        --bg: #0b0b0f; --surface: #131318; --border: #26262e; --text: #ececf1; --muted: #9393a0;
        --code: #1c1c23; --accent: #9496fa; --warn: #fbbf24; --warn-soft: #2e230c;
    }
}
*, *::before, *::after { box-sizing: border-box; }
html, body { margin: 0; }
body {
    display: grid; place-items: center; min-height: 100vh; padding: 32px 16px;
    background: var(--bg); color: var(--text);
    font: 15px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    -webkit-font-smoothing: antialiased;
}
main { width: 100%; max-width: 560px; }
.eyebrow { font: 600 12px/1 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; letter-spacing: .06em; color: var(--accent); }
h1 { margin: 12px 0 8px; font-size: 24px; line-height: 1.3; letter-spacing: -.015em; }
p { margin: 0; color: var(--muted); }
.problem { margin: 20px 0 0; padding: 10px 14px; border-radius: 10px; background: var(--warn-soft); color: var(--warn); font-size: 14px; overflow-wrap: anywhere; }
ol { margin: 24px 0 0; padding: 0; list-style: none; counter-reset: step; display: grid; gap: 16px; }
li { position: relative; padding-left: 36px; counter-increment: step; }
li::before {
    content: counter(step); position: absolute; left: 0; top: 0; width: 24px; height: 24px; border-radius: 50%;
    display: grid; place-items: center; background: var(--code); border: 1px solid var(--border);
    font-size: 12px; font-weight: 600; color: var(--muted);
}
code { display: inline-block; max-width: 100%; padding: 0 6px; border-radius: 5px; background: var(--code); font: 13px/1.6 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; overflow-wrap: anywhere; vertical-align: bottom; }
pre {
    margin: 8px 0 0; padding: 12px 14px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface);
    font: 13px/1.6 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; white-space: pre-wrap; overflow-wrap: anywhere;
}
</style>
</head>
<body>
<main>
    <div class="eyebrow">TUNNEL SETUP</div>
    <h1>Almost ready</h1>
    <p>This server needs a configuration file before it can carry tunnels.</p>
    <?php if (!$error->missing): ?>
        <div class="problem"><?= View::e($error->getMessage()) ?></div>
    <?php endif ?>
    <ol>
        <li>
            Copy <code>config.example.php</code> to this file:
            <pre><?= View::e($configPath) ?></pre>
        </li>
        <li>
            Set its token to a long random secret. Here is one made just now:
            <pre>'token' => '<?= View::e($token) ?>',</pre>
        </li>
        <li>Reload this page and sign in with that token.</li>
    </ol>
</main>
</body>
</html>

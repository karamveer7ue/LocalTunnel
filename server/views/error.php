<?php
/**
 * @var int $status
 * @var string $title
 * @var string $message
 * @var ?string $hint text in which `backticks` mark commands
 */

use Tunnel\View;

$format = static fn (string $text): string => preg_replace('/`([^`]+)`/', '<code>$1</code>', View::e($text)) ?? View::e($text);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="color-scheme" content="light dark">
<title><?= View::e("$status $title") ?></title>
<style>
:root {
    --bg: #f7f7f8; --surface: #ffffff; --border: #e4e4e8; --text: #17171c; --muted: #6b6b76;
    --code: #f1f1f4; --accent: #4f46e5;
}
@media (prefers-color-scheme: dark) {
    :root {
        --bg: #0b0b0f; --surface: #131318; --border: #26262e; --text: #ececf1; --muted: #9393a0;
        --code: #1c1c23; --accent: #9496fa;
    }
}
*, *::before, *::after { box-sizing: border-box; }
html, body { margin: 0; min-height: 100%; }
body {
    display: grid; place-items: center; min-height: 100vh; padding: 24px 16px;
    background: var(--bg); color: var(--text);
    font: 15px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    -webkit-font-smoothing: antialiased;
}
main { width: 100%; max-width: 460px; }
.status { font: 600 12px/1 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; letter-spacing: .06em; color: var(--accent); }
h1 { margin: 12px 0 8px; font-size: 24px; line-height: 1.3; letter-spacing: -.015em; }
p { margin: 0; color: var(--muted); overflow-wrap: anywhere; }
.hint { margin-top: 20px; padding: 12px 14px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface); color: var(--text); font-size: 14px; }
code { display: inline-block; max-width: 100%; padding: 0 6px; border-radius: 5px; background: var(--code); font: 13px/1.6 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; overflow-wrap: anywhere; vertical-align: bottom; }
footer { margin-top: 32px; font-size: 12px; color: var(--muted); }
</style>
</head>
<body>
<main>
    <div class="status">ERROR <?= (int)$status ?></div>
    <h1><?= View::e($title) ?></h1>
    <p><?= $format($message) ?></p>
    <?php if ($hint !== null): ?>
        <div class="hint"><?= $format($hint) ?></div>
    <?php endif ?>
    <footer>Served by Tunnel</footer>
</main>
</body>
</html>

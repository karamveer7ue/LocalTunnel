<?php
/**
 * @var Tunnel\Request $request
 * @var array<string, mixed> $settings
 */

use Tunnel\Assets;
use Tunnel\View;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="color-scheme" content="light dark">
<title>Tunnel</title>
<link rel="icon" href="<?= View::e(Assets::url($request, 'icon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= View::e(Assets::url($request, 'app.css')) ?>">
<script src="<?= View::e(Assets::url($request, 'app.js')) ?>" defer></script>
</head>
<body data-settings="<?= View::e(json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) ?>">
<div id="app"></div>
<noscript><p class="noscript">The dashboard needs JavaScript.</p></noscript>
</body>
</html>

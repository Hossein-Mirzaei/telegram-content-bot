<?php

declare(strict_types=1);

/**
 * public/index.php
 *
 * Minimal public landing page for the bot's web root. Deliberately reveals
 * no configuration, tokens, or internal paths. Mainly useful as a quick
 * "is PHP working here / is bootstrap wired correctly" smoke test.
 */

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
$channel = (require CONFIG_PATH . '/config.php')['channel'];
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>Telegram Content Bot</title>
    <meta name="robots" content="noindex, nofollow">
    <style>
        body { font-family: system-ui, sans-serif; background:#0f172a; color:#e2e8f0; display:flex; align-items:center; justify-content:center; height:100vh; margin:0; }
        .card { background:#1e293b; padding:2rem 3rem; border-radius:12px; text-align:center; }
        a { color:#38bdf8; }
    </style>
</head>
<body>
    <div class="card">
        <h1>🤖 Telegram Content Bot</h1>
        <p>سرویس در حال اجراست.</p>
        <p><a href="<?= htmlspecialchars($channel['url']) ?>" target="_blank" rel="noopener">
            <?= htmlspecialchars($channel['name']) ?>
        </a></p>
    </div>
</body>
</html>

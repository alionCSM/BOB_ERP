<?php

/**
 * Render the standard 404 page and exit. Used by authorization checks that
 * want to hide the existence of out-of-scope resources from the caller —
 * a 404 reveals strictly less than a 403.
 *
 * Looks for a deployed bob404.html (legacy convention from public/index.php)
 * first; falls back to an inline template so behaviour is identical whether
 * or not the deploy ships the static file.
 */
function render_not_found_and_exit(): void
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');

    $custom404 = defined('APP_ROOT') ? (APP_ROOT . '/../bob404.html') : null;
    if ($custom404 && file_exists($custom404)) {
        require $custom404;
        exit;
    }

    echo <<<'HTML'
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>404 — Pagina non trovata</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        html, body {
            margin: 0; padding: 0; height: 100%;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #0d0d1a 0%, #130d2e 35%, #1a0a3d 60%, #0d1929 100%);
            color: #e2e8f0;
        }
        .nf-wrap {
            min-height: 100%;
            display: flex; align-items: center; justify-content: center;
            padding: 24px;
        }
        .nf-card {
            max-width: 520px; width: 100%;
            background: rgba(255, 255, 255, 0.04);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 40px 32px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35);
        }
        .nf-code {
            font-size: 88px; font-weight: 800;
            background: linear-gradient(135deg, #8b5cf6, #3b82f6);
            -webkit-background-clip: text; background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 0 0 8px 0; letter-spacing: -2px;
        }
        .nf-title { font-size: 22px; font-weight: 600; margin: 0 0 12px 0; color: #f1f5f9; }
        .nf-text  { font-size: 14px; color: #94a3b8; margin: 0 0 28px 0; line-height: 1.6; }
        .nf-btn {
            display: inline-block; padding: 12px 24px;
            background: #6366f1; color: #fff; text-decoration: none;
            border-radius: 8px; font-weight: 500; font-size: 14px;
            transition: background 0.15s ease;
        }
        .nf-btn:hover { background: #4f46e5; }
        .nf-logo { font-weight: 700; font-size: 13px; color: #64748b; margin-top: 32px; letter-spacing: 1px; }
    </style>
</head>
<body>
    <div class="nf-wrap">
        <div class="nf-card">
            <h1 class="nf-code">404</h1>
            <p class="nf-title">Pagina non trovata</p>
            <p class="nf-text">
                La risorsa richiesta non esiste oppure non è disponibile.
                Torna alla dashboard e riprova.
            </p>
            <a href="/" class="nf-btn">Torna alla home</a>
            <p class="nf-logo">BOB</p>
        </div>
    </div>
</body>
</html>
HTML;
    exit;
}

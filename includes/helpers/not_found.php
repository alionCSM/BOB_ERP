<?php

/**
 * Render the standard 404 page and exit. Used by authorization checks that
 * want to hide the existence of out-of-scope resources from the caller —
 * a 404 reveals strictly less than a 403.
 */
function render_not_found_and_exit(): void
{
    http_response_code(404);
    $custom404 = defined('APP_ROOT') ? (APP_ROOT . '/../bob404.html') : null;
    if ($custom404 && file_exists($custom404)) {
        require $custom404;
    } else {
        echo '404 Not Found';
    }
    exit;
}

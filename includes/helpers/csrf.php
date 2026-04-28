<?php

/**
 * Generate (or return) the per-request CSP nonce.
 * Stored in $GLOBALS so it survives across includes without a session write.
 */
function csp_nonce(): string
{
    if (empty($GLOBALS['csp_nonce'])) {
        $GLOBALS['csp_nonce'] = base64_encode(random_bytes(16));
    }
    return $GLOBALS['csp_nonce'];
}

/**
 * Generate (or return existing) CSRF token for the current session.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token from POST body (_csrf) or X-CSRF-Token header.
 */
function csrf_verify(): bool
{
    $submitted = $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? $_POST['_csrf']
        ?? '';

    return !empty($submitted)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $submitted);
}

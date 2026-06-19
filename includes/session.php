<?php
/**
 * Session bootstrap helpers (Sprint security Phase 0).
 * ====================================================
 *
 * INCLUDABLE + SIDE-EFFECT-FREE. This file defines functions ONLY — it does NOT
 * call session_start(), session_set_cookie_params(), or read superglobals at
 * include time. That is deliberate: the CLI regression harness (tests/run.php)
 * CANNOT load config.php (which defines DB_PATH and starts a session), but it CAN
 * `require_once` this file and assert the cookie-flag + idle-timeout logic
 * directly. config.php requires this file and applies the result.
 */

/**
 * Compute the hardened session-cookie parameters.
 *
 * Pure: returns the params array WITHOUT touching session state. config.php feeds
 * the result to session_set_cookie_params() immediately before session_start().
 *
 * - httponly : always true — block JS (document.cookie) from reading the id.
 * - samesite : 'Lax' — the cheap half of CSRF defence (cookie omitted on
 *              cross-site sub-requests, sent on top-level navigation).
 * - secure   : derived from whether the request arrived over HTTPS, so the Secure
 *              flag auto-enables the moment TLS lands (Phase 2) without breaking
 *              local `php -S` HTTP dev (where it stays false).
 *
 * @param array|null $server  Defaults to $_SERVER; tests pass a synthetic array
 *                            to exercise both the HTTP and HTTPS branches.
 */
function configureSessionCookieParams($server = null) {
    if ($server === null) {
        $server = isset($_SERVER) ? $_SERVER : [];
    }

    // Detect HTTPS robustly: direct TLS (HTTPS=on / =1), a terminating proxy
    // forwarding X-Forwarded-Proto: https, OR the standard :443 server port.
    $https = false;
    if (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off') {
        $https = true;
    } elseif (isset($server['HTTP_X_FORWARDED_PROTO'])
              && strtolower((string) $server['HTTP_X_FORWARDED_PROTO']) === 'https') {
        $https = true;
    } elseif (isset($server['SERVER_PORT']) && (int) $server['SERVER_PORT'] === 443) {
        $https = true;
    }

    return [
        'lifetime' => 0,        // session cookie: cleared when the browser closes
        'path'     => '/',
        'domain'   => '',
        'secure'   => $https,   // env-gated; inert-but-harmless until TLS (Phase 2)
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/**
 * Apply the hardened cookie params to PHP's session config. Call this BEFORE
 * session_start(). Kept out of configureSessionCookieParams() so the latter stays
 * pure/assertable; this is the thin side-effecting wrapper config.php invokes.
 */
function applySessionCookieParams($server = null) {
    $p = configureSessionCookieParams($server);
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params($p);
    } else {
        // PHP < 7.3 lacks the samesite array key; smuggle it via the path hack.
        session_set_cookie_params(
            $p['lifetime'], $p['path'] . '; samesite=' . $p['samesite'],
            $p['domain'], $p['secure'], $p['httponly']
        );
    }
}

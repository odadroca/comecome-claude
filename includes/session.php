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

/* ===========================================================================
 * Sprint security Phase 2 — enforce TLS/HTTPS + HSTS.
 * ===========================================================================
 *
 * The PRIMARY transport-security mechanism is the Apache `.htaccess` HTTP->HTTPS
 * 301 + HSTS header (uncommented in Phase 2). These PHP helpers are an
 * APP-LEVEL backstop + the testable surface:
 *
 *   - `.htaccess` only runs under Apache with mod_rewrite + mod_headers. The
 *     spec repeatedly warns the `.htaccess` rules "evaporate under
 *     nginx/litespeed". Doing the redirect + HSTS in PHP too means the control
 *     holds on any SAPI that reaches index.php, not only Apache.
 *   - `php -S` cannot process `.htaccess`, so the ONLY way the Testability
 *     section's "301 redirect + HSTS fire when the TLS env flag is set" smoke
 *     can be exercised is an app-level path the dev server actually runs.
 *
 * ORDERING INVARIANT (spec): the env-gated Secure cookie flag (Phase 0,
 * configureSessionCookieParams) is computed FIRST in config.php, so local
 * `php -S` HTTP dev is never broken. These helpers honour the same rule:
 * over plain HTTP with no force flag, BOTH are inert (no redirect, no HSTS).
 *
 * Both compute helpers are PURE (no header()/exit/superglobal reads) so
 * tests/run.php can assert every branch; enforceTransportSecurity() is the thin
 * side-effecting wrapper config.php invokes.
 */

/**
 * Reuse Phase 0's HTTPS detection (direct TLS, X-Forwarded-Proto, or :443) so
 * the redirect decision and the Secure-cookie decision can never disagree.
 *
 * @param array|null $server  Defaults to $_SERVER; tests pass a synthetic array.
 */
function requestIsHttps($server = null) {
    if ($server === null) {
        $server = isset($_SERVER) ? $_SERVER : [];
    }
    if (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off') {
        return true;
    }
    if (isset($server['HTTP_X_FORWARDED_PROTO'])
        && strtolower((string) $server['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    if (isset($server['SERVER_PORT']) && (int) $server['SERVER_PORT'] === 443) {
        return true;
    }
    return false;
}

/**
 * Should this request be forced from HTTP onto HTTPS? Pure decision logic.
 *
 * Returns the absolute https:// URL to 301-redirect to, or null to NOT redirect.
 * Redirects ONLY when:
 *   - the request is plain HTTP (already-HTTPS never redirects), AND
 *   - HTTPS enforcement is explicitly turned on for this deployment via the
 *     COMECOME_FORCE_HTTPS env flag (truthy: '1'/'true'/'on'/'yes').
 *
 * The OPT-IN flag is what preserves zero-config local `php -S` dev: with the
 * flag unset (the default), plain-HTTP dev is left completely alone — no
 * redirect loop, no surprise. An operator who has enabled Let's Encrypt sets
 * COMECOME_FORCE_HTTPS=1 (or relies solely on the `.htaccess` rule) to turn the
 * app-level backstop on. A proxy that terminates TLS upstream still forwards
 * X-Forwarded-Proto=https, so requestIsHttps() already sees it as secure and we
 * do NOT redirect (avoiding an infinite loop behind the proxy).
 *
 * @param array|null  $server   Defaults to $_SERVER.
 * @param string|null $forceEnv Defaults to getenv('COMECOME_FORCE_HTTPS');
 *                              tests pass an explicit value.
 */
function httpsRedirectTarget($server = null, $forceEnv = null) {
    if ($server === null) {
        $server = isset($_SERVER) ? $_SERVER : [];
    }
    if ($forceEnv === null) {
        $env = getenv('COMECOME_FORCE_HTTPS');
        $forceEnv = ($env === false) ? '' : $env;
    }

    if (!httpsEnforcementEnabled($forceEnv)) {
        return null;                 // enforcement off => never redirect (dev-safe)
    }
    if (requestIsHttps($server)) {
        return null;                 // already secure => nothing to do
    }

    // Build the same-host https:// target, preserving the path + query exactly.
    $host = isset($server['HTTP_HOST']) ? (string) $server['HTTP_HOST'] : '';
    if ($host === '') {
        return null;                 // no host to redirect to => fail safe (no loop)
    }
    // Strip CR/LF defensively so a crafted Host can't inject a header.
    $host = str_replace(["\r", "\n"], '', $host);
    $uri  = isset($server['REQUEST_URI']) ? (string) $server['REQUEST_URI'] : '/';
    $uri  = str_replace(["\r", "\n"], '', $uri);

    return 'https://' . $host . $uri;
}

/**
 * Is HTTPS enforcement turned on for this deployment? Truthy values only.
 */
function httpsEnforcementEnabled($forceEnv) {
    $v = strtolower(trim((string) $forceEnv));
    return in_array($v, ['1', 'true', 'on', 'yes'], true);
}

/**
 * The HSTS (Strict-Transport-Security) header VALUE to emit, or null to emit
 * none. Pure.
 *
 * RFC 6797: a UA MUST ignore an HSTS header received over plain HTTP, and
 * sending it there is pointless, so we emit it ONLY when the request is already
 * HTTPS. Conservative defaults per the spec ("conservative max-age first, no
 * preload"): max-age = 1 day, includeSubDomains, and crucially NO `preload`
 * token (preload is a hard-to-reverse commitment the operator opts into later).
 *
 * @param array|null $server  Defaults to $_SERVER.
 */
function hstsHeaderValue($server = null) {
    if ($server === null) {
        $server = isset($_SERVER) ? $_SERVER : [];
    }
    if (!requestIsHttps($server)) {
        return null;                 // never assert HSTS over plain HTTP (RFC 6797)
    }
    $maxAge = defined('HSTS_MAX_AGE') ? (int) HSTS_MAX_AGE : 86400; // 1 day, conservative
    return 'max-age=' . $maxAge . '; includeSubDomains';
}

/**
 * Side-effecting wrapper config.php invokes (after the session bootstrap). Does
 * the actual 301 redirect and/or emits the HSTS header based on the pure
 * helpers above. Returns silently (no-op) on the dev/HTTP path so local
 * `php -S` is untouched.
 *
 * Kept thin + separate from the pure helpers so the harness can assert the
 * decision logic without a real request.
 */
function enforceTransportSecurity($server = null) {
    $target = httpsRedirectTarget($server);
    if ($target !== null) {
        if (!headers_sent()) {
            header('Location: ' . $target, true, 301);
        }
        exit;
    }
    $hsts = hstsHeaderValue($server);
    if ($hsts !== null && !headers_sent()) {
        header('Strict-Transport-Security: ' . $hsts);
    }
}

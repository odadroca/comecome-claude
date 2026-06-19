<?php
/**
 * CSRF protection helpers (Sprint security Phase 3).
 * ==================================================
 *
 * Minimal, dependency-free, vanilla CSRF defence built on stock PHP only
 * (random_bytes + hash_equals) — no Composer, no framework. Defence-in-depth:
 * SameSite=Lax (Phase 0) and the api `Content-Type: application/json` preflight
 * already blunt naive cross-site POSTs; this is the explicit per-request token
 * that closes the gap regardless of SameSite support or a same-site attacker.
 *
 * MODEL: one per-session synchroniser token (the classic synchroniser-token
 * pattern). The token is minted once per session, kept in $_SESSION, embedded in
 * every state-changing form (hidden field) and surfaced to inline fetch() callers
 * via a <meta> tag + JS constant so the api endpoints can require it in an
 * X-CSRF-Token header. Verification is a constant-time hash_equals() compare.
 *
 * SESSION-DEPENDENT: csrfToken()/verifyCsrf() touch $_SESSION, so an ACTIVE
 * session is required (config.php has already called session_start() before any
 * page/handler includes this). The functions are guarded so that including this
 * file is side-effect free — nothing runs until a caller invokes it — which keeps
 * the CLI harness able to require_once it and assert verifyCsrfToken() (the pure,
 * session-free comparator) directly.
 */

if (!defined('CSRF_SESSION_KEY')) {
    define('CSRF_SESSION_KEY', 'csrf_token');
}
if (!defined('CSRF_FIELD_NAME')) {
    define('CSRF_FIELD_NAME', 'csrf_token');
}
if (!defined('CSRF_HEADER_NAME')) {
    // The HTTP header inline fetch() callers attach. PHP exposes it as
    // $_SERVER['HTTP_X_CSRF_TOKEN'].
    define('CSRF_HEADER_NAME', 'X-CSRF-Token');
}

/**
 * Get (minting on first use) the current session's CSRF token. Idempotent: the
 * same token is returned for the life of the session, so every form/handler in
 * one session shares one token. 32 random bytes => 64 hex chars.
 *
 * Requires an active session ($_SESSION available). Returns '' if somehow called
 * with no session (e.g. CLI) so callers degrade rather than fatal.
 */
function csrfToken() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // No session to anchor the token to (CLI/test context) — fail safe.
        return '';
    }
    if (empty($_SESSION[CSRF_SESSION_KEY]) || !is_string($_SESSION[CSRF_SESSION_KEY])) {
        $_SESSION[CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_SESSION_KEY];
}

/**
 * The hidden form field markup to drop inside every state-changing <form>. The
 * token is HTML-attribute-escaped defensively (it is hex, but escape anyway so
 * this helper is safe by construction).
 */
function csrfField() {
    $token = csrfToken();
    return '<input type="hidden" name="' . CSRF_FIELD_NAME . '" value="'
        . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * A <meta name="csrf-token"> tag + inline JS constant so the existing inline
 * fetch() callers on child/guardian pages can read the token and attach it as the
 * X-CSRF-Token header. Emitted into renderLayout()'s <head>. Pure string builder.
 */
function csrfMetaTag() {
    $token = htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8');
    return '<meta name="csrf-token" content="' . $token . '">' . "\n"
        . '<script>window.CSRF_TOKEN=' . json_encode($token)
        . ';window.CSRF_HEADER=' . json_encode(CSRF_HEADER_NAME) . ';</script>';
}

/**
 * PURE constant-time token comparison — the harness-assertable core. No session,
 * no superglobals: just "does $candidate match $expected?" via hash_equals so a
 * non-empty expected token never short-circuits on length/early-mismatch timing.
 * An empty expected OR empty candidate is ALWAYS a rejection (never let a blank
 * token validate).
 */
function verifyCsrfToken($expected, $candidate) {
    if (!is_string($expected) || !is_string($candidate)) {
        return false;
    }
    if ($expected === '' || $candidate === '') {
        return false;
    }
    return hash_equals($expected, $candidate);
}

/**
 * Verify the CSRF token on the CURRENT request against the session token. Reads
 * the token from (in order) the POST field, then the X-CSRF-Token header (so both
 * classic form POSTs and inline fetch() JSON calls are covered). Returns bool.
 *
 * Does NOT itself send a response — callers decide how to reject (a guardian page
 * re-renders with a message; an api endpoint returns 403 JSON). Keeps the policy
 * at the call site while the token plumbing stays here.
 */
function verifyCsrf() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    $expected = $_SESSION[CSRF_SESSION_KEY] ?? '';

    // Prefer the explicit form field; fall back to the request header.
    $candidate = '';
    if (isset($_POST[CSRF_FIELD_NAME]) && is_string($_POST[CSRF_FIELD_NAME])) {
        $candidate = $_POST[CSRF_FIELD_NAME];
    } elseif (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && is_string($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $candidate = $_SERVER['HTTP_X_CSRF_TOKEN'];
    }

    return verifyCsrfToken($expected, $candidate);
}

/**
 * Convenience guard for api/ endpoints: require a valid token on state-changing
 * methods (POST/DELETE/PUT/PATCH) and, on failure, emit a 403 JSON error and exit.
 * Read-only GET requests are never gated (they don't change state). Uses
 * jsonResponse() (from helpers.php) so the error shape matches the other api
 * errors.
 *
 * @param string|null $method Defaults to the request method; injectable for tests.
 */
function requireCsrfForApi($method = null) {
    if ($method === null) {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }
    $method = strtoupper((string) $method);

    // GET/HEAD/OPTIONS are non-mutating — no token required.
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return;
    }

    if (!verifyCsrf()) {
        jsonResponse(['success' => false, 'error' => 'invalid_csrf'], 403);
    }
}

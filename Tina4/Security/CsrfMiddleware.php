<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Backported from v3: session-bound CSRF protection middleware.
 */

namespace Tina4;

class CsrfMiddleware
{
    /**
     * Generate a CSRF token and bind it to the current session.
     * Returns the token string (also stored in $_SESSION['_csrf_token']).
     */
    public static function generateToken(): string
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        return $token;
    }

    /**
     * Validate a submitted CSRF token against the session-stored token.
     *
     * Rules (backported from v3):
     *  1. Token must match the session value (timing-safe comparison)
     *  2. Token must NOT arrive via query string (prevents referer leaks)
     *  3. Bearer-authenticated requests are exempt (API clients manage their own auth)
     *  4. XHR/JSON requests are exempt (browsers enforce same-origin for these)
     *
     * @param string|null $submittedToken The formToken value from the request
     * @return bool
     */
    public static function validate(?string $submittedToken = null): bool
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        // Exempt: Bearer-authenticated requests
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (stripos($authHeader, 'Bearer ') === 0) {
            return true;
        }

        // Exempt: XHR requests (covered by SameSite cookie + CORS)
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return true;
        }

        // Exempt: JSON/XML content types (native forms cannot send these)
        $ct = strtolower($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
        if (strpos($ct, 'application/json') === 0 || strpos($ct, 'application/xml') === 0) {
            return true;
        }

        // Reject tokens arriving via query string (prevents referer-based leaks)
        if (isset($_GET['formToken']) || isset($_GET['_csrf_token'])) {
            Debug::message("CSRF token rejected: arrived via query string", TINA4_LOG_WARNING);
            return false;
        }

        // Fall back to the submitted value or POST body
        if ($submittedToken === null) {
            $submittedToken = $_POST['formToken'] ?? $_POST['_csrf_token'] ?? '';
        }

        $sessionToken = $_SESSION['_csrf_token'] ?? '';

        if (empty($sessionToken) || empty($submittedToken)) {
            return false;
        }

        return hash_equals($sessionToken, $submittedToken);
    }
}

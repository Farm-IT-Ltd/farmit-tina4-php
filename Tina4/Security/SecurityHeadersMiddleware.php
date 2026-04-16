<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Injects security headers on every response.
 * Backported from Tina4 v3 SecurityHeadersMiddleware.
 *
 * Always applied:
 *   X-Frame-Options: DENY
 *   X-Content-Type-Options: nosniff
 *   Referrer-Policy: strict-origin-when-cross-origin
 *   Permissions-Policy: camera=(), microphone=(), geolocation=()
 *
 * Opt-in via .env:
 *   TINA4_HSTS=31536000   — enables Strict-Transport-Security with that max-age (seconds)
 *   TINA4_CSP="default-src 'self'"  — sets Content-Security-Policy
 *   TINA4_FRAME_OPTIONS=SAMEORIGIN  — overrides X-Frame-Options (default: DENY)
 *
 * Usage in index.php / bootstrap:
 *   \Tina4\SecurityHeadersMiddleware::apply();
 */
class SecurityHeadersMiddleware
{
    public static function apply(): void
    {
        if (headers_sent()) {
            return;
        }

        $frameOptions = $_ENV['TINA4_FRAME_OPTIONS'] ?? 'DENY';
        header('X-Frame-Options: ' . $frameOptions);
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

        $hsts = $_ENV['TINA4_HSTS'] ?? '';
        if (!empty($hsts) && is_numeric($hsts)) {
            header('Strict-Transport-Security: max-age=' . (int)$hsts . '; includeSubDomains');
        }

        $csp = $_ENV['TINA4_CSP'] ?? '';
        if (!empty($csp)) {
            header('Content-Security-Policy: ' . $csp);
        }
    }
}

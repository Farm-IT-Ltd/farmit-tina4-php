<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Backported from v3: env-configurable CORS middleware.
 */

namespace Tina4;

class CorsMiddleware
{
    /**
     * Apply CORS headers to the current response.
     *
     * Configuration via environment variables (all optional):
     *   TINA4_CORS_ALLOW_ORIGIN   — Comma-separated origins or "*" (default: "*")
     *   TINA4_CORS_ALLOW_METHODS  — Comma-separated HTTP methods (default: "GET,POST,PUT,PATCH,DELETE,OPTIONS")
     *   TINA4_CORS_ALLOW_HEADERS  — Comma-separated headers (default: "Content-Type,Authorization,X-Requested-With")
     *   TINA4_CORS_MAX_AGE        — Preflight cache seconds (default: "86400")
     *   TINA4_CORS_ALLOW_CREDENTIALS — "true" to send Access-Control-Allow-Credentials (default: "false")
     *
     * @param array $headers Existing response headers array to augment
     * @return array The augmented headers array
     */
    public static function apply(array $headers = []): array
    {
        $allowOrigin = $_ENV['TINA4_CORS_ALLOW_ORIGIN'] ?? '*';
        $allowMethods = $_ENV['TINA4_CORS_ALLOW_METHODS'] ?? 'GET,POST,PUT,PATCH,DELETE,OPTIONS';
        $allowHeaders = $_ENV['TINA4_CORS_ALLOW_HEADERS'] ?? 'Content-Type,Authorization,X-Requested-With';
        $maxAge = $_ENV['TINA4_CORS_MAX_AGE'] ?? '86400';
        $allowCredentials = strtolower($_ENV['TINA4_CORS_ALLOW_CREDENTIALS'] ?? 'false');

        // If specific origins are listed (not wildcard), check against the request origin
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($allowOrigin !== '*' && !empty($origin)) {
            $allowed = array_map('trim', explode(',', $allowOrigin));
            if (in_array($origin, $allowed, true)) {
                $headers[] = "Access-Control-Allow-Origin: {$origin}";
                $headers[] = "Vary: Origin";
            }
            // If origin not in the allow-list, don't send CORS headers at all
        } else {
            $headers[] = "Access-Control-Allow-Origin: *";
        }

        $headers[] = "Access-Control-Allow-Methods: {$allowMethods}";
        $headers[] = "Access-Control-Allow-Headers: {$allowHeaders}";
        $headers[] = "Access-Control-Max-Age: {$maxAge}";

        if ($allowCredentials === 'true') {
            $headers[] = "Access-Control-Allow-Credentials: true";
        }

        return $headers;
    }

    /**
     * Handle an OPTIONS preflight request.
     * Sends CORS headers and exits with 204 No Content.
     */
    public static function handlePreflight(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'OPTIONS') {
            return;
        }

        $headers = self::apply();
        foreach ($headers as $header) {
            header($header);
        }

        http_response_code(204);
        exit;
    }
}

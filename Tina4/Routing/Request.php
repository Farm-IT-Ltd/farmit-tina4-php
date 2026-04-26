<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Implements request params which get passed through to the routers
 * @package Tina4
 */
class Request extends \StdClass
{
    public $data = null;
    public $params = null;
    public $inlineParams = null;
    public $server = null;
    public $session = null;
    public $files = null;
    public $headers = null;
    public $rawRequest = null;
    public $security = null;
    public $ip = null;

    /**
     * Filter value
     * @param $value
     * @return mixed
     */
    final function filterValue($value)
    {
        if (is_array($value)) {
            foreach($value as $vKey => $vValue) {
                if (is_array($vValue)) {
                    $value[$vKey] = $this->filterValue($vValue);
                } else {
                    $value[$vKey] = htmlspecialchars($vValue, ENT_NOQUOTES, "UTF-8");
                }
            }
        } else {
            $value = htmlspecialchars($value, ENT_NOQUOTES, "UTF-8");
        }
        return $value;
    }

    /**
     * @param $rawRequest
     * @param $customRequest
     */
    public function __construct($rawRequest, $customRequest=null)
    {
        $this->rawRequest = $rawRequest;

        if (!empty($customRequest->get)) {
            foreach ($customRequest->get as $key => $value) {
                $_REQUEST[$key] = $value;
                $_GET[$key] = $value;
            }
        }

        if (!empty($customRequest->post))
        {
            foreach ($customRequest->post as $key => $value) {
                $_REQUEST[$key] = $this->filterValue($value);
                $_POST[$key] = $this->filterValue($value);
            }
        }
        if (!empty($customRequest->files)) {
            foreach ($customRequest->files as $key => $value) {
                $_FILES[$key] = $value;
            }
        }

        if (!empty($customRequest->cookie)) {
            foreach ($customRequest->cookie as $key => $value) {
                $_COOKIE[$key] = $value;
            }
        }

        if (!empty($customRequest->server)) {
            foreach ($customRequest->server as $key => $value) {
                $_SERVER[strtoupper($key)] = $value;
            }

            //hack for getting the swoole server header
            if (!empty($customRequest->header["host"])) {
                $_SERVER["HTTP_HOST"] = $customRequest->header["host"];
            }
        }

        Debug::message($rawRequest, TINA4_LOG_DEBUG);
        $this->data = (object)[];
        if (!empty($_REQUEST)) {
            $this->params = $_REQUEST;
            foreach ($this->params as $key => $value) {
                $this->params[$key] = $this->filterValue($value);
            }
        }

        $requestHeaders = null;
        if (function_exists("getallheaders")) {
            $requestHeaders = getallheaders();
        }

        if (!empty($requestHeaders)) {
            $this->headers = $requestHeaders;
            foreach ($this->headers as $header => $headerValue) {
                $this->headers[strtolower($header)] = $headerValue;
            }
        }

        if (!empty($_FILES)) {
            $this->files = $_FILES;
        }

        if (!empty($_SERVER)) {
            $this->server = $_SERVER;
        }

        if (!empty($_SESSION)) {
            $this->session = $_SESSION;
        }

        if (!empty($rawRequest)) {
            $this->data = json_decode($this->rawRequest, false, 512);
            if ($this->data === null && $rawRequest !== '') {
                $this->data = $rawRequest;
            }
        } else {
            foreach ($_REQUEST as $key => $value) {
                $this->data->{$key} = $this->filterValue($value);
            }
        }

        // Enforce upload size limit (env TINA4_MAX_UPLOAD_SIZE in bytes, default 0 = unlimited)
        $maxUpload = (int)($_ENV['TINA4_MAX_UPLOAD_SIZE'] ?? 0);
        if ($maxUpload > 0 && !empty($_FILES)) {
            foreach ($_FILES as $fileKey => $fileInfo) {
                $sizes = is_array($fileInfo['size']) ? $fileInfo['size'] : [$fileInfo['size']];
                foreach ($sizes as $size) {
                    if ($size > $maxUpload) {
                        Debug::message("Upload '{$fileKey}' exceeds TINA4_MAX_UPLOAD_SIZE ({$maxUpload} bytes)", TINA4_LOG_WARNING);
                        unset($_FILES[$fileKey]);
                        break;
                    }
                }
            }
            $this->files = !empty($_FILES) ? $_FILES : null;
        }

        // Resolve client IP — respects reverse proxy headers
        $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (!empty($forwardedFor)) {
            $this->ip = trim(explode(',', $forwardedFor)[0]);
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $this->ip = $_SERVER['HTTP_X_REAL_IP'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $this->ip = $_SERVER['REMOTE_ADDR'];
        } else {
            $this->ip = 'unknown';
        }
    }

    /**
     * Factory method to create a Request from the current PHP globals.
     * Useful in middleware, services, or anywhere you need a Request
     * without the Router having built one for you.
     *
     * @return static
     */
    public static function create(): self
    {
        return new self(@file_get_contents('php://input'));
    }

    /**
     * Does the client want a JSON response?
     * Checks the Accept header for application/json.
     */
    public function wantsJson(): bool
    {
        $accept = $this->headers['accept'] ?? $this->headers['Accept'] ?? '';
        return stripos($accept, 'application/json') !== false;
    }

    /**
     * Is the request body JSON?
     * Checks the Content-Type header.
     */
    public function isJson(): bool
    {
        $ct = $this->headers['content-type'] ?? $this->headers['Content-Type']
            ?? $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        return stripos($ct, 'application/json') !== false;
    }

    /**
     * Get a value from the request body (POST data or JSON body).
     * Supports dot notation for nested JSON: input('user.name')
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, $default = null)
    {
        $segments = explode('.', $key);
        $value = $this->data;

        foreach ($segments as $segment) {
            if (is_object($value) && isset($value->{$segment})) {
                $value = $value->{$segment};
            } elseif (is_array($value) && isset($value[$segment])) {
                $value = $value[$segment];
            } else {
                return $default;
            }
        }

        return $value;
    }

    /**
     * Get a named inline route parameter.
     * Route::get('/users/{id}', ...) => $request->param('id')
     *
     * @param string $name Parameter name (without braces)
     * @param mixed $default
     * @return mixed
     */
    public function param(string $name, $default = null)
    {
        if (is_array($this->inlineParams) && isset($this->inlineParams[$name])) {
            return $this->inlineParams[$name];
        }
        return $default;
    }

    /**
     * Get a query string parameter (?key=value).
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function queryParam(string $key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }

    /**
     * Returns the data object that was created from the request
     * @return mixed|null
     */
    public function __invoke()
    {
        return $this->data;
    }

    public function __toString(): string
    {
        return json_encode($this->data);
    }

    public function asArray()
    {
        return json_decode(json_encode($this->data), true, 512);
    }

    /**
     * Extracts the Bearer token from the Authorization header.
     * Returns null if no valid Bearer token is present.
     */
    public function bearerToken(): ?string
    {
        $authHeader = $this->headers['authorization']
            ?? $this->headers['Authorization']
            ?? $_SERVER['HTTP_AUTHORIZATION']
            ?? '';

        if (stripos($authHeader, 'Bearer ') === 0) {
            return substr($authHeader, 7);
        }

        return null;
    }
}
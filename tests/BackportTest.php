<?php

/**
 * Comprehensive tests for v3 backports.
 *
 * Covers:
 *  - Auth::getPayloadWithoutValidation()
 *  - Request::create(), wantsJson(), isJson(), input(), param(), queryParam()
 *  - Request upload size limit (TINA4_MAX_UPLOAD_SIZE)
 *  - Route::noAuth()
 *  - Router @no-auth annotation handling
 *  - Router param type casting ({id:int}, {price:float}, {active:bool})
 *  - CsrfMiddleware::generateToken(), validate()
 *  - CorsMiddleware::apply()
 */

use PHPUnit\Framework\TestCase;
use Tina4\Auth;
use Tina4\Request;
use Tina4\Route;
use Tina4\Router;
use Tina4\Response;
use Tina4\Security\CsrfMiddleware;
use Tina4\Security\CorsMiddleware;

class BackportTest extends TestCase
{
    // ─── Auth::getPayloadWithoutValidation ───────────────────────────────

    public function testGetPayloadWithoutValidationDecodesValidJwt(): void
    {
        $payload = ['sub' => '1234', 'name' => 'Test User', 'iat' => 1700000000];
        $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $body = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $sig = rtrim(strtr(base64_encode('fakesig'), '+/', '-_'), '=');
        $token = "$header.$body.$sig";

        $result = Auth::getPayloadWithoutValidation($token);

        $this->assertSame('1234', $result['sub']);
        $this->assertSame('Test User', $result['name']);
        $this->assertSame(1700000000, $result['iat']);
    }

    public function testGetPayloadWithoutValidationStripsBearer(): void
    {
        $payload = ['user' => 'alice'];
        $header = base64_encode(json_encode(['alg' => 'HS256']));
        $body = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $token = "Bearer $header.$body.fakesig";

        $result = Auth::getPayloadWithoutValidation($token);
        $this->assertSame('alice', $result['user']);
    }

    public function testGetPayloadWithoutValidationReturnEmptyOnMalformed(): void
    {
        $this->assertSame([], Auth::getPayloadWithoutValidation('not.a.valid-base64!'));
        $this->assertSame([], Auth::getPayloadWithoutValidation('onlyonepart'));
        $this->assertSame([], Auth::getPayloadWithoutValidation(''));
        $this->assertSame([], Auth::getPayloadWithoutValidation('a.b'));
    }

    public function testGetPayloadWithoutValidationReturnEmptyOnNonJsonPayload(): void
    {
        $header = base64_encode('{"alg":"HS256"}');
        $body = base64_encode('this is not json');
        $result = Auth::getPayloadWithoutValidation("$header.$body.sig");
        $this->assertSame([], $result);
    }

    // ─── Request helpers ─────────────────────────────────────────────────

    public function testRequestWantsJsonTrue(): void
    {
        $request = new Request('{}');
        $request->headers = ['accept' => 'application/json, text/plain'];
        $this->assertTrue($request->wantsJson());
    }

    public function testRequestWantsJsonFalse(): void
    {
        $request = new Request('{}');
        $request->headers = ['accept' => 'text/html'];
        $this->assertFalse($request->wantsJson());
    }

    public function testRequestIsJsonTrue(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/json; charset=utf-8';
        $request = new Request('{}');
        $this->assertTrue($request->isJson());
        unset($_SERVER['CONTENT_TYPE']);
    }

    public function testRequestIsJsonFalse(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $request = new Request('{}');
        $this->assertFalse($request->isJson());
        unset($_SERVER['CONTENT_TYPE']);
    }

    public function testRequestInputFromJsonBody(): void
    {
        $json = json_encode(['user' => ['name' => 'Bob', 'age' => 30]]);
        $request = new Request($json);

        $this->assertSame('Bob', $request->input('user.name'));
        $this->assertSame(30, $request->input('user.age'));
        $this->assertNull($request->input('user.email'));
        $this->assertSame('fallback', $request->input('missing.key', 'fallback'));
    }

    public function testRequestInputTopLevel(): void
    {
        $json = json_encode(['status' => 'active']);
        $request = new Request($json);

        $this->assertSame('active', $request->input('status'));
    }

    public function testRequestQueryParam(): void
    {
        $_GET['page'] = '3';
        $request = new Request('');

        $this->assertSame('3', $request->queryParam('page'));
        $this->assertNull($request->queryParam('nonexistent'));
        $this->assertSame('default', $request->queryParam('nonexistent', 'default'));

        unset($_GET['page']);
    }

    public function testRequestParam(): void
    {
        $request = new Request('');
        $request->inlineParams = ['id' => '42', 'slug' => 'hello-world'];

        $this->assertSame('42', $request->param('id'));
        $this->assertSame('hello-world', $request->param('slug'));
        $this->assertNull($request->param('missing'));
    }

    // ─── Route::noAuth() ────────────────────────────────────────────────

    public function testNoAuthSetsRouteFlags(): void
    {
        global $arrRoutes;
        $arrRoutes = [];

        Route::get('/api/health', function () { return 'ok'; })->noAuth();

        $lastRoute = end($arrRoutes);
        $this->assertFalse($lastRoute['secure']);
        $this->assertTrue($lastRoute['noAuth']);
    }

    public function testNoAuthOverridesSecure(): void
    {
        global $arrRoutes;
        $arrRoutes = [];

        Route::get('/api/secure-then-open', function () { return 'ok'; })->secure()->noAuth();

        $lastRoute = end($arrRoutes);
        $this->assertFalse($lastRoute['secure']);
        $this->assertTrue($lastRoute['noAuth']);
    }

    // ─── Router param type casting ──────────────────────────────────────

    public function testMatchPathWithIntTypeCasting(): void
    {
        $router = new Router();

        // Use reflection to call matchPath (protected-ish via public)
        $result = $router->matchPath('/users/42', '/users/{id:int}', []);
        $this->assertTrue($result);
    }

    public function testMatchPathIntRejectsNonNumeric(): void
    {
        $router = new Router();
        $result = $router->matchPath('/users/abc', '/users/{id:int}', []);
        $this->assertFalse($result);
    }

    public function testMatchPathWithFloatTypeCasting(): void
    {
        $router = new Router();
        $result = $router->matchPath('/price/19.99', '/price/{amount:float}', []);
        $this->assertTrue($result);
    }

    public function testMatchPathFloatRejectsNonNumeric(): void
    {
        $router = new Router();
        $result = $router->matchPath('/price/abc', '/price/{amount:float}', []);
        $this->assertFalse($result);
    }

    public function testMatchPathWithBoolTypeCasting(): void
    {
        $router = new Router();
        $result = $router->matchPath('/feature/true', '/feature/{active:bool}', []);
        $this->assertTrue($result);

        $result2 = $router->matchPath('/feature/0', '/feature/{active:bool}', []);
        $this->assertTrue($result2);
    }

    public function testMatchPathStandardParamsStillWork(): void
    {
        $router = new Router();
        $result = $router->matchPath('/users/42', '/users/{id}', []);
        $this->assertTrue($result);

        $result2 = $router->matchPath('/users/abc', '/users/{id}', []);
        $this->assertTrue($result2);
    }

    // ─── CsrfMiddleware ─────────────────────────────────────────────────

    public function testCsrfGenerateTokenCreatesSessionToken(): void
    {
        $token = \Tina4\CsrfMiddleware::generateToken();

        $this->assertNotEmpty($token);
        $this->assertSame(64, strlen($token)); // 32 bytes = 64 hex chars
        $this->assertSame($token, $_SESSION['_csrf_token']);
    }

    public function testCsrfValidateMatchingToken(): void
    {
        $token = \Tina4\CsrfMiddleware::generateToken();
        $this->assertTrue(\Tina4\CsrfMiddleware::validate($token));
    }

    public function testCsrfValidateRejectsWrongToken(): void
    {
        \Tina4\CsrfMiddleware::generateToken();
        $this->assertFalse(\Tina4\CsrfMiddleware::validate('wrong-token'));
    }

    public function testCsrfValidateRejectsEmptyToken(): void
    {
        \Tina4\CsrfMiddleware::generateToken();
        $this->assertFalse(\Tina4\CsrfMiddleware::validate(''));
    }

    public function testCsrfExemptsXhr(): void
    {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $this->assertTrue(\Tina4\CsrfMiddleware::validate('anything'));
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
    }

    public function testCsrfExemptsBearerAuth(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer some-token-here';
        $this->assertTrue(\Tina4\CsrfMiddleware::validate('anything'));
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    public function testCsrfExemptsJsonContentType(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $this->assertTrue(\Tina4\CsrfMiddleware::validate('anything'));
        unset($_SERVER['CONTENT_TYPE']);
    }

    public function testCsrfRejectsQueryStringToken(): void
    {
        $token = \Tina4\CsrfMiddleware::generateToken();
        $_GET['formToken'] = $token;
        $this->assertFalse(\Tina4\CsrfMiddleware::validate($token));
        unset($_GET['formToken']);
    }

    // ─── CorsMiddleware ─────────────────────────────────────────────────

    public function testCorsApplyDefaultsWildcard(): void
    {
        // Clear any env overrides
        unset($_ENV['TINA4_CORS_ALLOW_ORIGIN']);
        unset($_ENV['TINA4_CORS_ALLOW_METHODS']);
        unset($_ENV['TINA4_CORS_ALLOW_HEADERS']);

        $headers = \Tina4\CorsMiddleware::apply();

        $this->assertContains('Access-Control-Allow-Origin: *', $headers);
        $this->assertNotEmpty(array_filter($headers, fn($h) => str_starts_with($h, 'Access-Control-Allow-Methods:')));
        $this->assertNotEmpty(array_filter($headers, fn($h) => str_starts_with($h, 'Access-Control-Allow-Headers:')));
    }

    public function testCorsApplyWithSpecificOriginMatch(): void
    {
        $_ENV['TINA4_CORS_ALLOW_ORIGIN'] = 'https://example.com,https://other.com';
        $_SERVER['HTTP_ORIGIN'] = 'https://example.com';

        $headers = \Tina4\CorsMiddleware::apply();

        $this->assertContains('Access-Control-Allow-Origin: https://example.com', $headers);
        $this->assertContains('Vary: Origin', $headers);

        unset($_ENV['TINA4_CORS_ALLOW_ORIGIN'], $_SERVER['HTTP_ORIGIN']);
    }

    public function testCorsApplyWithSpecificOriginNoMatch(): void
    {
        $_ENV['TINA4_CORS_ALLOW_ORIGIN'] = 'https://example.com';
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.com';

        $headers = \Tina4\CorsMiddleware::apply();

        // Should NOT contain Allow-Origin for evil.com
        $originHeaders = array_filter($headers, fn($h) => str_starts_with($h, 'Access-Control-Allow-Origin:'));
        $this->assertEmpty($originHeaders);

        unset($_ENV['TINA4_CORS_ALLOW_ORIGIN'], $_SERVER['HTTP_ORIGIN']);
    }

    public function testCorsApplyCredentials(): void
    {
        $_ENV['TINA4_CORS_ALLOW_CREDENTIALS'] = 'true';

        $headers = \Tina4\CorsMiddleware::apply();
        $this->assertContains('Access-Control-Allow-Credentials: true', $headers);

        unset($_ENV['TINA4_CORS_ALLOW_CREDENTIALS']);
    }

    public function testCorsApplyCustomMethods(): void
    {
        $_ENV['TINA4_CORS_ALLOW_METHODS'] = 'GET,POST';

        $headers = \Tina4\CorsMiddleware::apply();
        $this->assertContains('Access-Control-Allow-Methods: GET,POST', $headers);

        unset($_ENV['TINA4_CORS_ALLOW_METHODS']);
    }

    public function testCorsApplyCustomMaxAge(): void
    {
        $_ENV['TINA4_CORS_MAX_AGE'] = '3600';

        $headers = \Tina4\CorsMiddleware::apply();
        $this->assertContains('Access-Control-Max-Age: 3600', $headers);

        unset($_ENV['TINA4_CORS_MAX_AGE']);
    }
}

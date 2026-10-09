<?php

use PHPUnit\Framework\TestCase;

/**
 * CORS header behaviour of Router::addCORS.
 * TINA4_ALLOW_ORIGINS is a constant, so each scenario runs in its own PHP process.
 */
class RouterCorsTest extends TestCase
{
    /**
     * @param string|null $originsPhp PHP expression for TINA4_ALLOW_ORIGINS, null to leave undefined
     * @param string|null $requestOrigin value of the Origin request header, null for none
     * @return array<int,string> headers returned by addCORS
     */
    private function runAddCors(?string $originsPhp, ?string $requestOrigin): array
    {
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        // The constant must be defined before the autoloader runs, as Initialize defines ["*"] by default.
        $code = '';
        if ($originsPhp !== null) {
            $code .= 'define("TINA4_ALLOW_ORIGINS", ' . $originsPhp . ');';
        }
        $code .= 'require ' . $autoload . ';';
        if ($requestOrigin !== null) {
            $code .= '$_SERVER["HTTP_ORIGIN"] = ' . var_export($requestOrigin, true) . ';';
        }
        $code .= '$r = new \Tina4\Router(); echo "\n@@" . json_encode($r->addCORS([]));';

        $cmd = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1';
        $out = shell_exec($cmd);
        $this->assertIsString($out);
        $pos = strrpos($out, '@@');
        $this->assertNotFalse($pos, 'subprocess output: ' . $out);
        $headers = json_decode(substr($out, $pos + 2), true);
        $this->assertIsArray($headers, 'subprocess output: ' . $out);
        return $headers;
    }

    private function find(array $headers, string $name): array
    {
        return array_values(array_filter($headers, fn($h) => stripos($h, $name . ':') === 0));
    }

    private function assertStaticHeaders(array $headers): void
    {
        $this->assertContains('Vary: Origin', $headers);
        $this->assertContains('Access-Control-Allow-Methods: GET, PUT, POST, PATCH, DELETE, OPTIONS', $headers);
        $this->assertContains('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With', $headers);
    }

    public function testWildcardSendsStarAndNoCredentials(): void
    {
        $h = $this->runAddCors('["*"]', 'https://a.example');
        $this->assertSame(['Access-Control-Allow-Origin: *'], $this->find($h, 'Access-Control-Allow-Origin'));
        $this->assertSame([], $this->find($h, 'Access-Control-Allow-Credentials'));
        $this->assertStaticHeaders($h);
    }

    public function testWildcardWithoutOriginHeaderSendsStarAndNoCredentials(): void
    {
        $h = $this->runAddCors('["*"]', null);
        $this->assertSame(['Access-Control-Allow-Origin: *'], $this->find($h, 'Access-Control-Allow-Origin'));
        $this->assertSame([], $this->find($h, 'Access-Control-Allow-Credentials'));
    }

    public function testWildcardInsideMixedListStillWildcard(): void
    {
        $h = $this->runAddCors('["https://a.example", "*"]', 'https://a.example');
        $this->assertSame(['Access-Control-Allow-Origin: *'], $this->find($h, 'Access-Control-Allow-Origin'));
        $this->assertSame([], $this->find($h, 'Access-Control-Allow-Credentials'));
    }

    public function testExplicitListMatchingOriginIsReflectedWithCredentials(): void
    {
        $h = $this->runAddCors('["https://a.example", "https://b.example"]', 'https://b.example');
        $this->assertSame(['Access-Control-Allow-Origin: https://b.example'], $this->find($h, 'Access-Control-Allow-Origin'));
        $this->assertSame(['Access-Control-Allow-Credentials: true'], $this->find($h, 'Access-Control-Allow-Credentials'));
        $this->assertStaticHeaders($h);
    }

    public function testExplicitListNonMatchingOriginGetsNothing(): void
    {
        $h = $this->runAddCors('["https://a.example", "https://b.example"]', 'https://evil.example');
        $this->assertSame([], $this->find($h, 'Access-Control-Allow-Origin'));
        $this->assertSame([], $this->find($h, 'Access-Control-Allow-Credentials'));
        $this->assertStaticHeaders($h);
    }

    public function testExplicitListMatchIsStrict(): void
    {
        foreach (['https://A.example', 'https://a.example/'] as $origin) {
            $h = $this->runAddCors('["https://a.example"]', $origin);
            $this->assertSame([], $this->find($h, 'Access-Control-Allow-Origin'), $origin);
            $this->assertSame([], $this->find($h, 'Access-Control-Allow-Credentials'), $origin);
        }
    }

    public function testExplicitListWithoutOriginHeaderGetsNothing(): void
    {
        $h = $this->runAddCors('["https://a.example"]', null);
        $this->assertSame([], $this->find($h, 'Access-Control-Allow-Origin'));
        $this->assertSame([], $this->find($h, 'Access-Control-Allow-Credentials'));
        $this->assertStaticHeaders($h);
    }
}

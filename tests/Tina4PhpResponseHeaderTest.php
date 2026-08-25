<?php

use PHPUnit\Framework\TestCase;
use Tina4\Tina4Php;

/**
 * Regression test for a real bug found 2026-08-25: customHeaders is commonly
 * passed as an associative array (e.g. ['Location' => '/some/path']), matching
 * Response::redirect()'s own documented shape (see ResponseTest::testRedirect).
 * That array gets merged with plain indexed "Name: Value" string entries
 * (CORS/X-Headers) elsewhere in Router::handleRoutes(), producing a mixed
 * array. Tina4Php::__toString()'s header-sending loop iterated by VALUE only,
 * silently discarding the array key for associative entries -- so
 * header('/some/path') got called instead of header('Location: /some/path'),
 * which PHP cannot interpret as a real header. Confirmed live: a real
 * commerce-bridge redirect sent a garbled header instead of Location, and the
 * browser never navigated anywhere.
 */
class Tina4PhpResponseHeaderTest extends TestCase
{
    public function testAssociativeHeaderEntryIsReconstructedWithItsName(): void
    {
        $this->assertSame(
            'Location: /redirected-target',
            Tina4Php::formatHeaderLine('Location', '/redirected-target'),
            'an associative ["Location" => "/path"] entry must become a real "Location: /path" header line'
        );
    }

    public function testAlreadyFormattedIndexedStringEntryPassesThroughUnchanged(): void
    {
        $this->assertSame(
            'Access-Control-Allow-Origin: *',
            Tina4Php::formatHeaderLine(0, 'Access-Control-Allow-Origin: *'),
            'plain indexed "Name: Value" string entries (CORS/X-Headers) must be sent exactly as-is'
        );
    }

    public function testDoesNotDoubleFormatAnAlreadyColonSeparatedAssociativeValue(): void
    {
        // Only the array KEY decides whether reconstruction happens -- an
        // int key always means "already a full string", regardless of
        // whether that string happens to contain a colon.
        $this->assertSame(
            'Content-Type: text/html; charset=utf-8',
            Tina4Php::formatHeaderLine(3, 'Content-Type: text/html; charset=utf-8')
        );
    }

    /**
     * Regression test for a second bug found alongside the header issue: an
     * empty body on a SUCCESS/redirect code (e.g. a 302 whose whole point is
     * an empty body plus a Location header) was being silently replaced by
     * Utilities::renderErrorTemplate(), which is only meaningful for genuine
     * error codes.
     */
    public function testEmptyBodyOnASuccessOrRedirectCodeIsNotTreatedAsAnError(): void
    {
        $this->assertFalse(Tina4Php::shouldRenderErrorTemplate('', 200), '200 with an empty body (e.g. 204-style) must not render an error template');
        $this->assertFalse(Tina4Php::shouldRenderErrorTemplate('', 204));
        $this->assertFalse(Tina4Php::shouldRenderErrorTemplate('', 302), '302 redirect intentionally has an empty body -- this is the exact bug found live');
        $this->assertFalse(Tina4Php::shouldRenderErrorTemplate('', 399));
    }

    public function testEmptyBodyOnARealErrorCodeStillRendersTheErrorTemplate(): void
    {
        $this->assertTrue(Tina4Php::shouldRenderErrorTemplate('', 400));
        $this->assertTrue(Tina4Php::shouldRenderErrorTemplate('', 404));
        $this->assertTrue(Tina4Php::shouldRenderErrorTemplate('', 500));
    }

    public function testNonEmptyBodyIsNeverReplacedRegardlessOfCode(): void
    {
        $this->assertFalse(Tina4Php::shouldRenderErrorTemplate('actual content', 404));
        $this->assertFalse(Tina4Php::shouldRenderErrorTemplate('actual content', 500));
    }
}

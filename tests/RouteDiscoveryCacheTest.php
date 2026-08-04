<?php

use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the shared route-discovery cache added to Tina4\Initialize.php.
 *
 * Background: route discovery runs a RecursiveDirectoryIterator over the app's src/ tree and
 * reflects over every declared class/function on every request to find #[Get]/#[Post]/etc
 * attribute routes. That is O(N^2) in file/class count and was responsible for a real
 * thundering-herd production incident (~470ms CPU per cold PHP-FPM worker, many workers cold
 * simultaneously after a deploy). tina4DiscoverRoutes() now wraps that scan with a phpfastcache
 * (Tina4\Cache) backed cache keyed off a hash of route-relevant file paths+mtimes, so the scan
 * only re-runs when the app's source actually changes.
 *
 * These tests call tina4DiscoverRoutes() directly against a throwaway fixture directory (rather
 * than the project's real src/), so route discovery can be exercised repeatedly, deterministically,
 * within a single process. They assert cache-hit/miss behaviour via a real side effect
 * ($tina4RouteDiscoveryScanCount, incremented once per actual scan) rather than trusting the
 * function merely returned without error.
 *
 * Every fixture class/function/route-path name embeds a per-test unique suffix. Class/function
 * names are global PHP symbols, and every test method here calls tina4DiscoverRoutes() in the
 * same process (require_once means the same file can safely be required again, but the same
 * *class name* declared in two different fixture files across two different test methods would
 * fatal with "Cannot redeclare class").
 */
class RouteDiscoveryCacheTest extends TestCase
{
    private string $fixtureDir;
    private string $suffix;
    private array $savedArrRoutes;
    private array $savedArrRouteIndex;

    protected function setUp(): void
    {
        $this->suffix = str_replace('.', '', uniqid('', true));
        $this->fixtureDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tina4_route_cache_test_' . $this->suffix;
        mkdir($this->fixtureDir, 0777, true);

        // $arrRoutes/$arrRouteIndex are real process-wide globals shared with other test files
        // (RoutingTest.php, RouterTest.php etc register routes into them directly). Snapshot and
        // restore around each test instead of wiping them, so this test can freely reset them to
        // simulate independent "requests" without disturbing other tests in the same run.
        $this->savedArrRoutes = $GLOBALS['arrRoutes'] ?? [];
        $this->savedArrRouteIndex = $GLOBALS['arrRouteIndex'] ?? [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['arrRoutes'] = $this->savedArrRoutes;
        $GLOBALS['arrRouteIndex'] = $this->savedArrRouteIndex;

        $this->removeDirectory($this->fixtureDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
        rmdir($dir);
    }

    private function controllerClass(): string
    {
        return "Tina4RouteDiscoveryCacheTestController_{$this->suffix}";
    }

    private function globalFunctionName(): string
    {
        return "tina4RouteDiscoveryCacheTestGlobalRoute_{$this->suffix}";
    }

    private function routePath(string $name): string
    {
        return "/__tina4_route_cache_test_{$this->suffix}__/{$name}";
    }

    /**
     * Writes fixture route files exercising: a plain #[Get] controller method, a #[Template]
     * attributed controller method (which must produce a Closure - the case that can never be
     * naively serialized into a cache), and a flat #[Post] global function route.
     *
     * The #[Template] handler is declared `static`: registerRouteFromAttributes() builds its
     * original callable as [$class, $method] (plain strings, no object instance - see
     * Initialize.php), and the wrapper closure invokes it via call_user_func_array(). That only
     * works for a static method or a plain function; this is a pre-existing constraint of the
     * #[Template] attribute unrelated to the caching work here, so the fixture matches it rather
     * than exercising an unrelated, already-existing limitation.
     */
    private function writeFixtureRoutes(): void
    {
        $class = $this->controllerClass();
        $path1 = $this->routePath('plain');
        $path2 = $this->routePath('templated');

        file_put_contents($this->fixtureDir . '/TestController.php', <<<PHP
<?php

class {$class}
{
    #[Get('{$path1}')]
    public function plainRoute()
    {
        return ['content' => 'plain-ok', 'httpCode' => 200, 'contentType' => 'text/plain'];
    }

    #[Template('unused.twig')]
    #[Get('{$path2}')]
    public static function templatedRoute()
    {
        // Returns a non-array on purpose: the #[Template] wrapper closure only calls
        // renderTemplate() for array results, so this exercises the wrapper without requiring a
        // real Twig template on disk, while still proving the callable is a rebuilt Closure.
        return 'templated-passthrough-ok';
    }
}
PHP
        );

        $function = $this->globalFunctionName();
        $path3 = $this->routePath('global-function');

        file_put_contents($this->fixtureDir . '/test_functions.php', <<<PHP
<?php

#[Post('{$path3}')]
function {$function}()
{
    return ['content' => 'function-ok', 'httpCode' => 200, 'contentType' => 'text/plain'];
}
PHP
        );
    }

    private function findRoute(string $path): ?array
    {
        foreach ($GLOBALS['arrRoutes'] as $route) {
            if ($route['routePath'] === $path) {
                return $route;
            }
        }
        return null;
    }

    private function scanCount(): int
    {
        return $GLOBALS['tina4RouteDiscoveryScanCount'] ?? 0;
    }

    public function testFirstRunScansAndSecondRunWithUnchangedFilesHitsCacheWithoutRescanning(): void
    {
        $this->writeFixtureRoutes();

        // --- "Request 1": cold, nothing cached yet for this file signature ---
        $countBefore = $this->scanCount();
        $GLOBALS['arrRoutes'] = [];
        tina4DiscoverRoutes($this->fixtureDir, true); // true = force cache on, regardless of TINA4_DEBUG
        $countAfterFirstRun = $this->scanCount();

        $this->assertSame(
            $countBefore + 1,
            $countAfterFirstRun,
            'First run against an unseen file signature must perform exactly one real scan.'
        );

        $plainRoute = $this->findRoute($this->routePath('plain'));
        $this->assertNotNull($plainRoute, 'Plain #[Get] route must be registered after the first scan.');
        $this->assertSame($this->controllerClass(), $plainRoute['class']);
        $this->assertSame('plainRoute', $plainRoute['function']);

        $templatedRoute = $this->findRoute($this->routePath('templated'));
        $this->assertNotNull($templatedRoute, '#[Template]-wrapped route must be registered after the first scan.');
        $this->assertInstanceOf(
            \Closure::class,
            $templatedRoute['function'],
            'A #[Template] attributed route must be wrapped in a Closure, not left as a plain method reference.'
        );
        $this->assertSame('templated-passthrough-ok', call_user_func($templatedRoute['function']));

        $functionRoute = $this->findRoute($this->routePath('global-function'));
        $this->assertNotNull($functionRoute, 'Flat #[Post] function route must be registered after the first scan.');
        $result = call_user_func($functionRoute['function']);
        $this->assertSame('function-ok', $result['content']);

        // --- "Request 2": simulate a fresh PHP-FPM request (fresh $arrRoutes) with the exact
        // same, untouched files. This must be served entirely from cache: no new scan. ---
        $GLOBALS['arrRoutes'] = [];
        tina4DiscoverRoutes($this->fixtureDir, true);
        $countAfterSecondRun = $this->scanCount();

        $this->assertSame(
            $countAfterFirstRun,
            $countAfterSecondRun,
            'Second run with an unchanged file signature must be a cache hit - the expensive scan must NOT run again.'
        );

        // And the replayed registrations must be behaviourally correct, not just "present".
        $plainRouteReplayed = $this->findRoute($this->routePath('plain'));
        $this->assertNotNull($plainRouteReplayed);
        $this->assertSame($this->controllerClass(), $plainRouteReplayed['class']);
        $this->assertSame('plainRoute', $plainRouteReplayed['function']);

        $templatedRouteReplayed = $this->findRoute($this->routePath('templated'));
        $this->assertNotNull($templatedRouteReplayed);
        $this->assertInstanceOf(
            \Closure::class,
            $templatedRouteReplayed['function'],
            'Cache-hit replay must rebuild the #[Template] wrapper Closure fresh (Closures cannot be serialized into the cache itself).'
        );
        $this->assertSame('templated-passthrough-ok', call_user_func($templatedRouteReplayed['function']));

        $functionRouteReplayed = $this->findRoute($this->routePath('global-function'));
        $this->assertNotNull($functionRouteReplayed);
        $resultReplayed = call_user_func($functionRouteReplayed['function']);
        $this->assertSame('function-ok', $resultReplayed['content']);
    }

    public function testTouchingAFileInvalidatesTheCacheAndForcesARescan(): void
    {
        $this->writeFixtureRoutes();

        $GLOBALS['arrRoutes'] = [];
        tina4DiscoverRoutes($this->fixtureDir, true);
        $countAfterFirstRun = $this->scanCount();

        // Confirm the second run really is a cache hit first (baseline for this test).
        $GLOBALS['arrRoutes'] = [];
        tina4DiscoverRoutes($this->fixtureDir, true);
        $this->assertSame($countAfterFirstRun, $this->scanCount(), 'Sanity check: unchanged files must hit cache.');

        // Now touch one fixture file's mtime forward - content is unchanged, but the signature
        // (path+mtime) changes, which must be enough to invalidate with zero manual steps.
        $touchedFile = $this->fixtureDir . '/TestController.php';
        touch($touchedFile, time() + 5);
        clearstatcache(true, $touchedFile);

        $GLOBALS['arrRoutes'] = [];
        tina4DiscoverRoutes($this->fixtureDir, true);
        $countAfterTouch = $this->scanCount();

        $this->assertSame(
            $countAfterFirstRun + 1,
            $countAfterTouch,
            'Touching a route file must invalidate the cache and force exactly one fresh rescan.'
        );

        // The rescanned result must still be functionally correct.
        $plainRoute = $this->findRoute($this->routePath('plain'));
        $this->assertNotNull($plainRoute);
    }

    public function testAddingARouteFileInvalidatesTheCacheAndTheNewRouteAppears(): void
    {
        $this->writeFixtureRoutes();

        $GLOBALS['arrRoutes'] = [];
        tina4DiscoverRoutes($this->fixtureDir, true);
        $countAfterFirstRun = $this->scanCount();

        $addedLaterPath = $this->routePath('added-later');
        $this->assertNull($this->findRoute($addedLaterPath), 'Route from a not-yet-added file must not exist yet.');

        $addedClass = "Tina4RouteDiscoveryCacheTestAddedLater_{$this->suffix}";
        file_put_contents($this->fixtureDir . '/AddedLater.php', <<<PHP
<?php

class {$addedClass}
{
    #[Get('{$addedLaterPath}')]
    public function addedLaterRoute()
    {
        return ['content' => 'added-later-ok', 'httpCode' => 200, 'contentType' => 'text/plain'];
    }
}
PHP
        );

        $GLOBALS['arrRoutes'] = [];
        tina4DiscoverRoutes($this->fixtureDir, true);
        $countAfterAdd = $this->scanCount();

        $this->assertSame(
            $countAfterFirstRun + 1,
            $countAfterAdd,
            'Adding a new route file must change the file-set signature and force exactly one fresh rescan.'
        );

        $addedRoute = $this->findRoute($addedLaterPath);
        $this->assertNotNull($addedRoute, 'Route from the newly added file must be discovered after the forced rescan.');
        $this->assertSame($addedClass, $addedRoute['class']);
        $this->assertSame('addedLaterRoute', $addedRoute['function']);
    }

    public function testExplicitCacheDisableOverrideAlwaysRescans(): void
    {
        $this->writeFixtureRoutes();

        $countBefore = $this->scanCount();

        $GLOBALS['arrRoutes'] = [];
        tina4DiscoverRoutes($this->fixtureDir, false); // false = force cache off, as TINA4_DEBUG would

        $GLOBALS['arrRoutes'] = [];
        tina4DiscoverRoutes($this->fixtureDir, false);

        $countAfter = $this->scanCount();

        $this->assertSame(
            $countBefore + 2,
            $countAfter,
            'With the cache explicitly disabled, every run must rescan - nothing may be read from or written to the cache.'
        );
    }

    public function testRealTina4DebugConstantBypassesTheCacheByDefault(): void
    {
        // No override passed: this exercises the REAL default wiring against whatever
        // TINA4_DEBUG/TINA4_ROUTE_DISCOVERY_CACHE are set to for this test run (this repo's
        // committed .env sets TINA4_DEBUG=true, so this proves debug mode really does bypass the
        // cache end-to-end, not just via the test-only override parameter used above).
        $this->assertTrue(
            defined('TINA4_DEBUG') && TINA4_DEBUG === true,
            'This test asserts real default behaviour and requires TINA4_DEBUG=true in the test environment (see .env).'
        );

        $this->writeFixtureRoutes();

        $countBefore = $this->scanCount();

        $GLOBALS['arrRoutes'] = [];
        tina4DiscoverRoutes($this->fixtureDir);

        $GLOBALS['arrRoutes'] = [];
        tina4DiscoverRoutes($this->fixtureDir);

        $countAfter = $this->scanCount();

        $this->assertSame(
            $countBefore + 2,
            $countAfter,
            'TINA4_DEBUG=true must bypass the route-discovery cache by default, with no override needed.'
        );
    }
}

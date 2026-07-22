<?php

declare(strict_types=1);

namespace Webrium\View\Tests;

use PHPUnit\Framework\TestCase;
use Webrium\View\Engine;
use Webrium\View\ViewException;

final class HybridCacheTest extends TestCase
{
    private string $rootDir;
    private string $viewDir;
    private string $compiledDir;
    private string $staticDir;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir() . '/webrium_hybrid_test_' . bin2hex(random_bytes(6));
        $this->viewDir = $this->rootDir . '/views';
        $this->compiledDir = $this->rootDir . '/compiled';
        $this->staticDir = $this->rootDir . '/static';

        mkdir($this->viewDir . '/a', 0775, true);
        mkdir($this->viewDir . '/b', 0775, true);
        mkdir($this->compiledDir, 0775, true);
        mkdir($this->staticDir, 0775, true);

        Engine::setViewDir($this->viewDir);
        Engine::setCompiledDir($this->compiledDir);
        Engine::setStaticDir($this->staticDir);
        Engine::setDefaultHybridCacheTtl(Engine::CACHE_A_WEEK);
        Engine::enableHybridCache(true);

        file_put_contents($this->viewDir . '/plain.php', '<p>@{{ $value }}</p>');
        file_put_contents($this->viewDir . '/component.php', '<aside>@{{ $label }}</aside>');
        file_put_contents(
            $this->viewDir . '/page.php',
            "@section('seo')<title>@{{ \$title }}</title>@endsection\n"
            . "@section('content')<article>@{{ \$body }}</article>@endsection"
        );
        file_put_contents(
            $this->viewDir . '/layout.php',
            '<html><head>@yield(\'seo\')</head><body><header>@{{ $header }}</header><main>@yield(\'content\')</main></body></html>'
        );
        file_put_contents($this->viewDir . '/missing-section.php', '<p>No section</p>');
        file_put_contents($this->viewDir . '/a/item.php', 'First @{{ $value }}');
        file_put_contents($this->viewDir . '/b/item.php', 'Second @{{ $value }}');
    }

    protected function tearDown(): void
    {
        Engine::enableHybridCache(true);
        Engine::setDefaultHybridCacheTtl(Engine::CACHE_A_WEEK);
        $this->removeDirectory($this->rootDir);
    }

    public function testLazyViewFactoryRunsOnlyOnCacheMiss(): void
    {
        $calls = 0;
        $factory = function () use (&$calls): array {
            $calls++;
            return ['value' => 'cached'];
        };

        $first = Engine::hybrid('plain', 'same-key', $factory, Engine::CACHE_AN_HOUR);
        $second = Engine::hybrid('plain', 'same-key', $factory, Engine::CACHE_AN_HOUR);

        self::assertSame(1, $calls);
        self::assertSame($first, $second);
        self::assertStringContainsString('<p>cached</p>', $second);
    }

    public function testDirectDataStillForcesRefresh(): void
    {
        Engine::hybrid('plain', 'refresh', ['value' => 'first'], Engine::CACHE_AN_HOUR);
        $second = Engine::hybrid('plain', 'refresh', ['value' => 'second'], Engine::CACHE_AN_HOUR);

        self::assertStringContainsString('<p>second</p>', $second);
        self::assertStringNotContainsString('<p>first</p>', $second);
    }

    public function testReadOnlyModeReturnsFreshCacheAndFalseForAMiss(): void
    {
        self::assertFalse(Engine::hybrid('plain', 'read-only', null));

        $written = Engine::hybrid(
            'plain',
            'read-only',
            fn (): array => ['value' => 'available'],
            Engine::CACHE_AN_HOUR
        );

        self::assertSame($written, Engine::hybrid('plain', 'read-only', null));
    }

    public function testArrayKeysAreStableRegardlessOfAssociativeOrder(): void
    {
        $calls = 0;
        $factory = function () use (&$calls): array {
            $calls++;
            return ['value' => 'stable'];
        };

        Engine::hybrid('plain', ['locale' => 'fa', 'slug' => 'home'], $factory, 60);
        Engine::hybrid('plain', ['slug' => 'home', 'locale' => 'fa'], $factory, 60);

        self::assertSame(1, $calls);
    }

    public function testViewsWithTheSameBasenameDoNotCollide(): void
    {
        $first = Engine::hybrid('a/item', 'shared', fn (): array => ['value' => 'A'], 60);
        $second = Engine::hybrid('b/item', 'shared', fn (): array => ['value' => 'B'], 60);

        self::assertStringContainsString('First A', $first);
        self::assertStringContainsString('Second B', $second);
        self::assertCount(2, $this->cacheFiles());
    }

    public function testHybridLayoutCachesFactoryAndCompleteLayout(): void
    {
        $calls = 0;
        $factory = function () use (&$calls): array {
            $calls++;
            return ['title' => 'Cached title', 'body' => 'Cached body', 'header' => 'Guest'];
        };

        $first = Engine::hybridLayout('layout', 'page', 'page', $factory, 60);
        $second = Engine::hybridLayout('layout', 'page', 'page', $factory, 60);

        self::assertSame(1, $calls);
        self::assertSame($first, $second);
        self::assertStringContainsString('<header>Guest</header>', $second);
        self::assertStringContainsString('<article>Cached body</article>', $second);
    }

    public function testHybridSectionSkipsFactoryAndKeepsLayoutDynamic(): void
    {
        $calls = 0;
        $factory = function () use (&$calls): array {
            $calls++;
            return ['title' => 'Unused here', 'body' => 'Expensive content'];
        };

        $firstSection = Engine::hybridSection('page', 'content', 'home-fa', $factory, 60);
        $firstPage = Engine::renderLayoutWithSections(
            'layout',
            ['seo' => '<title>First</title>', 'content' => $firstSection],
            ['header' => 'Guest']
        );

        $secondSection = Engine::hybridSection('page', 'content', 'home-fa', $factory, 60);
        $secondPage = Engine::renderLayoutWithSections(
            'layout',
            ['seo' => '<title>Second</title>', 'content' => $secondSection],
            ['header' => 'Signed in']
        );

        self::assertSame(1, $calls);
        self::assertSame($firstSection, $secondSection);
        self::assertStringContainsString('<header>Guest</header>', $firstPage);
        self::assertStringContainsString('<header>Signed in</header>', $secondPage);
        self::assertStringContainsString('<title>Second</title>', $secondPage);
        self::assertStringContainsString('<article>Expensive content</article>', $secondPage);
    }

    public function testHybridSectionRejectsMissingSection(): void
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage("did not define section 'content'");

        Engine::hybridSection(
            'missing-section',
            'content',
            'missing',
            fn (): array => [],
            60
        );
    }

    public function testHybridSectionRejectsEmptySectionName(): void
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('cannot be empty');

        Engine::hybridSection('page', '  ', 'empty', fn (): array => [], 60);
    }

    public function testHybridComponentUsesLazyFactory(): void
    {
        $calls = 0;
        $factory = function () use (&$calls): array {
            $calls++;
            return ['label' => 'Shared footer'];
        };

        $first = Engine::hybridComponent('component', ['footer', 'fa'], $factory, 60);
        $second = Engine::hybridComponent('component', ['footer', 'fa'], $factory, 60);

        self::assertSame(1, $calls);
        self::assertSame($first, $second);
        self::assertStringContainsString('<aside>Shared footer</aside>', $second);
    }

    public function testRememberCachesArbitraryControllerHtml(): void
    {
        $calls = 0;
        $renderer = function () use (&$calls): string {
            $calls++;
            return '<strong>Rendered once</strong>';
        };

        $first = Engine::remember('reports', ['monthly', 7], $renderer, 60);
        $second = Engine::remember('reports', ['monthly', 7], $renderer, 60);

        self::assertSame(1, $calls);
        self::assertSame($first, $second);
    }

    public function testRememberRejectsNonStringRendererOutput(): void
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('must return a string');

        Engine::remember('invalid', 'result', fn (): array => [], 60);
    }

    public function testHybridRejectsNonArrayDataFactoryOutput(): void
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('must return an array');

        Engine::hybrid('plain', 'invalid-factory', fn (): string => 'invalid', 60);
    }

    public function testCacheNoneBypassesAndRemovesExistingCache(): void
    {
        $calls = 0;
        $factory = function () use (&$calls): array {
            $calls++;
            return ['value' => 'call-' . $calls];
        };

        Engine::hybrid('plain', 'disabled-key', $factory, 60);
        $first = Engine::hybrid('plain', 'disabled-key', $factory, Engine::CACHE_NONE);
        $second = Engine::hybrid('plain', 'disabled-key', $factory, Engine::CACHE_NONE);

        self::assertSame(3, $calls);
        self::assertSame('<p>call-2</p>', $first);
        self::assertSame('<p>call-3</p>', $second);
        self::assertSame([], $this->cacheFiles());
    }

    public function testGlobalDisableBypassesCacheEvenWithoutDefaultTtl(): void
    {
        Engine::setDefaultHybridCacheTtl(null);
        Engine::enableHybridCache(false);
        $calls = 0;
        $factory = function () use (&$calls): array {
            $calls++;
            return ['value' => 'call-' . $calls];
        };

        $first = Engine::hybrid('plain', 'global-off', $factory);
        $second = Engine::hybrid('plain', 'global-off', $factory);

        self::assertSame(2, $calls);
        self::assertSame('<p>call-1</p>', $first);
        self::assertSame('<p>call-2</p>', $second);
        self::assertSame([], $this->cacheFiles());
        self::assertFalse(Engine::hybrid('plain', 'global-off', null));
    }

    public function testMissingDefaultTtlRequiresExplicitTtl(): void
    {
        Engine::setDefaultHybridCacheTtl(null);

        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('TTL must be provided');

        Engine::hybrid('plain', 'missing-ttl', fn (): array => ['value' => 'x']);
    }

    public function testNegativeTtlIsRejected(): void
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('cannot be negative');

        Engine::hybrid('plain', 'negative', fn (): array => ['value' => 'x'], -1);
    }

    public function testCacheUsesPreciseTimestampAndExpiredEntryRefreshes(): void
    {
        $calls = 0;
        $factory = function () use (&$calls): array {
            $calls++;
            return ['value' => 'call-' . $calls];
        };

        Engine::hybrid('plain', 'expiry', $factory, 60);
        $cacheFile = $this->cacheFiles()[0];
        $content = (string) file_get_contents($cacheFile);

        self::assertMatchesRegularExpression(
            '/\[ex:\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\]/',
            $content
        );

        $expired = (string) preg_replace('/\[ex:[^\]]+\]/', '[ex:2000-01-01T00:00:00+00:00]', $content);
        file_put_contents($cacheFile, $expired);

        $refreshed = Engine::hybrid('plain', 'expiry', $factory, 60);

        self::assertSame(2, $calls);
        self::assertStringContainsString('<p>call-2</p>', $refreshed);
    }

    public function testLegacyDateOnlyCacheRemainsReadable(): void
    {
        $calls = 0;
        $factory = function () use (&$calls): array {
            $calls++;
            return ['value' => 'legacy'];
        };

        $written = Engine::hybrid('plain', 'legacy', $factory, 60);
        $cacheFile = $this->cacheFiles()[0];
        $legacy = (string) preg_replace('/\[ex:[^\]]+\]/', '[ex:2999-12-31]', $written);
        file_put_contents($cacheFile, $legacy);

        $read = Engine::hybrid('plain', 'legacy', $factory, 60);

        self::assertSame(1, $calls);
        self::assertSame($legacy, $read);
    }

    public function testClearStaticsRemovesCacheAndLockFiles(): void
    {
        Engine::hybrid('plain', 'clear', fn (): array => ['value' => 'x'], 60);
        self::assertNotSame([], glob($this->staticDir . '/*') ?: []);

        Engine::clearStatics();

        self::assertSame([], glob($this->staticDir . '/*') ?: []);
    }

    public function testConcurrentMissesRunRendererOnlyOnce(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('The pcntl extension is required for the concurrency test.');
        }

        $counterFile = $this->rootDir . '/renderer-calls.log';
        $pids = [];

        for ($i = 0; $i < 4; $i++) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid, 'Unable to fork cache test worker.');

            if ($pid === 0) {
                try {
                    $result = Engine::remember(
                        'concurrent',
                        'same-entry',
                        function () use ($counterFile): string {
                            file_put_contents($counterFile, "rendered\n", FILE_APPEND | LOCK_EX);
                            usleep(100_000);
                            return '<p>shared</p>';
                        },
                        60
                    );

                    exit(str_contains($result, '<p>shared</p>') ? 0 : 2);
                } catch (\Throwable) {
                    exit(3);
                }
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }

        $calls = file($counterFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(1, $calls);
        self::assertCount(1, $this->cacheFiles());
    }

    /** @return list<string> */
    private function cacheFiles(): array
    {
        $files = glob($this->staticDir . '/*.html') ?: [];
        sort($files);
        return array_values($files);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}

<?php

declare(strict_types=1);

namespace Webrium\View\Tests;

use PHPUnit\Framework\TestCase;
use Webrium\View\Engine;
use Webrium\View\ViewException;

/**
 * Regression specifications for source-file and source-line diagnostics.
 *
 * These tests cover source-file and source-line mapping across every rendering
 * entry point, including templates whose compilation changes line counts.
 */
final class ViewErrorDiagnosticsTest extends TestCase
{
    private string $rootDir;
    private string $viewDir;
    private string $compiledDir;
    private string $staticDir;
    private int $outputBufferLevel;

    protected function setUp(): void
    {
        $this->outputBufferLevel = ob_get_level();
        $this->rootDir = sys_get_temp_dir() . '/webrium_view_errors_' . bin2hex(random_bytes(6));
        $this->viewDir = $this->rootDir . '/views';
        $this->compiledDir = $this->rootDir . '/compiled';
        $this->staticDir = $this->rootDir . '/static';

        mkdir($this->viewDir, 0775, true);
        mkdir($this->compiledDir, 0775, true);
        mkdir($this->staticDir, 0775, true);

        Engine::setViewDir($this->viewDir);
        Engine::setCompiledDir($this->compiledDir);
        Engine::setStaticDir($this->staticDir);
        Engine::enableHybridCache(true);
        Engine::setDefaultHybridCacheTtl(60);
        Engine::allowRawPhpDirective(true);

        $this->putView('runtime-error', <<<'VIEW'
<p>line 1</p>
<p>line 2</p>
<p>line 3</p>
@php(webrium_missing_function_for_diagnostics())
VIEW);

        $this->putView('warning', <<<'VIEW'
<p>line 1</p>
<p>line 2</p>
<p>line 3</p>
@php(trigger_error('diagnostic warning', E_USER_WARNING))
VIEW);

        $this->putView('component-error', <<<'VIEW'
<span>line 1</span>
<span>line 2</span>
@php(webrium_missing_component_function())
VIEW);

        $this->putView('component-parent', <<<'VIEW'
<main>
    @component('component-error')
</main>
VIEW);

        $this->putView('layout', <<<'VIEW'
<html>
<body>
@yield('content')
</body>
</html>
VIEW);

        $this->putView('layout-error', <<<'VIEW'
<html>
<body>
@yield('content')
@php(webrium_missing_layout_function())
</body>
</html>
VIEW);

        $this->putView('section-error', <<<'VIEW'
@section('content')
<p>line 2</p>
@php(webrium_missing_section_function())
@endsection
VIEW);

        $this->putView('safe-section', <<<'VIEW'
@section('content')
<p>safe</p>
@endsection
VIEW);

        $this->putView('parser-error', '<div><span>unclosed</span>');

        $this->putView('multiline-control-error', <<<'VIEW'
<p>line 1</p>
<div
    class="card"
    w-if="webrium_missing_multiline_condition()">
    content
</div>
VIEW);

        $this->putView('multiline-directive-error', <<<'VIEW'
<p>line 1</p>
@php(
    webrium_missing_multiline_directive()
)
VIEW);

        $this->putView('php-block-error', <<<'VIEW'
@php
$value = 1;
webrium_missing_php_block_function();
@endphp
VIEW);

        $this->putView('raw-php-error', <<<'VIEW'
<div
    class="card">
    content
</div>
<?php
webrium_missing_raw_php_function();
?>
VIEW);

        $this->putView('nested-parser-error', <<<'VIEW'
<p>line 1</p>
<p>line 2</p>
<section>unclosed
VIEW);
    }

    protected function tearDown(): void
    {
        while (ob_get_level() > $this->outputBufferLevel) {
            ob_end_clean();
        }

        $this->removeDirectory($this->rootDir);
    }

    public function testDirectRenderReportsOriginalRuntimeErrorLine(): void
    {
        $exception = $this->capture(fn () => Engine::render('runtime-error'));

        self::assertSame($this->viewPath('runtime-error'), $exception->getOriginalView());
        self::assertSame(4, $exception->getOriginalLine());
        self::assertSame(4, $exception->getPrevious()?->getLine());
    }

    public function testPhpWarningReportsOriginalLine(): void
    {
        $exception = $this->capture(fn () => Engine::render('warning'));

        self::assertSame($this->viewPath('warning'), $exception->getOriginalView());
        self::assertSame(4, $exception->getOriginalLine());
        self::assertSame(4, $exception->getPrevious()?->getLine());
    }

    public function testComponentReportsComponentSourceLine(): void
    {
        $exception = $this->capture(fn () => Engine::render('component-parent'));

        self::assertSame($this->viewPath('component-error'), $exception->getOriginalView());
        self::assertSame(3, $exception->getPrevious()?->getLine());
    }

    public function testLayoutReportsChildSourceLine(): void
    {
        $exception = $this->capture(
            fn () => Engine::renderLayout('layout', 'runtime-error')
        );

        self::assertSame($this->viewPath('runtime-error'), $exception->getOriginalView());
        self::assertSame(4, $exception->getPrevious()?->getLine());
    }

    public function testLayoutReportsLayoutSourceLine(): void
    {
        $exception = $this->capture(
            fn () => Engine::renderLayout('layout-error', 'safe-section')
        );

        self::assertSame($this->viewPath('layout-error'), $exception->getOriginalView());
        self::assertSame(4, $exception->getPrevious()?->getLine());
    }

    public function testHybridLayoutReportsOriginalSourceLine(): void
    {
        $exception = $this->capture(
            fn () => Engine::hybridLayout(
                'layout',
                'runtime-error',
                'runtime-error',
                fn (): array => [],
                60
            )
        );

        self::assertSame($this->viewPath('runtime-error'), $exception->getOriginalView());
        self::assertSame(4, $exception->getPrevious()?->getLine());
    }

    public function testHybridViewReportsOriginalSourceLine(): void
    {
        $exception = $this->capture(
            fn () => Engine::hybrid(
                'runtime-error',
                'runtime-error',
                fn (): array => [],
                60
            )
        );

        self::assertSame($this->viewPath('runtime-error'), $exception->getOriginalView());
        self::assertSame(4, $exception->getPrevious()?->getLine());
    }

    public function testHybridSectionReportsOriginalSourceLine(): void
    {
        $exception = $this->capture(
            fn () => Engine::hybridSection(
                'section-error',
                'content',
                'section-error',
                fn (): array => [],
                60
            )
        );

        self::assertSame($this->viewPath('section-error'), $exception->getOriginalView());
        self::assertSame(3, $exception->getPrevious()?->getLine());
    }

    public function testHybridComponentReportsComponentSourceLine(): void
    {
        $exception = $this->capture(
            fn () => Engine::hybridComponent(
                'component-error',
                'component-error',
                fn (): array => [],
                60
            )
        );

        self::assertSame($this->viewPath('component-error'), $exception->getOriginalView());
        self::assertSame(3, $exception->getPrevious()?->getLine());
    }

    public function testSectionRuntimeErrorDoesNotLeakOutputBuffers(): void
    {
        $before = ob_get_level();
        $this->capture(
            fn () => Engine::hybridSection(
                'section-error',
                'content',
                'buffer',
                fn (): array => [],
                60
            )
        );

        self::assertSame($before, ob_get_level());
    }

    public function testParserFailureCarriesOriginalViewPath(): void
    {
        $exception = $this->capture(fn () => Engine::render('parser-error'));

        self::assertSame($this->viewPath('parser-error'), $exception->getOriginalView());
        self::assertSame(1, $exception->getOriginalLine());
        self::assertSame(1, $exception->getPrevious()?->getLine());
    }

    public function testMultilineControlAttributeUsesItsSourceLine(): void
    {
        $exception = $this->capture(fn () => Engine::render('multiline-control-error'));

        self::assertSame(4, $exception->getOriginalLine());
        self::assertSame(4, $exception->getPrevious()?->getLine());
    }

    public function testMultilineDirectiveUsesExpressionSourceLine(): void
    {
        $exception = $this->capture(fn () => Engine::render('multiline-directive-error'));

        self::assertSame(3, $exception->getOriginalLine());
        self::assertSame(3, $exception->getPrevious()?->getLine());
    }

    public function testPhpBlockUsesFailingBodyLine(): void
    {
        $exception = $this->capture(fn () => Engine::render('php-block-error'));

        self::assertSame(3, $exception->getOriginalLine());
        self::assertSame(3, $exception->getPrevious()?->getLine());
    }

    public function testRawPhpUsesFailingBodyLineAfterMultilineHtml(): void
    {
        $exception = $this->capture(fn () => Engine::render('raw-php-error'));

        self::assertSame(6, $exception->getOriginalLine());
        self::assertSame(6, $exception->getPrevious()?->getLine());
    }

    public function testParserFailureCarriesItsSourceLine(): void
    {
        $exception = $this->capture(fn () => Engine::render('nested-parser-error'));

        self::assertSame($this->viewPath('nested-parser-error'), $exception->getOriginalView());
        self::assertSame(3, $exception->getOriginalLine());
        self::assertSame(3, $exception->getPrevious()?->getLine());
    }

    public function testCompiledTemplateStoresSourceMapWithoutLeakingMarkers(): void
    {
        $this->capture(fn () => Engine::render('runtime-error'));

        $compiledFiles = glob($this->compiledDir . '/*.php') ?: [];
        $mapFiles = glob($this->compiledDir . '/*.map.json') ?: [];

        self::assertNotEmpty($compiledFiles);
        self::assertNotEmpty($mapFiles);
        self::assertStringNotContainsString(
            '__WEBRIUM_SOURCE_LINE_',
            (string) file_get_contents($compiledFiles[0])
        );
    }

    public function testMissingViewMessageExplainsWhatWasNotFound(): void
    {
        $exception = $this->capture(fn () => Engine::render('missing-view'));

        self::assertStringContainsString('View file does not exist', $exception->getMessage());
        self::assertStringContainsString('missing-view.php', $exception->getMessage());
    }

    private function capture(callable $operation): ViewException
    {
        try {
            $operation();
        } catch (ViewException $exception) {
            return $exception;
        }

        self::fail('Expected a ViewException to be thrown.');
    }

    private function putView(string $name, string $content): void
    {
        file_put_contents($this->viewPath($name), $content);
    }

    private function viewPath(string $name): string
    {
        return $this->viewDir . '/' . $name . '.php';
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

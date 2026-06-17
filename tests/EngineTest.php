<?php

declare(strict_types=1);

namespace Webrium\View\Tests;

use PHPUnit\Framework\TestCase;
use Webrium\View\Engine;
use Webrium\View\ViewException;

/**
 * Unit tests for Webrium\View\Engine, focused on the view path resolution
 * behaviour (automatic ".php" extension when not explicitly provided).
 */
final class EngineTest extends TestCase
{
    private string $viewDir;
    private string $compiledDir;

    protected function setUp(): void
    {
        $this->viewDir     = sys_get_temp_dir() . '/webrium_view_test_' . uniqid();
        $this->compiledDir = $this->viewDir . '/compiled';

        mkdir($this->viewDir, 0775, true);
        mkdir($this->compiledDir, 0775, true);

        Engine::setViewDir($this->viewDir);
        Engine::setCompiledDir($this->compiledDir);

        file_put_contents($this->viewDir . '/test.php', 'Hello <?= $name ?? "world" ?>');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->viewDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /** Explicit ".php" extension keeps working as before. */
    public function testRenderWithExplicitPhpExtension(): void
    {
        $output = Engine::render('test.php');

        $this->assertSame('Hello world', $output);
    }

    /** Omitting the extension should automatically resolve to "<view>.php". */
    public function testRenderWithoutExtensionAutoAppendsPhp(): void
    {
        $output = Engine::render('test');

        $this->assertSame('Hello world', $output);
    }

    /** Both forms must render identical output for the same view + data. */
    public function testRenderWithAndWithoutExtensionProduceSameOutput(): void
    {
        $data = ['name' => 'Webrium'];

        $withExtension    = Engine::render('test.php', $data);
        $withoutExtension = Engine::render('test', $data);

        $this->assertSame($withExtension, $withoutExtension);
    }

    /** Auto-extension must also work for views inside subdirectories. */
    public function testRenderWithoutExtensionWorksForNestedViews(): void
    {
        mkdir($this->viewDir . '/pages', 0775, true);
        file_put_contents($this->viewDir . '/pages/about.php', 'About page');

        $output = Engine::render('pages/about');

        $this->assertSame('About page', $output);
    }

    /** A genuinely missing view must still throw, with or without extension. */
    public function testRenderMissingViewThrowsException(): void
    {
        $this->expectException(ViewException::class);

        Engine::render('does-not-exist');
    }
}
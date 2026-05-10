<?php

namespace Webrium\View\EditorJs\Tests;

use PHPUnit\Framework\TestCase;
use Webrium\View\EditorJs\EditorJsParser;

class EditorJsParserTest extends TestCase
{
    private EditorJsParser $parser;

    protected function setUp(): void
    {
        $this->parser = new EditorJsParser();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function editorJson(array $blocks): string
    {
        return json_encode(['time' => 0, 'version' => '2.28.0', 'blocks' => $blocks]);
    }

    // -------------------------------------------------------------------------
    // Core
    // -------------------------------------------------------------------------

    public function testParseAcceptsArray(): void
    {
        $data = ['blocks' => [['type' => 'delimiter', 'data' => []]]];
        $html = $this->parser->parse($data);
        $this->assertStringContainsString('<hr', $html);
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse('not-json');
    }

    public function testMissingBlocksKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse('{"time":0}');
    }

    public function testUnknownBlockTypeIsIgnored(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'unknownFutureTool', 'data' => ['text' => 'hello']],
        ]));
        $this->assertSame('', $html);
    }

    public function testEmptyBlocksProducesEmptyString(): void
    {
        $html = $this->parser->parse($this->editorJson([]));
        $this->assertSame('', $html);
    }

    // -------------------------------------------------------------------------
    // paragraph
    // -------------------------------------------------------------------------

    public function testParagraph(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'paragraph', 'data' => ['text' => 'Hello <b>world</b>']],
        ]));
        $this->assertStringContainsString('<p>', $html);
        $this->assertStringContainsString('Hello', $html);
        $this->assertStringContainsString('<b>world</b>', $html);
    }

    public function testEmptyParagraphIsSkipped(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'paragraph', 'data' => ['text' => '']],
        ]));
        $this->assertSame('', $html);
    }

    public function testParagraphSanitizesScriptTag(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'paragraph', 'data' => ['text' => '<script>alert(1)</script>safe']],
        ]));
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('safe', $html);
    }

    public function testParagraphCustomClass(): void
    {
        $parser = new EditorJsParser(['paragraph' => ['class' => 'prose']]);
        $html   = $parser->parse($this->editorJson([
            ['type' => 'paragraph', 'data' => ['text' => 'Hi']],
        ]));
        $this->assertStringContainsString('class="prose"', $html);
    }

    // -------------------------------------------------------------------------
    // header
    // -------------------------------------------------------------------------

    public function testHeaderLevel(): void
    {
        foreach ([1, 2, 3, 4, 5, 6] as $level) {
            $html = $this->parser->parse($this->editorJson([
                ['type' => 'header', 'data' => ['text' => 'Title', 'level' => $level]],
            ]));
            $this->assertStringContainsString("<h{$level}", $html);
            $this->assertStringContainsString("</h{$level}>", $html);
        }
    }

    public function testHeaderDefaultsToH2(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'header', 'data' => ['text' => 'Title']],
        ]));
        $this->assertStringContainsString('<h2', $html);
    }

    public function testHeaderLevelClamped(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'header', 'data' => ['text' => 'Title', 'level' => 99]],
        ]));
        $this->assertStringContainsString('<h6', $html);
    }

    // -------------------------------------------------------------------------
    // list
    // -------------------------------------------------------------------------

    public function testUnorderedList(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'list', 'data' => ['style' => 'unordered', 'items' => ['Foo', 'Bar']]],
        ]));
        $this->assertStringContainsString('<ul', $html);
        $this->assertStringContainsString('<li', $html);
        $this->assertStringContainsString('Foo', $html);
    }

    public function testOrderedList(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'list', 'data' => ['style' => 'ordered', 'items' => ['First', 'Second']]],
        ]));
        $this->assertStringContainsString('<ol', $html);
    }

    public function testEmptyListIsSkipped(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'list', 'data' => ['style' => 'unordered', 'items' => []]],
        ]));
        $this->assertSame('', $html);
    }

    // -------------------------------------------------------------------------
    // image
    // -------------------------------------------------------------------------

    public function testImage(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'image', 'data' => [
                'file'    => ['url' => 'https://example.com/img.jpg'],
                'caption' => 'A photo',
            ]],
        ]));
        $this->assertStringContainsString('<figure', $html);
        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('https://example.com/img.jpg', $html);
        $this->assertStringContainsString('<figcaption', $html);
        $this->assertStringContainsString('A photo', $html);
    }

    public function testImageWithBorderClass(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'image', 'data' => [
                'file'       => ['url' => 'https://example.com/img.jpg'],
                'withBorder' => true,
            ]],
        ]));
        $this->assertStringContainsString('image--bordered', $html);
    }

    public function testImageWithStretchedClass(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'image', 'data' => [
                'file'      => ['url' => 'https://example.com/img.jpg'],
                'stretched' => true,
            ]],
        ]));
        $this->assertStringContainsString('image--stretched', $html);
    }

    public function testImageWithoutSrcIsSkipped(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'image', 'data' => ['file' => [], 'caption' => 'No src']],
        ]));
        $this->assertSame('', $html);
    }

    // -------------------------------------------------------------------------
    // quote
    // -------------------------------------------------------------------------

    public function testQuote(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'quote', 'data' => [
                'text'      => 'Great quote',
                'caption'   => 'Author',
                'alignment' => 'center',
            ]],
        ]));
        $this->assertStringContainsString('<blockquote', $html);
        $this->assertStringContainsString('Great quote', $html);
        $this->assertStringContainsString('<cite', $html);
        $this->assertStringContainsString('Author', $html);
    }

    // -------------------------------------------------------------------------
    // code
    // -------------------------------------------------------------------------

    public function testCode(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'code', 'data' => ['code' => 'echo "hi";', 'language' => 'php']],
        ]));
        $this->assertStringContainsString('<pre', $html);
        $this->assertStringContainsString('<code', $html);
        $this->assertStringContainsString('echo &quot;hi&quot;;', $html);
        $this->assertStringContainsString('data-language="php"', $html);
    }

    public function testCodeEscapesHtml(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'code', 'data' => ['code' => '<script>alert(1)</script>']],
        ]));
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // -------------------------------------------------------------------------
    // table
    // -------------------------------------------------------------------------

    public function testTableWithHeadings(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'table', 'data' => [
                'withHeadings' => true,
                'content'      => [['Name', 'Age'], ['Alice', '30'], ['Bob', '25']],
            ]],
        ]));
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('<thead', $html);
        $this->assertStringContainsString('<th', $html);
        $this->assertStringContainsString('<tbody', $html);
        $this->assertStringContainsString('<td', $html);
    }

    public function testTableWithoutHeadings(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'table', 'data' => [
                'withHeadings' => false,
                'content'      => [['A', 'B'], ['C', 'D']],
            ]],
        ]));
        $this->assertStringContainsString('<table', $html);
        $this->assertStringNotContainsString('<thead', $html);
        $this->assertStringContainsString('<td', $html);
    }

    // -------------------------------------------------------------------------
    // delimiter
    // -------------------------------------------------------------------------

    public function testDelimiter(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'delimiter', 'data' => []],
        ]));
        $this->assertStringContainsString('<hr', $html);
    }

    // -------------------------------------------------------------------------
    // embed
    // -------------------------------------------------------------------------

    public function testEmbed(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'embed', 'data' => [
                'service' => 'youtube',
                'embed'   => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
                'caption' => 'Cool video',
                'width'   => 560,
                'height'  => 315,
            ]],
        ]));
        $this->assertStringContainsString('<iframe', $html);
        $this->assertStringContainsString('youtube.com/embed', $html);
        $this->assertStringContainsString('embed--youtube', $html);
        $this->assertStringContainsString('Cool video', $html);
    }

    public function testEmbedWithoutSrcIsSkipped(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'embed', 'data' => ['service' => 'youtube', 'embed' => '']],
        ]));
        $this->assertSame('', $html);
    }

    // -------------------------------------------------------------------------
    // warning
    // -------------------------------------------------------------------------

    public function testWarning(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'warning', 'data' => ['title' => 'Watch out!', 'message' => 'Something is wrong.']],
        ]));
        $this->assertStringContainsString('cdx-warning', $html);
        $this->assertStringContainsString('Watch out!', $html);
        $this->assertStringContainsString('Something is wrong.', $html);
    }

    // -------------------------------------------------------------------------
    // raw
    // -------------------------------------------------------------------------

    public function testRaw(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'raw', 'data' => ['html' => '<div class="custom">Raw HTML</div>']],
        ]));
        $this->assertStringContainsString('<div class="custom">Raw HTML</div>', $html);
    }

    // -------------------------------------------------------------------------
    // checklist
    // -------------------------------------------------------------------------

    public function testChecklist(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'checklist', 'data' => [
                'items' => [
                    ['text' => 'Done',    'checked' => true],
                    ['text' => 'Pending', 'checked' => false],
                ],
            ]],
        ]));
        $this->assertStringContainsString('<ul', $html);
        $this->assertStringContainsString('checked', $html);
        $this->assertStringContainsString('Done', $html);
        $this->assertStringContainsString('Pending', $html);
    }

    // -------------------------------------------------------------------------
    // linkTool
    // -------------------------------------------------------------------------

    public function testLinkTool(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'linkTool', 'data' => [
                'link' => 'https://example.com',
                'meta' => ['title' => 'Example', 'description' => 'A site'],
            ]],
        ]));
        $this->assertStringContainsString('<a', $html);
        $this->assertStringContainsString('https://example.com', $html);
        $this->assertStringContainsString('Example', $html);
        $this->assertStringContainsString('A site', $html);
    }

    // -------------------------------------------------------------------------
    // attaches
    // -------------------------------------------------------------------------

    public function testAttaches(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'attaches', 'data' => [
                'title' => 'Report PDF',
                'file'  => ['url' => 'https://example.com/report.pdf', 'size' => 2048000],
            ]],
        ]));
        $this->assertStringContainsString('<a', $html);
        $this->assertStringContainsString('https://example.com/report.pdf', $html);
        $this->assertStringContainsString('Report PDF', $html);
        $this->assertStringContainsString('MB', $html);
    }

    // -------------------------------------------------------------------------
    // Custom handler
    // -------------------------------------------------------------------------

    public function testRegisterCustomBlockHandler(): void
    {
        $this->parser->registerBlock('myTool', function (array $data): string {
            return '<div class="my-tool">' . htmlspecialchars($data['value'] ?? '') . '</div>' . "\n";
        });

        $html = $this->parser->parse($this->editorJson([
            ['type' => 'myTool', 'data' => ['value' => 'Custom value']],
        ]));

        $this->assertStringContainsString('<div class="my-tool">Custom value</div>', $html);
    }

    public function testCustomHandlerOverridesBuiltIn(): void
    {
        $this->parser->registerBlock('paragraph', function (): string {
            return '<section>overridden</section>' . "\n";
        });

        $html = $this->parser->parse($this->editorJson([
            ['type' => 'paragraph', 'data' => ['text' => 'original']],
        ]));

        $this->assertStringContainsString('<section>overridden</section>', $html);
        $this->assertStringNotContainsString('<p>', $html);
    }

    // -------------------------------------------------------------------------
    // Sanitization toggle
    // -------------------------------------------------------------------------

    public function testSanitizeDisabledAllowsAllTags(): void
    {
        $parser = new EditorJsParser([], false);
        $html   = $parser->parse($this->editorJson([
            ['type' => 'paragraph', 'data' => ['text' => '<custom-tag>allowed</custom-tag>']],
        ]));
        $this->assertStringContainsString('<custom-tag>', $html);
    }

    // -------------------------------------------------------------------------
    // Multiple blocks
    // -------------------------------------------------------------------------

    public function testMultipleBlocksInOrder(): void
    {
        $html = $this->parser->parse($this->editorJson([
            ['type' => 'header',    'data' => ['text' => 'Title', 'level' => 1]],
            ['type' => 'paragraph', 'data' => ['text' => 'Body']],
            ['type' => 'delimiter', 'data' => []],
        ]));

        $h1Pos  = strpos($html, '<h1');
        $pPos   = strpos($html, '<p>');
        $hrPos  = strpos($html, '<hr');

        $this->assertNotFalse($h1Pos);
        $this->assertNotFalse($pPos);
        $this->assertNotFalse($hrPos);
        $this->assertLessThan($pPos, $h1Pos);
        $this->assertLessThan($hrPos, $pPos);
    }
}
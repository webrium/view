<?php
declare(strict_types=1);

namespace Webrium\View\Tests;

use PHPUnit\Framework\TestCase;
use Webrium\View\Parser;
use Webrium\View\Engine;
use Webrium\View\ViewTemplateException;

/**
 * Unit tests for the Zog template Parser (DOM-less version).
 *
 * These tests focus on:
 *  - Inline directives: @{{ }}, @raw(), @php(), @json(), @tojs(), @section(), @yield(), @component()
 *  - DOM-level directives: w-if / w-else-if / w-else, w-for, w-skip
 *  - Special handling for <script> and <style>
 *  - Error handling for invalid HTML (unclosed tags, unexpected closing tags)
 */
final class ParserTest extends TestCase
{
    protected function setUp(): void
    {
        // Make sure raw PHP directive is allowed by default.
        if (method_exists(Engine::class, 'allowRawPhpDirective')) {
            Engine::allowRawPhpDirective(true);
        }
    }

    public function testPlainHtmlWithDoctypeIsPreserved(): void
    {
        $template = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Test Page</title>
</head>
<body>
    <div id="app"></div>
</body>
</html>
HTML;

        $compiled = Parser::compile($template);

        // Basic sanity: doctype and structure must still be there.
        $this->assertStringContainsString('<!DOCTYPE html>', $compiled);
        $this->assertStringContainsString('<html lang="en">', $compiled);
        $this->assertStringContainsString('<div id="app"></div>', $compiled);

        // No PHP code should be injected for plain HTML.
        $this->assertStringNotContainsString('<?php', $compiled);
    }

    public function testTextOnlyTemplateWithEscapedDirective(): void
    {
        $template = 'Hello @{{ $name }}!';

        $compiled = Parser::compile($template);

        $this->assertSame(
            "Hello <?php echo htmlspecialchars(\$name, ENT_QUOTES, 'UTF-8'); ?>!",
            $compiled
        );
    }

    public function testEscapedDirectiveInsideHtmlTag(): void
    {
        $template = '<p>Hello @{{ $userName }}</p>';

        $compiled = Parser::compile($template);

        $this->assertStringContainsString(
            "Hello <?php echo htmlspecialchars(\$userName, ENT_QUOTES, 'UTF-8'); ?>",
            $compiled
        );
        $this->assertStringContainsString('<p>', $compiled);
        $this->assertStringContainsString('</p>', $compiled);
    }

    public function testRawDirectiveWithExplicitDollarSign(): void
    {
        $template = 'Value: @raw($totalAmount)';

        $compiled = Parser::compile($template);

        $this->assertStringContainsString(
            'Value: <?php echo $totalAmount; ?>',
            $compiled
        );
    }

    public function testJsonDirectiveWithExplicitDollarSign(): void
    {
        $template = '@json($products)';

        $compiled = Parser::compile($template);

        $this->assertStringContainsString(
            "<?php echo json_encode(\$products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>",
            $compiled
        );
    }


 public function testAttributeInterpolationWithAtCurlyEchoAndJson(): void
    {
        $template = ''
            . '<a href="docs/@{{ $page }}">@{{ $link }}</a>'
            . '<a href="@{{ $url }}">Link</a>'
            . '<div data-json="@json($payload)"></div>';

        $compiled = Parser::compile($template);

        // 1) href="docs/@{{ $page }}"
        $this->assertStringContainsString(
            'href="docs/<?php echo htmlspecialchars($page, ENT_QUOTES, \'UTF-8\'); ?>"',
            $compiled,
            'Attribute interpolation for href="docs/@{{ $page }}" did not compile as expected.'
        );

        // 2) href="@{{ $url }}"
        $this->assertStringContainsString(
            'href="<?php echo htmlspecialchars($url, ENT_QUOTES, \'UTF-8\'); ?>"',
            $compiled,
            'Attribute interpolation for href="@{{ $url }}" did not compile as expected.'
        );

        // 3) data-json="@json($payload)"
        $this->assertStringContainsString(
            'data-json="<?php echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>"',
            $compiled,
            'Attribute interpolation for data-json="@json($payload)" did not compile as expected.'
        );
    }

    public function testJsonDirectiveWithComplexExpression(): void
    {
        $template = '@json($user["profile"]["settings"])';

        $compiled = Parser::compile($template);

        $this->assertStringContainsString(
            "<?php echo json_encode(\$user[\"profile\"][\"settings\"], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>",
            $compiled
        );
    }

    public function testSectionYieldAndEndsectionDirectives(): void
    {
        $template = <<<'TPL'
@section('title')
    My Page
@endsection

<h1>@yield('title')</h1>
TPL;

        $compiled = Parser::compile($template);

        $this->assertStringContainsString(
            "<?php \\Webrium\\View\\View::startSection('title'); ?>",
            $compiled
        );
        $this->assertStringContainsString(
            "<?php \\Webrium\\View\\View::endSection(); ?>",
            $compiled
        );
        $this->assertStringContainsString(
            "<?php echo \\Webrium\\View\\View::yieldSection('title'); ?>",
            $compiled
        );
    }

    public function testComponentDirective(): void
    {
        $template = "@component('partials.card', ['title' => 'Hello'])";

        $compiled = Parser::compile($template);

        $this->assertStringContainsString(
            "<?php echo \\Webrium\\View\\View::component('partials.card', ['title' => 'Hello']); ?>",
            $compiled
        );
    }

    public function testIfChainCompilesToPhpIfElse(): void
    {
        $template = <<<'HTML'
<div w-if="$a">A</div>
<div w-else-if="$b">B</div>
<div w-else>C</div>
HTML;

        $compiled = Parser::compile($template);

        // The if/elseif/else structure must be present
        $this->assertStringContainsString('<?php if ($a): ?>', $compiled);
        $this->assertStringContainsString('<?php elseif ($b): ?>', $compiled);
        $this->assertStringContainsString('<?php else: ?>', $compiled);
        $this->assertStringContainsString('<?php endif; ?>', $compiled);

        // The HTML branches must still be present
        $this->assertStringContainsString('<div>A</div>', $compiled);
        $this->assertStringContainsString('<div>B</div>', $compiled);
        $this->assertStringContainsString('<div>C</div>', $compiled);
    }

    public function testForGeneratesForeachLoop(): void
    {
        $template = '<li w-for="$items as $item">@{{ $item }}</li>';

        $compiled = Parser::compile($template);

        $this->assertStringContainsString(
            '<?php foreach ($items as $item): ?>',
            $compiled
        );
        $this->assertStringContainsString(
            '<?php echo htmlspecialchars($item, ENT_QUOTES, \'UTF-8\'); ?>',
            $compiled
        );
        $this->assertStringContainsString(
            '</li><?php endforeach; ?>',
            $compiled
        );
    }

    public function testForWithKeyAndItem(): void
    {
        $template = '<tr w-for="$rows as $idx => $row"><td>@{{ $idx }}</td><td>@{{ $row }}</td></tr>';

        $compiled = Parser::compile($template);

        $this->assertStringContainsString(
            '<?php foreach ($rows as $idx => $row): ?>',
            $compiled
        );
        $this->assertStringContainsString(
            '<?php echo htmlspecialchars($idx, ENT_QUOTES, \'UTF-8\'); ?>',
            $compiled
        );
        $this->assertStringContainsString(
            '<?php echo htmlspecialchars($row, ENT_QUOTES, \'UTF-8\'); ?>',
            $compiled
        );
    }

    public function testMultipleDirectivesOnSameLineAttributeAndTernarySelected(): void
    {
        $template = '<option value="@{{ $prov->id }}"  @{{ ($province_id === $prov->id) ? \'selected\' : \'\' }}>@{{ $prov->name }}</option>';

        $compiled = Parser::compile($template);

        $this->assertStringContainsString(
            'value="<?php echo htmlspecialchars($prov->id, ENT_QUOTES, \'UTF-8\'); ?>"',
            $compiled
        );

        $this->assertStringContainsString(
            "<?php echo htmlspecialchars((\$province_id === \$prov->id) ? 'selected' : '', ENT_QUOTES, 'UTF-8'); ?>",
            $compiled
        );

        $this->assertStringContainsString(
            '<?php echo htmlspecialchars($prov->name, ENT_QUOTES, \'UTF-8\'); ?>',
            $compiled
        );
    }

    public function testElementWithBothWIfAndWForAlongsideInlineDirectives(): void
    {
        $template = <<<'HTML'
<option 
 w-if="$active"  w-for="$provinces as $prov" 
 value="@{{ $prov->id }}"  @{{ ($province_id === $prov->id) ? 'selected' : '' }} >
    @{{ $prov->name }}
</option>
HTML;

        $compiled = Parser::compile($template);

        // Expect both foreach and if compiled to PHP (order not asserted)
        $this->assertStringContainsString('<?php foreach ($provinces as $prov): ?>', $compiled);
        $this->assertStringContainsString('<?php if ($active): ?>', $compiled);

        // Inline interpolations should be processed
        $this->assertStringContainsString('value="<?php echo htmlspecialchars($prov->id, ENT_QUOTES, \'UTF-8\'); ?>"', $compiled);
        $this->assertStringContainsString("<?php echo htmlspecialchars((\$province_id === \$prov->id) ? 'selected' : '', ENT_QUOTES, 'UTF-8'); ?>", $compiled);
        $this->assertStringContainsString('<?php echo htmlspecialchars($prov->name, ENT_QUOTES, \'UTF-8\'); ?>', $compiled);

        // Ensure the PHP blocks are properly closed
        $this->assertStringContainsString('<?php endif; ?>', $compiled);
        $this->assertStringContainsString('<?php endforeach; ?>', $compiled);
    }

    public function testViewSkipDisablesDomDirectivesButKeepsInline(): void
    {
        $template = <<<'HTML'
<div w-skip>
    <span w-if="$cond">@{{ $title }}</span>
</div>
HTML;

        $compiled = Parser::compile($template);

        // DOM-level directives inside w-skip must NOT be converted to PHP if/endif
        $this->assertStringNotContainsString('<?php if (', $compiled);

        // The w-if attribute must remain in the output
        $this->assertStringContainsString('<span w-if="$cond">', $compiled);

        // Inline directive @{{ }} should still be processed
        $this->assertStringContainsString(
            '<?php echo htmlspecialchars($title, ENT_QUOTES, \'UTF-8\'); ?>',
            $compiled
        );
    }

    public function testScriptTagProcessesInlineDirectivesByDefault(): void
    {
        $template = <<<'HTML'
<script>
    console.log("Title:", "@{{ $title }}");
</script>
HTML;

        $compiled = Parser::compile($template);

        // The wrapper tags must still be there
        $this->assertStringContainsString('<script>', $compiled);
        $this->assertStringContainsString('</script>', $compiled);

        // Inline directive inside script should be processed
        $this->assertStringContainsString(
            '<?php echo htmlspecialchars($title, ENT_QUOTES, \'UTF-8\'); ?>',
            $compiled
        );
    }

    public function testScriptTagWithNozogDoesNotProcessInlineDirectives(): void
    {
        $template = <<<'HTML'
<script w-skip>
    console.log("Title:", "@{{ $title }}");
</script>
HTML;

        $compiled = Parser::compile($template);

        // w-skip on <script> means deep "no Zog" mode for the content
        $this->assertStringContainsString('<script>', $compiled);
        $this->assertStringNotContainsString('w-skip', $compiled); // attribute removed in output

        // Inline @{{ }} must remain untouched
        $this->assertStringContainsString('@{{ $title }}', $compiled);
        $this->assertStringNotContainsString(
            '<?php echo htmlspecialchars($title',
            $compiled
        );
    }

    public function testUnexpectedClosingTagThrowsException(): void
    {
        $this->expectException(ViewTemplateException::class);
        $this->expectExceptionMessage('Unexpected closing tag');

        $template = '</div>';

        Parser::compile($template);
    }

    public function testUnclosedTagThrowsException(): void
    {
        $this->expectException(ViewTemplateException::class);
        $this->expectExceptionMessage('Unclosed <div> tag');

        $template = '<div><span></span>';

        Parser::compile($template);
    }

    public function testUnterminatedCommentInsideElementThrowsException(): void
    {
        $this->expectException(ViewTemplateException::class);
        $this->expectExceptionMessage('Unterminated HTML comment inside <div>.');

        $template = '<div><!-- missing closing comment</div>';

        Parser::compile($template);
    }

    public function testBalancedParenthesesInPhpDirectiveAreHandledCorrectly(): void
    {
        $template = <<<'TPL'
@php(
    $code = "function(x) { return (x + 1); }"; // comment with )
    if ($a && ($b || someFn(") inside string"))) {
        echo "ok";
    }
)
TPL;

        $compiled = Parser::compile($template);

        // The full body of the @php(...) should appear inside a single PHP block
        $this->assertStringContainsString('<?php', $compiled);
        $this->assertStringContainsString('$code = "function(x) { return (x + 1); }";', $compiled);
        $this->assertStringContainsString('if ($a && ($b || someFn(") inside string")))', $compiled);
        $this->assertStringContainsString('echo "ok";', $compiled);
        $this->assertStringContainsString('?>', $compiled);
    }

    public function testRawPhpDirectivePolicyBlocksLiteralPhpIfNotAllowed(): void
    {
        // This test assumes that Engine::isRawPhpDirectiveAllowed() is consulted
        // only for *unprotected* @php( occurrences that remain in the text.
        if (!method_exists(Engine::class, 'allowRawPhpDirective')) {
            $this->markTestSkipped('Engine::allowRawPhpDirective() not available.');
        }

        Engine::allowRawPhpDirective(false);

        // Intentionally craft an invalid @php directive that the balanced parser
        // will not fully consume (missing closing parenthesis).
        $template = '@php(echo "missing parenthesis";';

        $this->expectException(ViewTemplateException::class);
        $this->expectExceptionMessage('Unmatched parentheses in @php(');

        Parser::compile($template);
    }
}

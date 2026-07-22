<?php
declare(strict_types=1);

namespace Webrium\View;

/**
 * Webrium\\View template parser / compiler (DOM-less).
 *
 * - Converts Webrium\\View templates into executable PHP + HTML.
 * - Does NOT use DOMDocument, so "exotic" attributes like @click, :class,
 *   x-data, wire:click, etc. are preserved exactly as written.
 * - Supports:
 *     @{{ expr }}                    => escaped echo
 *     @raw(expr)                     => raw echo
 *     @php(code)                     => raw PHP inline (single expression)
 *     @php ... @endphp               => raw PHP block (multiline)
 *     @json(expr) / @tojs(expr)      => json_encode(...)
 *     @section(name) / @endsection   => View::startSection / View::endSection
 *     @yield(name)                   => View::yieldSection
 *     @component(view, data)         => View::component(...)
 *     w-if / w-else-if / w-else   => if / elseif / else
 *     w-for                         => foreach  ($list as $item  /  $list as $key => $item)
 *     w-skip                       => disable DOM-level processing for subtree
 *
 * - On <script> and <style>:
 *     * They are treated as "raw text" elements: inner content is not parsed
 *       as HTML; we only look for the matching closing tag.
 *     * If the tag itself has w-skip, no Zog processing (inline or DOM-level)
 *       is applied inside; the inner content is emitted verbatim.
 *     * Otherwise, inline directives (e.g. @{{ }}) inside are still processed,
 *       but HTML tags inside are not parsed.
 *
 * - Invalid HTML:
 *     * Unclosed non-void tags (e.g. <div> without </div>) cause a
 *       ZogTemplateException.
 *     * Unexpected closing tags (e.g. </div> without a matching opening tag)
 *       also cause a ZogTemplateException.
 */
class Parser
{
    /**
     * Placeholder map used to protect directives before text/HTML parsing.
     *
     * @var array<string,array{type:string,inner:string,line:int}>
     */
    protected static array $directivePlaceholders = [];

    private const SOURCE_LINE_MARKER_PATTERN = '/\/\*__WEBRIUM_SOURCE_LINE_([0-9]+)__\*\//';

    /**
     * HTML5 void elements (do not have closing tags).
     *
     * Note: script/style are intentionally NOT listed here.
     */
    protected const VOID_TAGS = [
        'area',
        'base',
        'br',
        'col',
        'embed',
        'hr',
        'img',
        'input',
        'link',
        'meta',
        'param',
        'source',
        'track',
        'wbr',
    ];

    /**
     * Entry point: compile a raw template string into PHP+HTML.
     */
    public static function compile(string $template): string
    {
        return self::compileWithSourceMap($template)->getCode();
    }

    /**
     * Compile a template and return both PHP code and generated-to-source line
     * metadata. Existing callers can continue using compile().
     */
    public static function compileWithSourceMap(string $template): CompiledTemplate
    {
        try {
            return self::compileMappedTemplate($template);
        } catch (ViewTemplateException $exception) {
            if ($exception->getOriginalLine() === 0) {
                $exception->setOriginalLine(1);
            }

            throw $exception;
        }
    }

    private static function compileMappedTemplate(string $template): CompiledTemplate
    {
        // Compile @php ... @endphp block directives first, before any other
        //    processing, so PHP code inside the block is never touched by the
        //    placeholder or HTML parser.
        $template = self::compilePhpBlocks($template);

        // Protect directive calls with balanced parentheses into placeholders.
        self::$directivePlaceholders = [];
        $counter = 0;

        $makePlaceholder = function (string $type) use (&$counter) {
            return function (string $inner, int $line) use (&$counter, $type) {
                $counter++;
                $key = "__VIEW_" . strtoupper(trim($type, '@')) . "_" . $counter . "__";
                $leadingWhitespace = substr($inner, 0, strspn($inner, " \t\r\n"));
                Parser::$directivePlaceholders[$key] = [
                    'type' => $type,
                    'inner' => $inner,
                    'line' => $line + substr_count($leadingWhitespace, "\n"),
                ];
                return $key;
            };
        };

        $template = self::replaceDirectiveWithBalancedParentheses(
            $template,
            '@php(',
            $makePlaceholder('@php')
        );
        $template = self::replaceDirectiveWithBalancedParentheses(
            $template,
            '@json(',
            $makePlaceholder('@json')
        );
        $template = self::replaceDirectiveWithBalancedParentheses(
            $template,
            '@tojs(',
            $makePlaceholder('@tojs')
        );
        $template = self::replaceDirectiveWithBalancedParentheses(
            $template,
            '@raw(',
            $makePlaceholder('@raw')
        );
        $template = self::replaceDirectiveWithBalancedParentheses(
            $template,
            '@section(',
            $makePlaceholder('@section')
        );
        $template = self::replaceDirectiveWithBalancedParentheses(
            $template,
            '@yield(',
            $makePlaceholder('@yield')
        );
        $template = self::replaceDirectiveWithBalancedParentheses(
            $template,
            '@component(',
            $makePlaceholder('@component')
        );

        // Compile the template using a streaming parser, then remove the
        // internal markers while retaining their generated-to-source map.
        return self::extractSourceMap(self::compileStream($template, false));
    }

    private static function sourceLineMarker(int $line): string
    {
        return '/*__WEBRIUM_SOURCE_LINE_' . max(1, $line) . '__*/';
    }

    private static function lineAt(string $text, int $offset, int $baseLine = 1): int
    {
        return $baseLine + substr_count(substr($text, 0, max(0, $offset)), "\n");
    }

    private static function templateException(
        string $message,
        string $template,
        int $offset,
        int $baseLine = 1
    ): ViewTemplateException {
        return (new ViewTemplateException($message))
            ->setOriginalLine(self::lineAt($template, $offset, $baseLine));
    }

    private static function markPhpBlock(string $php, int $sourceLine): string
    {
        $marker = self::sourceLineMarker($sourceLine);

        if (str_starts_with($php, '<?php')) {
            return '<?php' . $marker . substr($php, 5);
        }
        if (str_starts_with($php, '<?=')) {
            return '<?=' . $marker . substr($php, 3);
        }
        if (str_starts_with($php, '<?')) {
            return '<?' . $marker . substr($php, 2);
        }

        return $php;
    }

    private static function extractSourceMap(string $markedCode): CompiledTemplate
    {
        $lines = explode("\n", $markedCode);
        $lineMap = [];
        $currentSourceLine = 1;

        foreach ($lines as $index => &$line) {
            if (preg_match(self::SOURCE_LINE_MARKER_PATTERN, $line, $matches) === 1) {
                $currentSourceLine = max(1, (int) $matches[1]);
            }

            $lineMap[$index + 1] = $currentSourceLine;
            $line = (string) preg_replace(self::SOURCE_LINE_MARKER_PATTERN, '', $line);
            $currentSourceLine++;
        }
        unset($line);

        return new CompiledTemplate(implode("\n", $lines), $lineMap);
    }

    /**
     * Streaming compiler over the template string.
     *
     * @param string $template Full template string (with placeholders already injected).
     * @param bool   $noZog    When true, DOM-level attributes (w-if, w-for, ...)
     *                         are ignored in this subtree; inline directives still work.
     */
    protected static function compileStream(string $template, bool $noZog, int $baseLine = 1): string
    {
        $out = '';
        $length = strlen($template);
        $i = 0;
        $noZogStack = [$noZog];

        while ($i < $length) {
            $sourceLine = self::lineAt($template, $i, $baseLine);
            $ch = $template[$i];

            if ($ch === '<') {
                // HTML comment: <!-- ... -->
                if ($i + 3 < $length && substr($template, $i, 4) === '<!--') {
                    $end = strpos($template, '-->', $i + 4);
                    if ($end === false) {
                        // Unterminated comment -> output as-is and stop.
                        $out .= substr($template, $i);
                        break;
                    }
                    $comment = substr($template, $i, $end + 3 - $i);
                    $out .= $comment;
                    $i = $end + 3;
                    continue;
                }

                // Markup declaration (e.g. <!DOCTYPE html>, <![something], etc.)
                // We simply copy it as-is and do not parse it as an element.
                if (
                    $i + 2 < $length
                    && $template[$i + 1] === '!'
                    && substr($template, $i, 4) !== '<!--'
                ) {
                    $end = strpos($template, '>', $i + 2);
                    if ($end === false) {
                        // No closing '>' -> treat rest as text.
                        $out .= substr($template, $i);
                        break;
                    }
                    $decl = substr($template, $i, $end + 1 - $i);
                    $out .= $decl;
                    $i = $end + 1;
                    continue;
                }

                // We pass it through verbatim so PHP can execute it in the compiled file.
                if ($i + 1 < $length && $template[$i + 1] === '?') {
                    $end = strpos($template, '?>', $i + 2);
                    if ($end === false) {
                        // Unterminated PHP tag -> treat rest as text.
                        $out .= substr($template, $i);
                        break;
                    }
                    $phpBlock = substr($template, $i, $end + 2 - $i);
                    $out .= self::markPhpBlock($phpBlock, $sourceLine);
                    $i = $end + 2;
                    continue;
                }

                // Unexpected closing tag at this level (should have been consumed inside a parent).
                if ($i + 1 < $length && $template[$i + 1] === '/') {
                    [$tagName] = self::parseEndTag($template, $i);
                    throw self::templateException(
                        "Unexpected closing tag </{$tagName}> without a matching opening tag.",
                        $template,
                        $i,
                        $baseLine
                    );
                }

                // Opening / self-closing tag
                [$tagInfo, $newPos] = self::parseStartTag($template, $i, (bool) end($noZogStack));
                $i = $newPos;

                // Track Zog-disable state for this element.
                if ($tagInfo['nozogElement']) {
                    $noZogStack[] = true;
                } else {
                    $noZogStack[] = $tagInfo['nozogActive'];
                }

                // Build attribute HTML.
                // NOTE: attribute values are passed through compileAttributeValue()
                // so @{{ ... }} and directive placeholders work inside attributes.
                $attrHtml = '';
                foreach ($tagInfo['attrs'] as $attr) {
                    $name = $attr['name'];
                    $value = $attr['value'];
                    $attributeLine = self::lineAt($template, (int) $attr['offset'], $baseLine);

                    if ($value === null) {
                        // Boolean / value-less attribute: still run through compileText
                        // so that @{{ expr }} used as a dynamic attribute (e.g. @{{ $sel?'selected':'' }})
                        // gets compiled to a PHP echo.
                        $compiledName = self::compileAttributeValue($name, $attributeLine);
                        $attrHtml .= ' ' . $compiledName;
                    } else {
                        $compiledValue = self::compileAttributeValue($value, $attributeLine);
                        $attrHtml .= ' ' . $name . '="' . $compiledValue . '"';
                    }
                }

                $tagName = $tagInfo['tag'];
                $tagLower = strtolower($tagName);
                $selfClosing = $tagInfo['selfClosing'];
                $openHtml = '<' . $tagName . $attrHtml . ($selfClosing ? ' />' : '>');
                $closeHtml = $selfClosing ? '' : '</' . $tagName . '>';

                $currentNoZog = (bool) end($noZogStack);
                $zpFor = $tagInfo['zpForExpr'];
                $zpIf = $tagInfo['zpIfExpr'];
                $zpElseIf = $tagInfo['zpElseIfExpr'];
                $isZpElse = $tagInfo['isZpElse'];

                // Special handling for raw-text elements (<script> and <style>).
                if (!$selfClosing && ($tagLower === 'script' || $tagLower === 'style')) {
                    // For <script>/<style>, w-skip on the tag itself disables all processing inside.
                    $deepNoZog = $tagInfo['nozogElement'];

                    [$rawHtml, $endPos] = self::compileRawTextElement(
                        $template,
                        $i,
                        $tagName,
                        $openHtml,
                        $deepNoZog,
                        $baseLine
                    );

                    $out = $out . $rawHtml;
                    $i = $endPos;

                    array_pop($noZogStack);
                    continue;
                }

                // If Zog is disabled for this element, simply output the tag and
                // recurse its inner HTML with noZog=true.
                if ($currentNoZog) {
                    if ($selfClosing) {
                        $out .= $openHtml;
                        array_pop($noZogStack);
                        continue;
                    }

                    $inner = self::compileInnerHtml($template, $i, $tagName, $baseLine);
                    $i = $inner['endPos'];
                    $innerLine = self::lineAt($template, $inner['innerStartPos'], $baseLine);
                    $innerHtml = self::compileStream($inner['innerHtml'], true, $innerLine);

                    $out .= $openHtml . $innerHtml . $closeHtml;
                    array_pop($noZogStack);
                    continue;
                }

                // If we have w-if / w-else-if / w-else, we need to collect the full chain.
                // When w-for is ALSO present on the same element, the if-condition is the outer
                // wrapper and the foreach runs inside it — matching PHP alternative syntax:
                //   if(...): foreach(...): ... endforeach; endif;
                if ($zpIf !== null || $zpElseIf !== null || $isZpElse) {
                    [$chainHtml, $endPos] = self::compileIfChainStream(
                        $template,
                        $i,
                        $tagInfo,
                        $openHtml,
                        $closeHtml,
                        $baseLine
                    );
                    $out = $out . $chainHtml;
                    $i = $endPos;

                    array_pop($noZogStack);
                    continue;
                }

                // w-for: wrap this element in a foreach block.
                if ($zpFor !== null && $zpFor !== '') {
                    [$collectionExpr, $itemVar, $keyVar] = self::parseForExpression($zpFor);

                    $loopPhp = '<?php foreach (' . $collectionExpr . ' as '
                        . ($keyVar ? $keyVar . ' => ' : '')
                        . $itemVar . '): ?>';
                    $loopLine = self::lineAt(
                        $template,
                        (int) ($tagInfo['zpForOffset'] ?? $tagInfo['sourceOffset']),
                        $baseLine
                    );
                    $loopPhp = self::markPhpBlock($loopPhp, $loopLine);

                    if ($selfClosing) {
                        $out .= $loopPhp . $openHtml . $closeHtml . '<?php endforeach; ?>';
                        array_pop($noZogStack);
                        continue;
                    }

                    $innerInfo = self::compileInnerHtml($template, $i, $tagName, $baseLine);
                    $i = $innerInfo['endPos'];
                    $innerLine = self::lineAt($template, $innerInfo['innerStartPos'], $baseLine);
                    $innerHtml = self::compileStream($innerInfo['innerHtml'], false, $innerLine);

                    $out .= $loopPhp . $openHtml . $innerHtml . $closeHtml . '<?php endforeach; ?>';
                    array_pop($noZogStack);
                    continue;
                }

                // Normal element without Zog DOM directives
                if ($selfClosing) {
                    $out .= $openHtml;
                    array_pop($noZogStack);
                    continue;
                }

                $innerInfo = self::compileInnerHtml($template, $i, $tagName, $baseLine);
                $i = $innerInfo['endPos'];
                $innerLine = self::lineAt($template, $innerInfo['innerStartPos'], $baseLine);
                $innerHtml = self::compileStream($innerInfo['innerHtml'], false, $innerLine);

                $out .= $openHtml . $innerHtml . $closeHtml;
                array_pop($noZogStack);
                continue;
            }

            // Plain text section
            $nextTagPos = strpos($template, '<', $i);
            if ($nextTagPos === false) {
                $text = substr($template, $i);
                $i = $length;
            } else {
                $text = substr($template, $i, $nextTagPos - $i);
                $i = $nextTagPos;
            }

            if ($text !== '') {
                $out .= self::compileText($text, $sourceLine);
            }
        }

        return $out;
    }

    /**
     * Compile a <script> or <style> element.
     *
     * The inner content is treated as raw text (not parsed as HTML).
     * If $deepNoZog is true, no inline Zog directives are processed inside.
     *
     * @return array{0:string,1:int} [compiledHtml, endPos]
     */
    protected static function compileRawTextElement(
        string $template,
        int $innerStartPos,
        string $tagName,
        string $openHtml,
        bool $deepNoZog,
        int $baseLine = 1
    ): array {
        $len = strlen($template);
        $tagLower = strtolower($tagName);
        $needle = '</' . $tagLower;
        $closeStart = stripos($template, $needle, $innerStartPos);

        if ($closeStart === false) {
            throw self::templateException(
                "Unclosed <{$tagName}> tag.",
                $template,
                $innerStartPos,
                $baseLine
            );
        }

        $closeEnd = strpos($template, '>', $closeStart);
        if ($closeEnd === false) {
            throw self::templateException(
                "Unclosed </{$tagName}> tag.",
                $template,
                $closeStart,
                $baseLine
            );
        }

        $innerRaw = substr($template, $innerStartPos, $closeStart - $innerStartPos);
        $endPos = $closeEnd + 1;
        $closeHtml = '</' . $tagName . '>';

        if ($deepNoZog) {
            $innerHtml = $innerRaw;
        } else {
            $innerHtml = self::compileText(
                $innerRaw,
                self::lineAt($template, $innerStartPos, $baseLine)
            );
        }

        return [$openHtml . $innerHtml . $closeHtml, $endPos];
    }

    /**
     * Parse an opening (or self-closing) HTML tag from $template starting at $index.
     *
     * Returns [tagInfo, newIndex].
     *
     * tagInfo includes:
     *  - tag           : string
     *  - attrs         : list<array{name:string,value:?string}>
     *  - selfClosing   : bool
     *  - nozogElement  : bool (this element has w-skip)
     *  - nozogActive   : bool (parentNozog OR this element has w-skip)
     *  - zpIfExpr      : ?string
     *  - zpElseIfExpr  : ?string
     *  - isZpElse      : bool
     *  - zpForExpr     : ?string
     */
    protected static function parseStartTag(
        string $template,
        int $index,
        bool $parentNozogActive
    ): array {
        $len = strlen($template);
        $i = $index;

        // assume $template[$i] == '<'
        $i++;

        // read tag name
        $nameStart = $i;
        while ($i < $len && !ctype_space($template[$i]) && $template[$i] !== '>' && $template[$i] !== '/') {
            $i++;
        }
        $tag = substr($template, $nameStart, $i - $nameStart);

        $attrs = [];
        $selfClosing = false;

        // parse attributes
        while ($i < $len) {
            // skip whitespace
            while ($i < $len && ctype_space($template[$i])) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }
            $ch = $template[$i];

            if ($ch === '>') {
                $i++;
                break;
            }
            if ($ch === '/' && $i + 1 < $len && $template[$i + 1] === '>') {
                $selfClosing = true;
                $i += 2;
                break;
            }

            // attribute name
            // We must treat @{{ ... }} as a single token even though it contains spaces.
            $nameStart = $i;
            while ($i < $len) {
                $c = $template[$i];

                // End of tag
                if ($c === '>') {
                    break;
                }
                if ($c === '/' && $i + 1 < $len && $template[$i + 1] === '>') {
                    break;
                }
                // Value separator
                if ($c === '=') {
                    break;
                }
                // Whitespace ends the name — UNLESS we are inside an @{{ }} token
                if (ctype_space($c)) {
                    // Peek: are we at the start of @{{ ?  That would be unusual for an attr name,
                    // but handle it safely. Whitespace simply ends the name here.
                    break;
                }
                // If we see @{{ we consume everything up to and including the closing }}
                if ($c === '@' && $i + 2 < $len && $template[$i + 1] === '{' && $template[$i + 2] === '{') {
                    $i += 3; // skip @{{
                    while ($i < $len) {
                        if ($template[$i] === '}' && $i + 1 < $len && $template[$i + 1] === '}') {
                            $i += 2; // skip }}
                            break;
                        }
                        $i++;
                    }
                    continue;
                }
                $i++;
            }
            $attrName = substr($template, $nameStart, $i - $nameStart);
            if ($attrName === '') {
                $i++;
                continue;
            }

            // skip whitespace
            while ($i < $len && ctype_space($template[$i])) {
                $i++;
            }

            $value = null;
            if ($i < $len && $template[$i] === '=') {
                $i++;
                while ($i < $len && ctype_space($template[$i])) {
                    $i++;
                }
                if ($i >= $len) {
                    break;
                }
                $ch = $template[$i];
                if ($ch === '"' || $ch === "'") {
                    $quote = $ch;
                    $i++;
                    $valStart = $i;
                    while ($i < $len) {
                        if ($template[$i] === '\\' && $i + 1 < $len && $template[$i + 1] === $quote) {
                            // escaped quote inside attribute value — skip both chars
                            $i += 2;
                            continue;
                        }
                        if ($template[$i] === $quote) {
                            break;
                        }
                        $i++;
                    }
                    $value = substr($template, $valStart, $i - $valStart);
                    if ($i < $len && $template[$i] === $quote) {
                        $i++;
                    }
                } else {
                    $valStart = $i;
                    while (
                        $i < $len
                        && !ctype_space($template[$i])
                        && $template[$i] !== '>'
                        && !($template[$i] === '/' && $i + 1 < $len && $template[$i + 1] === '>')
                    ) {
                        $i++;
                    }
                    $value = substr($template, $valStart, $i - $valStart);
                }
            }

            $attrs[] = [
                'name' => $attrName,
                'value' => $value,
                'offset' => $nameStart,
            ];
        }

        // Mark void tags as self-closing even without "/>"
        $tagLower = strtolower($tag);
        if (!$selfClosing && in_array($tagLower, self::VOID_TAGS, true)) {
            $selfClosing = true;
        }

        $isNozogElement = false;
        $nozogActive = $parentNozogActive;
        $zpIf = null;
        $zpElseIf = null;
        $isZpElse = false;
        $zpFor = null;
        $zpIfOffset = null;
        $zpElseIfOffset = null;
        $zpElseOffset = null;
        $zpForOffset = null;
        $filteredAttrs = [];

        foreach ($attrs as $attr) {
            $name = $attr['name'];
            $value = $attr['value'];
            $lname = strtolower($name);

            if ($lname === 'w-skip') {
                $isNozogElement = true;
                $nozogActive = true;
                continue;
            }

            if (!$nozogActive) {
                if ($lname === 'w-if') {
                    $zpIf = $value ?? '';
                    $zpIfOffset = $attr['offset'];
                    continue;
                }
                if ($lname === 'w-else-if') {
                    $zpElseIf = $value ?? '';
                    $zpElseIfOffset = $attr['offset'];
                    continue;
                }
                if ($lname === 'w-else') {
                    $isZpElse = true;
                    $zpElseOffset = $attr['offset'];
                    continue;
                }
                if ($lname === 'w-for') {
                    $zpFor = $value ?? '';
                    $zpForOffset = $attr['offset'];
                    continue;
                }
            }

            $filteredAttrs[] = $attr;
        }

        $tagInfo = [
            'tag' => $tag,
            'attrs' => $filteredAttrs,
            'selfClosing' => $selfClosing,
            'nozogElement' => $isNozogElement,
            'nozogActive' => $nozogActive,
            'zpIfExpr' => $zpIf,
            'zpElseIfExpr' => $zpElseIf,
            'isZpElse' => $isZpElse,
            'zpForExpr' => $zpFor,
            'zpIfOffset' => $zpIfOffset,
            'zpElseIfOffset' => $zpElseIfOffset,
            'zpElseOffset' => $zpElseOffset,
            'zpForOffset' => $zpForOffset,
            'sourceOffset' => $index,
        ];

        return [$tagInfo, $i];
    }

    /**
     * Parse a closing HTML tag: </tag>
     *
     * Returns [tagName, newIndex]
     */
    protected static function parseEndTag(string $template, int $index): array
    {
        $len = strlen($template);
        $i = $index + 2; // skip "</"

        // skip spaces
        while ($i < $len && ctype_space($template[$i])) {
            $i++;
        }

        $nameStart = $i;
        while ($i < $len && !ctype_space($template[$i]) && $template[$i] !== '>') {
            $i++;
        }
        $tag = substr($template, $nameStart, $i - $nameStart);

        while ($i < $len && $template[$i] !== '>') {
            $i++;
        }
        if ($i < $len && $template[$i] === '>') {
            $i++;
        }

        return [$tag, $i];
    }

    /**
     * Extract inner HTML for an element (from after the start tag to its matching end tag).
     *
     * Returns:
     *   [
     *      'innerHtml' => string,
     *      'endPos'    => int  (position AFTER the closing tag)
     *   ]
     *
     * Throws ZogTemplateException if the tag is not properly closed.
     */
    protected static function compileInnerHtml(
        string $template,
        int $startPos,
        string $tagName,
        int $baseLine = 1
    ): array {
        $len = strlen($template);
        $depth = 1;
        $i = $startPos;
        $tagLower = strtolower($tagName);

        while ($i < $len && $depth > 0) {
            $pos = strpos($template, '<', $i);
            if ($pos === false) {
                // No more tags; the element is never closed.
                throw self::templateException(
                    "Unclosed <{$tagName}> tag.",
                    $template,
                    $startPos,
                    $baseLine
                );
            }

            // Comment
            if ($pos + 3 < $len && substr($template, $pos, 4) === '<!--') {
                $endComment = strpos($template, '-->', $pos + 4);
                if ($endComment === false) {
                    throw self::templateException(
                        'Unterminated HTML comment inside <' . $tagName . '>.',
                        $template,
                        $pos,
                        $baseLine
                    );
                }
                $i = $endComment + 3;
                continue;
            }

            // Markup declarations like <!DOCTYPE ...> or <![...]> – skip them
            if (
                $pos + 2 < $len
                && $template[$pos + 1] === '!'
                && substr($template, $pos, 4) !== '<!--'
            ) {
                $declEnd = strpos($template, '>', $pos + 2);
                if ($declEnd === false) {
                    throw self::templateException(
                        'Unterminated markup declaration inside <' . $tagName . '>.',
                        $template,
                        $pos,
                        $baseLine
                    );
                }
                $i = $declEnd + 1;
                continue;
            }

            if ($pos + 1 < $len && $template[$pos + 1] === '?') {
                $phpEnd = strpos($template, '?>', $pos + 2);
                if ($phpEnd === false) {
                    throw self::templateException(
                        'Unterminated PHP block inside <' . $tagName . '>.',
                        $template,
                        $pos,
                        $baseLine
                    );
                }
                $i = $phpEnd + 2;
                continue;
            }

            // Closing tag
            if ($pos + 1 < $len && $template[$pos + 1] === '/') {
                [$closeTag, $newPos] = self::parseEndTag($template, $pos);
                if (strtolower($closeTag) === $tagLower) {
                    $depth--;
                    if ($depth === 0) {
                        $innerHtml = substr($template, $startPos, $pos - $startPos);
                        return [
                            'innerHtml' => $innerHtml,
                            'innerStartPos' => $startPos,
                            'closeStartPos' => $pos,
                            'endPos' => $newPos,
                        ];
                    }
                    $i = $newPos;
                    continue;
                }

                // Closing tag for some inner element; let that element's own parse handle it.
                $i = $newPos;
                continue;
            }

            // Nested opening tag
            [$nestedTag, $nestedPos] = self::parseStartTag($template, $pos, false);
            $nestedNameLower = strtolower($nestedTag['tag']);

            // If it's a nested <script> or <style>, skip its raw-text body safely.
            if (($nestedNameLower === 'script' || $nestedNameLower === 'style') && !$nestedTag['selfClosing']) {
                $needle = '</' . $nestedNameLower;
                $closeStart = stripos($template, $needle, $nestedPos);
                if ($closeStart === false) {
                    throw self::templateException(
                        "Unclosed <{$nestedTag['tag']}> tag inside <{$tagName}>.",
                        $template,
                        $pos,
                        $baseLine
                    );
                }
                $closeEnd = strpos($template, '>', $closeStart);
                if ($closeEnd === false) {
                    throw self::templateException(
                        "Unclosed </{$nestedTag['tag']}> tag inside <{$tagName}>.",
                        $template,
                        $closeStart,
                        $baseLine
                    );
                }
                $i = $closeEnd + 1;
                continue;
            }

            if ($nestedNameLower === $tagLower && !$nestedTag['selfClosing']) {
                $depth++;
            }

            $i = $nestedPos;
        }

        throw self::templateException(
            "Unclosed <{$tagName}> tag.",
            $template,
            $startPos,
            $baseLine
        );
    }

    /**
     * Compile a w-if / w-else-if / w-else chain starting from the first element.
     *
     * - $firstTagInfo: info returned by parseStartTag for the initial w-if element.
     * - $firstOpenHtml / $firstCloseHtml: pre-built opening/closing HTML for the first element.
     *
     * Returns [compiledHtml, newIndex].
     */
    protected static function compileIfChainStream(
        string $template,
        int $posAfterFirstStart,
        array $firstTagInfo,
        string $firstOpenHtml,
        string $firstCloseHtml,
        int $baseLine = 1
    ): array {
        $len = strlen($template);
        $i = $posAfterFirstStart;
        $branches = [];

        // helper to compile a single branch element + its inner HTML.
        // When the element also carries w-for, the foreach wraps the element
        // inside the already-open if/elseif/else branch, mirroring PHP alternative syntax:
        //   if(...): foreach(...): <tag>...</tag> endforeach; endif;
        $compileBranch = function (array $tagInfo, string $openHtml, string $closeHtml, int &$pos) use ($template, $baseLine): string {
            $tagName = $tagInfo['tag'];

            // Build the element body (open + inner + close).
            if ($tagInfo['selfClosing']) {
                $elementHtml = $openHtml . $closeHtml;
            } else {
                $innerInfo = Parser::compileInnerHtml($template, $pos, $tagName, $baseLine);
                $pos = $innerInfo['endPos'];
                $innerLine = Parser::lineAt($template, $innerInfo['innerStartPos'], $baseLine);
                $innerHtml = Parser::compileStream($innerInfo['innerHtml'], false, $innerLine);
                $elementHtml = $openHtml . $innerHtml . $closeHtml;
            }

            // If this branch element also has w-for, wrap the element in foreach.
            $zpFor = $tagInfo['zpForExpr'] ?? null;
            if ($zpFor !== null && $zpFor !== '') {
                [$collectionExpr, $itemVar, $keyVar] = Parser::parseForExpression($zpFor);
                $loopOpen  = '<?php foreach (' . $collectionExpr . ' as '
                    . ($keyVar ? $keyVar . ' => ' : '')
                    . $itemVar . '): ?>';
                $loopLine = Parser::lineAt(
                    $template,
                    (int) ($tagInfo['zpForOffset'] ?? $tagInfo['sourceOffset']),
                    $baseLine
                );
                $loopOpen = Parser::markPhpBlock($loopOpen, $loopLine);
                $loopClose = '<?php endforeach; ?>';
                return $loopOpen . $elementHtml . $loopClose;
            }

            return $elementHtml;
        };

        // first branch is always "if"
        $branches[] = [
            'type' => 'if',
            'expr' => trim((string) $firstTagInfo['zpIfExpr']),
            'line' => self::lineAt(
                $template,
                (int) ($firstTagInfo['zpIfOffset'] ?? $firstTagInfo['sourceOffset']),
                $baseLine
            ),
            'html' => $compileBranch($firstTagInfo, $firstOpenHtml, $firstCloseHtml, $i),
        ];

        // scan for following w-else-if / w-else siblings
        while ($i < $len) {
            $savePos = $i;
            $ltPos = strpos($template, '<', $i);
            if ($ltPos === false) {
                break;
            }

            // whitespace-only text between branches is allowed
            $rawBetween = substr($template, $i, $ltPos - $i);
            if (trim($rawBetween) !== '') {
                // not just indentation -> chain ended
                $i = $savePos;
                break;
            }

            $i = $ltPos;

            // comments between branches are allowed
            if ($i + 3 < $len && substr($template, $i, 4) === '<!--') {
                $endComment = strpos($template, '-->', $i + 4);
                if ($endComment === false) {
                    throw new ViewTemplateException('Unterminated HTML comment inside w-if chain.');
                }
                $i = $endComment + 3;
                continue;
            }

            if (
                $i + 2 < $len
                && $template[$i + 1] === '!'
                && substr($template, $i, 4) !== '<!--'
            ) {
                // Markup declaration between branches – not part of the chain.
                $i = $savePos;
                break;
            }

            if ($i + 1 < $len && $template[$i + 1] === '/') {
                // closing tag -> chain ended
                break;
            }

            [$tagInfo, $newPos] = self::parseStartTag($template, $i, false);
            $i = $newPos;

            // Build HTML for attributes (with interpolation support)
            $attrHtml = '';
            foreach ($tagInfo['attrs'] as $attr) {
                $name = $attr['name'];
                $value = $attr['value'];
                $attributeLine = self::lineAt($template, (int) $attr['offset'], $baseLine);

                if ($value === null) {
                    // Boolean attribute: compile the name too so @{{ expr }} works
                    $compiledName = self::compileAttributeValue($name, $attributeLine);
                    $attrHtml .= ' ' . $compiledName;
                } else {
                    $compiledValue = self::compileAttributeValue($value, $attributeLine);
                    $attrHtml .= ' ' . $name . '="' . $compiledValue . '"';
                }
            }

            $openHtml = '<' . $tagInfo['tag'] . $attrHtml . ($tagInfo['selfClosing'] ? ' />' : '>');
            $closeHtml = $tagInfo['selfClosing'] ? '' : '</' . $tagInfo['tag'] . '>';

            if ($tagInfo['zpElseIfExpr'] !== null) {
                $branches[] = [
                    'type' => 'elseif',
                    'expr' => trim((string) $tagInfo['zpElseIfExpr']),
                    'line' => self::lineAt(
                        $template,
                        (int) ($tagInfo['zpElseIfOffset'] ?? $tagInfo['sourceOffset']),
                        $baseLine
                    ),
                    'html' => $compileBranch($tagInfo, $openHtml, $closeHtml, $i),
                ];
                continue;
            }

            if ($tagInfo['isZpElse']) {
                $branches[] = [
                    'type' => 'else',
                    'expr' => null,
                    'line' => self::lineAt(
                        $template,
                        (int) ($tagInfo['zpElseOffset'] ?? $tagInfo['sourceOffset']),
                        $baseLine
                    ),
                    'html' => $compileBranch($tagInfo, $openHtml, $closeHtml, $i),
                ];
                break; // else must be last in a chain
            }

            // normal element, not part of chain
            $i = $savePos;
            break;
        }

        // Build final PHP if/elseif/else structure
        $out = '';

        foreach ($branches as $branch) {
            if ($branch['type'] === 'if') {
                $out .= self::markPhpBlock(
                    "<?php if ({$branch['expr']}): ?>",
                    (int) $branch['line']
                ) . $branch['html'];
            } elseif ($branch['type'] === 'elseif') {
                $out .= self::markPhpBlock(
                    "<?php elseif ({$branch['expr']}): ?>",
                    (int) $branch['line']
                ) . $branch['html'];
            } else {
                // Else branch has no expression.
                $out .= self::markPhpBlock('<?php else: ?>', (int) $branch['line'])
                    . $branch['html'];
            }
        }

        // Close the if-chain
        $out .= '<?php endif; ?>';

        return [$out, $i];
    }

    /**
     * Compile @php ... @endphp block directives.
     *
     * The entire block — including its PHP body — is replaced with a single
     * <?php ... ?> tag before any other parsing takes place, so the HTML
     * parser and placeholder system never see the PHP code inside.
     *
     * Syntax:
     *   @php
     *   // any PHP code here
     *   $x = 1;
     *   @endphp
     *
     * Rules:
     *   - @php must be the only non-whitespace content on its line.
     *   - @endphp must be the only non-whitespace content on its line.
     *   - Nesting @php inside @php is not supported and will throw.
     *   - An empty block (@php immediately followed by @endphp) is allowed
     *     but produces no output.
     */
    protected static function compilePhpBlocks(string $template): string
    {
        // Fast path — no block directives present.
        if (stripos($template, '@php') === false) {
            return $template;
        }

        $out    = '';
        $len    = strlen($template);
        $offset = 0;

        while ($offset < $len) {
            // Find the next @php that appears alone on its line.
            $found = preg_match(
                '/^[ \t]*@php[ \t]*$/im',
                $template,
                $openMatch,
                PREG_OFFSET_CAPTURE,
                $offset
            );

            if (!$found) {
                // No more @php blocks — append the remainder and stop.
                $out .= substr($template, $offset);
                break;
            }

            $openStart = $openMatch[0][1];         // byte offset of the matched line start
            $openEnd   = $openStart + strlen($openMatch[0][0]); // byte offset after the matched line

            // Guard against nested @php blocks in the text before this block.
            $before = substr($template, $offset, $openStart - $offset);
            if (preg_match('/^[ \t]*@php[ \t]*$/im', $before)) {
                throw new ViewTemplateException(
                    'Nested @php blocks are not supported.'
                );
            }

            // Append everything before this @php tag.
            $out .= $before;

            // Consume the newline that follows @php (if any).
            $bodyStart = $openEnd;
            if ($bodyStart < $len && $template[$bodyStart] === "\r") {
                $bodyStart++;
            }
            if ($bodyStart < $len && $template[$bodyStart] === "\n") {
                $bodyStart++;
            }

            // Find the matching @endphp on its own line.
            $foundEnd = preg_match(
                '/^[ \t]*@endphp[ \t]*$/im',
                $template,
                $closeMatch,
                PREG_OFFSET_CAPTURE,
                $bodyStart
            );

            if (!$foundEnd) {
                throw (new ViewTemplateException(
                    '@php block opened but @endphp was never found.'
                ))->setOriginalLine(self::lineAt($template, $openStart));
            }

            $closeStart = $closeMatch[0][1];
            $closeEnd   = $closeStart + strlen($closeMatch[0][0]);

            // The PHP body is everything between the two markers.
            $body = substr($template, $bodyStart, $closeStart - $bodyStart);

            // Trim only trailing newline from body so indentation is preserved.
            $body = rtrim($body, "\r\n");

            if (!Engine::isRawPhpDirectiveAllowed()) {
                throw new ViewTemplateException(
                    '@php directive is disabled for security reasons.'
                );
            }

            $compiledBlock = '';
            if (trim($body) !== '') {
                $compiledBlock = self::markPhpBlock(
                    '<?php ' . "\n" . $body . "\n" . '?>',
                    self::lineAt($template, $openStart)
                );
            }

            // Consume the newline after @endphp (if any) to avoid blank lines.
            $offset = $closeEnd;
            if ($offset < $len && $template[$offset] === "\r") {
                $offset++;
            }
            if ($offset < $len && $template[$offset] === "\n") {
                $offset++;
            }

            $sourceBlock = substr($template, $openStart, $offset - $openStart);
            $missingLines = substr_count($sourceBlock, "\n")
                - substr_count($compiledBlock, "\n");
            if ($missingLines > 0) {
                $compiledBlock .= str_repeat("<?php\n?>", $missingLines);
            }

            $out .= $compiledBlock;
        }

        return $out;
    }

    /**
     * Compile plain text node content:
     *   - placeholders for protected directives are restored here
     *   - @{{ expr }}               => escaped echo
     *   - @endsection               => View::endSection()
     */
    protected static function compileText(string $text, int $sourceLine = 1): string
    {
        if ($text === '') {
            return '';
        }

        // -- restore directive placeholders (if any) --
        if (strpos($text, '__VIEW_') !== false && !empty(self::$directivePlaceholders)) {
            $text = (string) preg_replace_callback(
                '/__VIEW_[A-Z]+_[0-9]+__/',
                function (array $m) {
                    $key = $m[0];
                    if (!isset(Parser::$directivePlaceholders[$key])) {
                        return $key; // unknown placeholder, leave as-is
                    }
                    $entry = Parser::$directivePlaceholders[$key];
                    $type = $entry['type'];
                    $inner = $entry['inner'];
                    $line = $entry['line'];

                    // emulate original transforms
                    if ($type === '@php') {
                        $code = trim($inner);
                        if ($code === '') {
                            throw new ViewTemplateException('@php() requires non-empty code.');
                        }
                        return Parser::markPhpBlock('<?php ' . $code . ' ?>', $line);
                    }

                    if ($type === '@json' || $type === '@tojs') {
                        return Parser::markPhpBlock(Parser::buildJsonDirective($inner), $line);
                    }

                    if ($type === '@raw') {
                        $expr = trim($inner);
                        if ($expr === '') {
                            throw new ViewTemplateException('@raw() requires a non-empty expression.');
                        }
                        return Parser::markPhpBlock('<?php echo ' . $expr . '; ?>', $line);
                    }

                    if ($type === '@section') {
                        $args = trim($inner);
                        if ($args === '') {
                            throw new ViewTemplateException('@section() requires a section name.');
                        }
                        return Parser::markPhpBlock(
                            '<?php \\Webrium\\View\\View::startSection(' . $args . '); ?>',
                            $line
                        );
                    }

                    if ($type === '@yield') {
                        $args = trim($inner);
                        if ($args === '') {
                            throw new ViewTemplateException('@yield() requires a section name.');
                        }
                        return Parser::markPhpBlock(
                            '<?php echo \\Webrium\\View\\View::yieldSection(' . $args . '); ?>',
                            $line
                        );
                    }

                    if ($type === '@component') {
                        $args = trim($inner);
                        if ($args === '') {
                            throw new ViewTemplateException('@component() requires at least a view name.');
                        }
                        return Parser::markPhpBlock(
                            '<?php echo \\Webrium\\View\\View::component(' . $args . '); ?>',
                            $line
                        );
                    }

                    return $key;
                },
                $text
            );
        }

        // Enforce raw PHP directive policy
        if (!Engine::isRawPhpDirectiveAllowed()) {
            if (strpos($text, '@php(') !== false || strpos($text, '@php') !== false) {
                throw new ViewTemplateException('@php directive is disabled for security reasons.');
            }
        }

        // Handle @{{ expr }}  (escaped echo)
        $textBeforeEscapedEchoes = $text;
        $text = (string) preg_replace_callback(
            '/@\{\{\s*(.+?)\s*\}\}/s',
            function (array $m) use ($textBeforeEscapedEchoes, $sourceLine): string {
                $expr = trim($m[1][0]);
                if ($expr === '') {
                    return '';
                }

                $line = self::lineAt($textBeforeEscapedEchoes, (int) $m[0][1], $sourceLine);
                return self::markPhpBlock(
                    '<?php echo htmlspecialchars(' . $expr . ", ENT_QUOTES, 'UTF-8'); ?>",
                    $line
                );
            },
            $text,
            -1,
            $replaceCount,
            PREG_OFFSET_CAPTURE
        );

        // Handle @endsection directive (no parentheses)
        if (strpos($text, '@endsection') !== false) {
            $textBeforeEndSections = $text;
            $text = (string) preg_replace_callback(
                '/@endsection\b/',
                function (array $match) use ($textBeforeEndSections, $sourceLine): string {
                    $line = self::lineAt($textBeforeEndSections, (int) $match[0][1], $sourceLine);
                    return self::markPhpBlock(
                        '<?php \\Webrium\\View\\View::endSection(); ?>',
                        $line
                    );
                },
                $text,
                -1,
                $replaceCount,
                PREG_OFFSET_CAPTURE
            );
        }

        return $text;
    }


    /**
     * Compile an attribute value so that directives like
     * @{{ expr }} and protected placeholders (__VIEW_...__)
     * also work inside HTML attributes.
     *
     * Examples:
     *   href="docs/@{{ $page }}"
     *   href="@{{ $url }}"
     *   data-json="@json($payload)"
     */
    protected static function compileAttributeValue(string $value, int $sourceLine = 1): string
    {
        if ($value === '') {
            return '';
        }

        // Reuse compileText so behavior inside attributes matches text nodes.
        return self::compileText($value, $sourceLine);
    }


    /**
     * Balanced parentheses replacement for directives like:
     *   @php(...)
     *   @json(...)
     *   @tojs(...)
     *   @raw(...)
     *   @section(...)
     *   @yield(...)
     *   @component(...)
     *
     * This version is robust: it ignores parentheses inside single/double
     * quoted strings, line/block comments, and attempts to detect heredoc/nowdoc.
     *
     * @param string   $text      Original text
     * @param string   $token     Directive prefix, e.g. "@php(" or "@json("
     * @param callable $transform function(string $inner): string  -> returns replacement
     *
     * @return string
     */
    protected static function replaceDirectiveWithBalancedParentheses(
        string $text,
        string $token,
        callable $transform
    ): string {
        $tokenLen = strlen($token);
        $offset = 0;

        while (true) {
            $start = strpos($text, $token, $offset);
            if ($start === false) {
                break;
            }

            // position of '(' (token expected to include the '(' at the end, e.g. "@php(")
            $openParenPos = $start + $tokenLen - 1;
            $len = strlen($text);

            if ($openParenPos >= $len || $text[$openParenPos] !== '(') {
                throw self::templateException(
                    "Internal error parsing directive {$token}",
                    $text,
                    $start
                );
            }

            // Scan forward and handle PHP-like strings and comments so parentheses inside them are ignored.
            $depth = 1;
            $i = $openParenPos + 1;

            $inSingleQuote = false;
            $inDoubleQuote = false;
            $inLineComment = false;   // // or #
            $inBlockComment = false;  // /* */
            $inHeredoc = false;
            $heredocLabel = '';

            for (; $i < $len; $i++) {
                $ch = $text[$i];
                $next = ($i + 1 < $len) ? $text[$i + 1] : '';

                // If currently inside a line comment (// or #)
                if ($inLineComment) {
                    if ($ch === "\n" || $ch === "\r") {
                        $inLineComment = false;
                    }
                    continue;
                }

                // If inside block comment /* ... */
                if ($inBlockComment) {
                    if ($ch === '*' && $next === '/') {
                        $inBlockComment = false;
                        $i++; // skip '/'
                    }
                    continue;
                }

                // If inside single-quoted string
                if ($inSingleQuote) {
                    if ($ch === '\\') {
                        // skip escaped char (\' or \\)
                        $i++;
                        continue;
                    }
                    if ($ch === "'") {
                        $inSingleQuote = false;
                    }
                    continue;
                }

                // If inside double-quoted string
                if ($inDoubleQuote) {
                    if ($ch === '\\') {
                        // skip escaped char
                        $i++;
                        continue;
                    }
                    if ($ch === '"') {
                        $inDoubleQuote = false;
                    }
                    continue;
                }

                // If inside heredoc/nowdoc, search for terminator at start of line
                if ($inHeredoc) {
                    // look ahead for "\n" + label   (handle possible "\r\n")
                    $search = "\n" . $heredocLabel;
                    $pos = strpos($text, $search, $i);
                    if ($pos === false) {
                        // not found => unterminated heredoc
                        $i = $len;
                        break;
                    }
                    // after the label there may be optional whitespace and optional ; then newline
                    $afterLabelPos = $pos + strlen($search);
                    $tail = substr($text, $afterLabelPos, 64);
                    if (preg_match('/^[ \t]*(;)?\r?\n/s', $tail, $m)) {
                        // found terminator — set i to the newline after terminator
                        $i = $afterLabelPos + strlen($m[0]) - 1;
                        $inHeredoc = false;
                        continue;
                    }
                    // it's not a real terminator — continue searching after pos
                    $i = $pos + 1;
                    continue;
                }

                // Not inside any string/comment/heredoc — detect entries

                // start of line comment //
                if ($ch === '/' && $next === '/') {
                    $inLineComment = true;
                    $i++; // skip second '/'
                    continue;
                }
                // start of block comment /*
                if ($ch === '/' && $next === '*') {
                    $inBlockComment = true;
                    $i++; // skip '*'
                    continue;
                }
                // shell-style comment #
                if ($ch === '#') {
                    $inLineComment = true;
                    continue;
                }
                // single or double quote start
                if ($ch === "'") {
                    $inSingleQuote = true;
                    continue;
                }
                if ($ch === '"') {
                    $inDoubleQuote = true;
                    continue;
                }

                // heredoc/nowdoc start detection: look for "<<<"
                if ($ch === '<' && $next === '<' && ($i + 2 < $len) && $text[$i + 2] === '<') {
                    // parse label after <<<
                    $j = $i + 3;
                    // skip optional whitespace
                    while ($j < $len && ($text[$j] === ' ' || $text[$j] === "\t")) {
                        $j++;
                    }
                    if ($j >= $len) {
                        // malformed, treat as normal chars
                    } else {
                        $label = '';
                        if ($text[$j] === "'" || $text[$j] === '"') {
                            $quoteChar = $text[$j];
                            $j++;
                            while ($j < $len) {
                                if ($text[$j] === '\\') {
                                    $j += 2;
                                    continue;
                                }
                                if ($text[$j] === $quoteChar) {
                                    $j++;
                                    break;
                                }
                                $label .= $text[$j];
                                $j++;
                            }
                        } else {
                            while ($j < $len && preg_match('/[A-Za-z0-9_]/', $text[$j])) {
                                $label .= $text[$j];
                                $j++;
                            }
                        }

                        // ensure we have a label and there's a newline after the rest of the line
                        if ($label !== '' && preg_match('/\r?\n/', substr($text, $j, 2))) {
                            // found heredoc/nowdoc start
                            $inHeredoc = true;
                            $heredocLabel = $label;
                            $i = $j;
                            continue;
                        }
                    }
                }

                // actual parentheses counting (only when not inside string/comment)
                if ($ch === '(') {
                    $depth++;
                    continue;
                }
                if ($ch === ')') {
                    $depth--;
                    if ($depth === 0) {
                        break; // found matching closing parenthesis
                    }
                    continue;
                }
            }

            if ($depth !== 0 || $i >= $len) {
                throw self::templateException(
                    "Unmatched parentheses in {$token} directive.",
                    $text,
                    $start
                );
            }

            // Inner contents between the outermost (...)
            $inner = substr($text, $openParenPos + 1, $i - ($openParenPos + 1));

            $sourceLine = self::lineAt($text, $start);
            $replacement = $transform($inner, $sourceLine);

            // Keep preprocessing line-stable so offsets discovered by later
            // passes still refer to the original source. Padding newlines are
            // placed inside empty PHP blocks and therefore render no output.
            $originalDirective = substr($text, $start, $i + 1 - $start);
            $missingLines = substr_count($originalDirective, "\n")
                - substr_count($replacement, "\n");
            if ($missingLines > 0) {
                $replacement .= str_repeat("<?php\n?>", $missingLines);
            }

            // Replace the whole @xxx( ... ) block
            $text = substr($text, 0, $start)
                . $replacement
                . substr($text, $i + 1);

            // Continue searching after the replacement
            $offset = $start + strlen($replacement);
        }

        return $text;
    }

    /**
     * Build json_encode(...) directive used by @json and @tojs.
     */
    protected static function buildJsonDirective(string $inner): string
    {
        $expr = trim($inner);
        if ($expr === '') {
            throw new ViewTemplateException('@json() / @tojs() requires a non-empty expression.');
        }

        return '<?php echo json_encode(' . $expr
            . ', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>';
    }

    /**
     * Parse a w-for attribute value using PHP foreach syntax.
     *
     * Accepted forms:
     *   "$items as $item"
     *   "$items as $key => $item"
     *
     * Returns array [$collectionExpr, $itemVar, $keyVarOrNull].
     */
    public static function parseForExpression(string $expr): array
    {
        $expr = trim($expr);
        if ($expr === '') {
            throw new ViewTemplateException('Empty w-for expression.');
        }

        // $collection as $key => $value
        if (preg_match('/^(.+)\s+as\s+(\$[A-Za-z_][A-Za-z0-9_]*)\s*=>\s*(\$[A-Za-z_][A-Za-z0-9_]*)$/', $expr, $m)) {
            return [trim($m[1]), $m[3], $m[2]];
        }

        // $collection as $value
        if (preg_match('/^(.+)\s+as\s+(\$[A-Za-z_][A-Za-z0-9_]*)$/', $expr, $m)) {
            return [trim($m[1]), $m[2], null];
        }

        throw new ViewTemplateException(
            'Invalid w-for expression: "' . $expr . '". '
            . 'Expected "$list as $item" or "$list as $key => $item".'
        );
    }
}

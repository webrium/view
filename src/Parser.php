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
 *     @php(code)                     => raw PHP (if enabled in Zog)
 *     @json(expr) / @tojs(expr)      => json_encode(...)
 *     @section(name) / @endsection   => View::startSection / View::endSection
 *     @yield(name)                   => View::yieldSection
 *     @component(view, data)         => View::component(...)
 *     w-if / w-else-if / w-else   => if / elseif / else
 *     w-for                         => foreach
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
     * @var array<string,array{type:string,inner:string}>
     */
    protected static array $directivePlaceholders = [];

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
        // 1) Protect directive calls with balanced parentheses into placeholders.
        self::$directivePlaceholders = [];
        $counter = 0;

        $makePlaceholder = function (string $type) use (&$counter) {
            return function (string $inner) use (&$counter, $type) {
                $counter++;
                $key = "__VIEW_" . strtoupper(trim($type, '@')) . "_" . $counter . "__";
                Parser::$directivePlaceholders[$key] = [
                    'type' => $type,
                    'inner' => $inner,
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

        // 2) Compile the template using a streaming parser.
        return self::compileStream($template, false);
    }

    /**
     * Streaming compiler over the template string.
     *
     * @param string $template Full template string (with placeholders already injected).
     * @param bool   $noZog    When true, DOM-level attributes (w-if, w-for, ...)
     *                         are ignored in this subtree; inline directives still work.
     */
    protected static function compileStream(string $template, bool $noZog): string
    {
        $out = '';
        $length = strlen($template);
        $i = 0;
        $noZogStack = [$noZog];

        while ($i < $length) {
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
                    $out .= $phpBlock;
                    $i = $end + 2;
                    continue;
                }

                // Unexpected closing tag at this level (should have been consumed inside a parent).
                if ($i + 1 < $length && $template[$i + 1] === '/') {
                    [$tagName] = self::parseEndTag($template, $i);
                    throw new ViewTemplateException(
                        "Unexpected closing tag </{$tagName}> without a matching opening tag."
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

                    if ($value === null) {
                        $attrHtml .= ' ' . $name;
                    } else {
                        $compiledValue = self::compileAttributeValue($value);
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
                        $deepNoZog
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

                    $inner = self::compileInnerHtml($template, $i, $tagName);
                    $i = $inner['endPos'];
                    $innerHtml = self::compileStream($inner['innerHtml'], true);

                    $out .= $openHtml . $innerHtml . $closeHtml;
                    array_pop($noZogStack);
                    continue;
                }

                // If we have w-if / w-else-if / w-else, we need to collect the full chain.
                if ($zpIf !== null || $zpElseIf !== null || $isZpElse) {
                    [$chainHtml, $endPos] = self::compileIfChainStream(
                        $template,
                        $i,
                        $tagInfo,
                        $openHtml,
                        $closeHtml
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

                    if ($selfClosing) {
                        $out .= $loopPhp . $openHtml . $closeHtml . '<?php endforeach; ?>';
                        array_pop($noZogStack);
                        continue;
                    }

                    $innerInfo = self::compileInnerHtml($template, $i, $tagName);
                    $i = $innerInfo['endPos'];
                    $innerHtml = self::compileStream($innerInfo['innerHtml'], false);

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

                $innerInfo = self::compileInnerHtml($template, $i, $tagName);
                $i = $innerInfo['endPos'];
                $innerHtml = self::compileStream($innerInfo['innerHtml'], false);

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
                $out .= self::compileText($text);
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
        bool $deepNoZog
    ): array {
        $len = strlen($template);
        $tagLower = strtolower($tagName);
        $needle = '</' . $tagLower;
        $closeStart = stripos($template, $needle, $innerStartPos);

        if ($closeStart === false) {
            throw new ViewTemplateException("Unclosed <{$tagName}> tag.");
        }

        $closeEnd = strpos($template, '>', $closeStart);
        if ($closeEnd === false) {
            throw new ViewTemplateException("Unclosed </{$tagName}> tag.");
        }

        $innerRaw = substr($template, $innerStartPos, $closeStart - $innerStartPos);
        $endPos = $closeEnd + 1;
        $closeHtml = '</' . $tagName . '>';

        if ($deepNoZog) {
            $innerHtml = $innerRaw;
        } else {
            $innerHtml = self::compileText($innerRaw);
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
            $nameStart = $i;
            while (
                $i < $len
                && !ctype_space($template[$i])
                && $template[$i] !== '='
                && $template[$i] !== '>'
                && !($template[$i] === '/' && $i + 1 < $len && $template[$i + 1] === '>')
            ) {
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
                    while ($i < $len && $template[$i] !== $quote) {
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
                    continue;
                }
                if ($lname === 'w-else-if') {
                    $zpElseIf = $value ?? '';
                    continue;
                }
                if ($lname === 'w-else') {
                    $isZpElse = true;
                    continue;
                }
                if ($lname === 'w-for') {
                    $zpFor = $value ?? '';
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
        string $tagName
    ): array {
        $len = strlen($template);
        $depth = 1;
        $i = $startPos;
        $tagLower = strtolower($tagName);

        while ($i < $len && $depth > 0) {
            $pos = strpos($template, '<', $i);
            if ($pos === false) {
                // No more tags; the element is never closed.
                throw new ViewTemplateException("Unclosed <{$tagName}> tag.");
            }

            // Comment
            if ($pos + 3 < $len && substr($template, $pos, 4) === '<!--') {
                $endComment = strpos($template, '-->', $pos + 4);
                if ($endComment === false) {
                    throw new ViewTemplateException('Unterminated HTML comment inside <' . $tagName . '>.');
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
                    throw new ViewTemplateException('Unterminated markup declaration inside <' . $tagName . '>.');
                }
                $i = $declEnd + 1;
                continue;
            }

            if ($pos + 1 < $len && $template[$pos + 1] === '?') {
                $phpEnd = strpos($template, '?>', $pos + 2);
                if ($phpEnd === false) {
                    throw new ViewTemplateException('Unterminated PHP block inside <' . $tagName . '>.');
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
                    throw new ViewTemplateException(
                        "Unclosed <{$nestedTag['tag']}> tag inside <{$tagName}>."
                    );
                }
                $closeEnd = strpos($template, '>', $closeStart);
                if ($closeEnd === false) {
                    throw new ViewTemplateException(
                        "Unclosed </{$nestedTag['tag']}> tag inside <{$tagName}>."
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

        throw new ViewTemplateException("Unclosed <{$tagName}> tag.");
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
        string $firstCloseHtml
    ): array {
        $len = strlen($template);
        $i = $posAfterFirstStart;
        $branches = [];

        // helper to compile a single branch element + its inner HTML
        $compileBranch = function (array $tagInfo, string $openHtml, string $closeHtml, int &$pos) use ($template): string {
            $tagName = $tagInfo['tag'];
            if ($tagInfo['selfClosing']) {
                // self closing elements cannot have inner w-if logic; just wrap them
                return $openHtml . $closeHtml;
            }

            $innerInfo = Parser::compileInnerHtml($template, $pos, $tagName);
            $pos = $innerInfo['endPos'];
            $innerHtml = Parser::compileStream($innerInfo['innerHtml'], false);
            return $openHtml . $innerHtml . $closeHtml;
        };

        // first branch is always "if"
        $branches[] = [
            'type' => 'if',
            'expr' => self::ensurePhpExpression((string) $firstTagInfo['zpIfExpr']),
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

                if ($value === null) {
                    $attrHtml .= ' ' . $name;
                } else {
                    $compiledValue = self::compileAttributeValue($value);
                    $attrHtml .= ' ' . $name . '="' . $compiledValue . '"';
                }
            }

            $openHtml = '<' . $tagInfo['tag'] . $attrHtml . ($tagInfo['selfClosing'] ? ' />' : '>');
            $closeHtml = $tagInfo['selfClosing'] ? '' : '</' . $tagInfo['tag'] . '>';

            if ($tagInfo['zpElseIfExpr'] !== null) {
                $branches[] = [
                    'type' => 'elseif',
                    'expr' => self::ensurePhpExpression((string) $tagInfo['zpElseIfExpr']),
                    'html' => $compileBranch($tagInfo, $openHtml, $closeHtml, $i),
                ];
                continue;
            }

            if ($tagInfo['isZpElse']) {
                $branches[] = [
                    'type' => 'else',
                    'expr' => null,
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

                $out .= "<?php if ({$branch['expr']}): ?>" . $branch['html'];
            } elseif ($branch['type'] === 'elseif') {

                $out .= "<?php elseif ({$branch['expr']}): ?>" . $branch['html'];
            } else {
                // Else branch has no expression.
                $out .= "<?php else: ?>" . $branch['html'];
            }
        }

        // Close the if-chain
        $out .= '<?php endif; ?>';

        return [$out, $i];
    }

    /**
     * Compile plain text node content:
     *   - placeholders for protected directives are restored here
     *   - @{{ expr }}               => escaped echo
     *   - @endsection               => View::endSection()
     */
    protected static function compileText(string $text): string
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

                    // emulate original transforms
                    if ($type === '@php') {
                        $code = trim($inner);
                        if ($code === '') {
                            throw new ViewTemplateException('@php() requires non-empty code.');
                        }
                        return '<?php ' . $code . ' ?>';
                    }

                    if ($type === '@json' || $type === '@tojs') {
                        return Parser::buildJsonDirective($inner);
                    }

                    if ($type === '@raw') {
                        $expr = trim($inner);
                        if ($expr === '') {
                            throw new ViewTemplateException('@raw() requires a non-empty expression.');
                        }
                        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $expr) && $expr[0] !== '$') {
                            $expr = '$' . $expr;
                        }
                        return '<?php echo ' . $expr . '; ?>';
                    }

                    if ($type === '@section') {
                        $args = trim($inner);
                        if ($args === '') {
                            throw new ViewTemplateException('@section() requires a section name.');
                        }
                        return '<?php \\Webrium\\View\\View::startSection(' . $args . '); ?>';
                    }

                    if ($type === '@yield') {
                        $args = trim($inner);
                        if ($args === '') {
                            throw new ViewTemplateException('@yield() requires a section name.');
                        }
                        return '<?php echo \\Webrium\\View\\View::yieldSection(' . $args . '); ?>';
                    }

                    if ($type === '@component') {
                        $args = trim($inner);
                        if ($args === '') {
                            throw new ViewTemplateException('@component() requires at least a view name.');
                        }
                        return '<?php echo \\Webrium\\View\\View::component(' . $args . '); ?>';
                    }

                    return $key;
                },
                $text
            );
        }

        // Enforce raw PHP directive policy (if still present for some reason)
        if (!Engine::isRawPhpDirectiveAllowed() && strpos($text, '@php(') !== false) {
            throw new ViewTemplateException('@php directive is disabled for security reasons.');
        }

        // Handle @{{ expr }}  (escaped echo)
        $text = (string) preg_replace_callback(
            '/@\{\{\s*(.+?)\s*\}\}/s',
            function (array $m): string {
                $expr = trim($m[1]);
                if ($expr === '') {
                    return '';
                }

                // If expr is a bare identifier, auto-prefix with $
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $expr) && $expr[0] !== '$') {
                    $expr = '$' . $expr;
                }

                return '<?php echo htmlspecialchars(' . $expr . ", ENT_QUOTES, 'UTF-8'); ?>";
            },
            $text
        );

        // Handle @endsection directive (no parentheses)
        if (strpos($text, '@endsection') !== false) {
            $text = (string) preg_replace(
                '/@endsection\b/',
                '<?php \\Webrium\\View\\View::endSection(); ?>',
                $text
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
    protected static function compileAttributeValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // Reuse compileText so behavior inside attributes matches text nodes.
        return self::compileText($value);
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
                throw new ViewTemplateException("Internal error parsing directive {$token}");
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
                throw new ViewTemplateException("Unmatched parentheses in {$token} directive.");
            }

            // Inner contents between the outermost (...)
            $inner = substr($text, $openParenPos + 1, $i - ($openParenPos + 1));

            $replacement = $transform($inner);

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

        // If expr is a bare identifier (like "products"), auto-prefix with $
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $expr) && $expr[0] !== '$') {
            $expr = '$' . $expr;
        }

        return '<?php echo json_encode(' . $expr
            . ', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>';
    }

    /**
     * Parse w-for expression, e.g.:
     *   "product of $products"
     *   "product, key of $products"
     *
     * Returns array [collectionExpr, $itemVar, $keyVarOrNull].
     */
    protected static function parseForExpression(string $expr): array
    {
        $expr = trim($expr);
        if ($expr === '') {
            throw new ViewTemplateException('Empty w-for expression.');
        }

        // pattern: item, key of collection   (with optional '$' on item/key)
        if (
            preg_match(
                '/^\$?([A-Za-z_][A-Za-z0-9_]*)\s*,\s*\$?([A-Za-z_][A-Za-z0-9_]*)\s+of\s+(.+)$/',
                $expr,
                $m
            )
        ) {
            // ensurePhpVariable adds the '$' prefix when needed
            $itemVar = self::ensurePhpVariable($m[1]);
            $keyVar = self::ensurePhpVariable($m[2]);
            $collectionExpr = self::ensurePhpExpression($m[3]);

            return [$collectionExpr, $itemVar, $keyVar];
        }

        // pattern: item of collection (with optional '$' on item)
        if (
            preg_match(
                '/^\$?([A-Za-z_][A-Za-z0-9_]*)\s+of\s+(.+)$/',
                $expr,
                $m
            )
        ) {
            $itemVar = self::ensurePhpVariable($m[1]);
            $collectionExpr = self::ensurePhpExpression($m[2]);

            return [$collectionExpr, $itemVar, null];
        }

        throw new ViewTemplateException('Invalid w-for expression: ' . $expr);
    }

    /**
     * Ensure a variable name is prefixed with '$'.
     */
    protected static function ensurePhpVariable(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new ViewTemplateException('Empty variable name in w-for expression.');
        }

        if ($name[0] !== '$') {
            $name = '$' . $name;
        }

        return $name;
    }

    /**
     * Ensure a PHP expression is non-empty.
     * (Caller is responsible for making it syntactically valid PHP.)
     */
    protected static function ensurePhpExpression(string $expr): string
    {
        $expr = trim($expr);
        if ($expr === '') {
            throw new ViewTemplateException('Empty PHP expression in template.');
        }
        return $expr;
    }
}

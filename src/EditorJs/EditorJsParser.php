<?php

namespace Zog\EditorJs;

/**
 * EditorJsParser
 *
 * Converts Editor.js JSON output to clean HTML.
 * Supports all default Editor.js block types.
 *
 * @package Zog\EditorJs
 */
class EditorJsParser
{
    /** @var array<string, array<string, string>> CSS class config per block type */
    private array $config;

    /** @var array<string, callable> Custom block handlers registered by the user */
    private array $customHandlers = [];

    /** @var bool Whether to sanitize inline HTML in text blocks */
    private bool $sanitize;

    /** @var array<string> Allowed inline HTML tags when sanitization is enabled */
    private array $allowedInlineTags;

    /**
     * @param array<string, array<string, string>> $config    Per-block CSS class overrides.
     * @param bool                                 $sanitize  Strip unsafe inline tags (default: true).
     */
    public function __construct(array $config = [], bool $sanitize = true)
    {
        $this->sanitize          = $sanitize;
        $this->allowedInlineTags = ['b', 'strong', 'i', 'em', 'u', 'a', 'mark', 'code', 's', 'br', 'span'];

        $this->config = array_replace_recursive($this->defaultConfig(), $config);
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Parse an Editor.js JSON string or pre-decoded array and return HTML.
     *
     * @param  string|array<string, mixed> $json
     * @return string
     * @throws \InvalidArgumentException
     */
    public function parse(string|array $json): string
    {
        $data = is_string($json) ? json_decode($json, true) : $json;

        if (!is_array($data)) {
            throw new \InvalidArgumentException('EditorJsParser: invalid JSON input.');
        }

        if (!array_key_exists('blocks', $data)) {
            throw new \InvalidArgumentException('EditorJsParser: "blocks" key is missing or not an array.');
        }

        $blocks = $data['blocks'];

        if (!is_array($blocks)) {
            throw new \InvalidArgumentException('EditorJsParser: "blocks" key is missing or not an array.');
        }

        $html = '';

        foreach ($blocks as $block) {
            $type = $block['type'] ?? '';
            $blockData = $block['data'] ?? [];

            $html .= $this->renderBlock($type, $blockData);
        }

        return $html;
    }

    /**
     * Register a custom handler for a block type.
     *
     * The callable receives (array $data, array $config) and must return a string.
     *
     * @param  string   $type
     * @param  callable $handler
     * @return static
     */
    public function registerBlock(string $type, callable $handler): static
    {
        $this->customHandlers[$type] = $handler;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Block dispatcher
    // -------------------------------------------------------------------------

    private function renderBlock(string $type, array $data): string
    {
        // User-registered custom handler takes priority
        if (isset($this->customHandlers[$type])) {
            return ($this->customHandlers[$type])($data, $this->config[$type] ?? []);
        }

        return match ($type) {
            'paragraph'  => $this->paragraph($data),
            'header'     => $this->header($data),
            'list'       => $this->list($data),
            'nestedList' => $this->nestedList($data),
            'image'      => $this->image($data),
            'quote'      => $this->quote($data),
            'code'       => $this->code($data),
            'table'      => $this->table($data),
            'delimiter'  => $this->delimiter(),
            'embed'      => $this->embed($data),
            'warning'    => $this->warning($data),
            'raw'        => $this->raw($data),
            'checklist'  => $this->checklist($data),
            'linkTool'   => $this->linkTool($data),
            'attaches'   => $this->attaches($data),
            'personality' => $this->personality($data),
            default      => $this->unknown($type, $data),
        };
    }

    // -------------------------------------------------------------------------
    // Block renderers
    // -------------------------------------------------------------------------

    private function paragraph(array $data): string
    {
        $text = $this->inlineText($data['text'] ?? '');
        if ($text === '') return '';

        $cfg   = $this->config['paragraph'];
        $class = $this->classAttr($cfg['class'] ?? '');

        return "<p{$class}>{$text}</p>\n";
    }

    private function header(array $data): string
    {
        $text  = $this->inlineText($data['text'] ?? '');
        $level = max(1, min(6, (int)($data['level'] ?? 2)));

        if ($text === '') return '';

        $cfg   = $this->config['header'];
        $class = $this->classAttr($cfg['class'] ?? '');

        return "<h{$level}{$class}>{$text}</h{$level}>\n";
    }

    private function list(array $data): string
    {
        $items = $data['items'] ?? [];
        $style = ($data['style'] ?? 'unordered') === 'ordered' ? 'ol' : 'ul';

        if (empty($items)) return '';

        $cfg      = $this->config['list'];
        $class    = $this->classAttr($cfg['class'] ?? '');
        $itemClass = $this->classAttr($cfg['itemClass'] ?? '');

        $inner = '';
        foreach ($items as $item) {
            $text   = is_array($item) ? ($item['content'] ?? '') : $item;
            $inner .= "<li{$itemClass}>{$this->inlineText($text)}</li>\n";
        }

        return "<{$style}{$class}>\n{$inner}</{$style}>\n";
    }

    private function nestedList(array $data): string
    {
        $items = $data['items'] ?? [];
        $style = ($data['style'] ?? 'unordered') === 'ordered' ? 'ol' : 'ul';

        if (empty($items)) return '';

        $cfg   = $this->config['nestedList'] ?? $this->config['list'];
        $class = $this->classAttr($cfg['class'] ?? '');

        return "<{$style}{$class}>\n" . $this->renderNestedItems($items, $style, $cfg) . "</{$style}>\n";
    }

    private function renderNestedItems(array $items, string $style, array $cfg): string
    {
        $itemClass = $this->classAttr($cfg['itemClass'] ?? '');
        $html = '';

        foreach ($items as $item) {
            $text     = is_array($item) ? ($item['content'] ?? '') : $item;
            $children = is_array($item) ? ($item['items'] ?? []) : [];

            $html .= "<li{$itemClass}>{$this->inlineText($text)}";

            if (!empty($children)) {
                $html .= "\n<{$style}>\n" . $this->renderNestedItems($children, $style, $cfg) . "</{$style}>\n";
            }

            $html .= "</li>\n";
        }

        return $html;
    }

    private function image(array $data): string
    {
        $src     = htmlspecialchars($data['file']['url'] ?? $data['url'] ?? '', ENT_QUOTES, 'UTF-8');
        $caption = $this->inlineText($data['caption'] ?? '');

        if ($src === '') return '';

        $cfg         = $this->config['image'];
        $figClass    = $this->classAttr($cfg['figureClass'] ?? '');
        $imgClass    = $this->buildImageClass($data, $cfg);
        $capClass    = $this->classAttr($cfg['captionClass'] ?? '');
        $alt         = htmlspecialchars(strip_tags($caption), ENT_QUOTES, 'UTF-8');

        $img = "<img{$imgClass} src=\"{$src}\" alt=\"{$alt}\">";

        if ($caption !== '') {
            return "<figure{$figClass}>\n{$img}\n<figcaption{$capClass}>{$caption}</figcaption>\n</figure>\n";
        }

        return "<figure{$figClass}>\n{$img}\n</figure>\n";
    }

    private function buildImageClass(array $data, array $cfg): string
    {
        $classes = [];

        if (!empty($cfg['class'])) {
            $classes[] = $cfg['class'];
        }
        if (!empty($data['withBorder']) && !empty($cfg['borderClass'])) {
            $classes[] = $cfg['borderClass'];
        }
        if (!empty($data['stretched']) && !empty($cfg['stretchedClass'])) {
            $classes[] = $cfg['stretchedClass'];
        }
        if (!empty($data['withBackground']) && !empty($cfg['backgroundClass'])) {
            $classes[] = $cfg['backgroundClass'];
        }

        return $classes ? ' class="' . implode(' ', $classes) . '"' : '';
    }

    private function quote(array $data): string
    {
        $text    = $this->inlineText($data['text'] ?? '');
        $caption = $this->inlineText($data['caption'] ?? '');
        $align   = in_array($data['alignment'] ?? '', ['left', 'center', 'right']) ? $data['alignment'] : 'left';

        if ($text === '') return '';

        $cfg          = $this->config['quote'];
        $bqClass      = $this->classAttr(($cfg['class'] ?? '') . ($cfg['align'] ? " {$cfg['alignPrefix']}{$align}" : ''));
        $captionClass = $this->classAttr($cfg['captionClass'] ?? '');

        $html = "<blockquote{$bqClass}>\n<p>{$text}</p>\n";

        if ($caption !== '') {
            $html .= "<cite{$captionClass}>{$caption}</cite>\n";
        }

        return $html . "</blockquote>\n";
    }

    private function code(array $data): string
    {
        $code = htmlspecialchars($data['code'] ?? '', ENT_QUOTES, 'UTF-8');

        if ($code === '') return '';

        $cfg        = $this->config['code'];
        $preClass   = $this->classAttr($cfg['class'] ?? '');
        $codeClass  = $this->classAttr($cfg['codeClass'] ?? '');
        $lang       = htmlspecialchars($data['language'] ?? '', ENT_QUOTES, 'UTF-8');
        $langAttr   = $lang ? " data-language=\"{$lang}\"" : '';

        return "<pre{$preClass}{$langAttr}><code{$codeClass}>{$code}</code></pre>\n";
    }

    private function table(array $data): string
    {
        $content     = $data['content'] ?? [];
        $withHeadings = !empty($data['withHeadings']);

        if (empty($content)) return '';

        $cfg        = $this->config['table'];
        $tableClass = $this->classAttr($cfg['class'] ?? '');
        $thClass    = $this->classAttr($cfg['thClass'] ?? '');
        $tdClass    = $this->classAttr($cfg['tdClass'] ?? '');

        $html = "<table{$tableClass}>\n";

        foreach ($content as $rowIndex => $row) {
            if (!is_array($row)) continue;

            if ($withHeadings && $rowIndex === 0) {
                $html .= "<thead>\n<tr>\n";
                foreach ($row as $cell) {
                    $html .= "<th{$thClass}>{$this->inlineText($cell)}</th>\n";
                }
                $html .= "</tr>\n</thead>\n<tbody>\n";
            } else {
                $html .= "<tr>\n";
                foreach ($row as $cell) {
                    $html .= "<td{$tdClass}>{$this->inlineText($cell)}</td>\n";
                }
                $html .= "</tr>\n";
            }
        }

        if ($withHeadings && count($content) > 1) {
            $html .= "</tbody>\n";
        }

        return $html . "</table>\n";
    }

    private function delimiter(): string
    {
        $cfg   = $this->config['delimiter'];
        $class = $this->classAttr($cfg['class'] ?? '');

        return "<hr{$class}>\n";
    }

    private function embed(array $data): string
    {
        $service = htmlspecialchars($data['service'] ?? '', ENT_QUOTES, 'UTF-8');
        $src     = htmlspecialchars($data['embed'] ?? '', ENT_QUOTES, 'UTF-8');
        $caption = $this->inlineText($data['caption'] ?? '');
        $width   = (int)($data['width'] ?? 0);
        $height  = (int)($data['height'] ?? 0);

        if ($src === '') return '';

        $cfg         = $this->config['embed'];
        $wrapClass   = $this->classAttr(($cfg['class'] ?? '') . ($service ? " {$cfg['servicePrefix']}{$service}" : ''));
        $iframeClass = $this->classAttr($cfg['iframeClass'] ?? '');
        $capClass    = $this->classAttr($cfg['captionClass'] ?? '');

        $sizeAttr = '';
        if ($width > 0)  $sizeAttr .= " width=\"{$width}\"";
        if ($height > 0) $sizeAttr .= " height=\"{$height}\"";

        $iframe = "<iframe{$iframeClass} src=\"{$src}\"{$sizeAttr} allowfullscreen></iframe>";
        $html   = "<div{$wrapClass}>\n{$iframe}\n";

        if ($caption !== '') {
            $html .= "<p{$capClass}>{$caption}</p>\n";
        }

        return $html . "</div>\n";
    }

    private function warning(array $data): string
    {
        $title   = $this->inlineText($data['title'] ?? '');
        $message = $this->inlineText($data['message'] ?? '');

        if ($title === '' && $message === '') return '';

        $cfg         = $this->config['warning'];
        $wrapClass   = $this->classAttr($cfg['class'] ?? '');
        $titleClass  = $this->classAttr($cfg['titleClass'] ?? '');
        $msgClass    = $this->classAttr($cfg['messageClass'] ?? '');

        $html = "<div{$wrapClass}>\n";

        if ($title !== '') {
            $html .= "<p{$titleClass}><strong>{$title}</strong></p>\n";
        }
        if ($message !== '') {
            $html .= "<p{$msgClass}>{$message}</p>\n";
        }

        return $html . "</div>\n";
    }

    private function raw(array $data): string
    {
        // Raw HTML — output as-is (user is responsible for safety)
        return ($data['html'] ?? '') . "\n";
    }

    private function checklist(array $data): string
    {
        $items = $data['items'] ?? [];

        if (empty($items)) return '';

        $cfg       = $this->config['checklist'];
        $ulClass   = $this->classAttr($cfg['class'] ?? '');
        $itemClass = $cfg['itemClass'] ?? '';
        $checkedClass = $cfg['checkedClass'] ?? '';

        $html = "<ul{$ulClass}>\n";

        foreach ($items as $item) {
            $text    = $this->inlineText($item['text'] ?? '');
            $checked = !empty($item['checked']);
            $liClass = $this->classAttr($itemClass . ($checked && $checkedClass ? " {$checkedClass}" : ''));
            $checkbox = $checked ? 'checked' : '';

            $html .= "<li{$liClass}><input type=\"checkbox\" {$checkbox} disabled> {$text}</li>\n";
        }

        return $html . "</ul>\n";
    }

    private function linkTool(array $data): string
    {
        $url  = htmlspecialchars($data['link'] ?? '', ENT_QUOTES, 'UTF-8');
        $meta = $data['meta'] ?? [];

        if ($url === '') return '';

        $title       = htmlspecialchars($meta['title'] ?? $url, ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars($meta['description'] ?? '', ENT_QUOTES, 'UTF-8');
        $imageUrl    = htmlspecialchars($meta['image']['url'] ?? '', ENT_QUOTES, 'UTF-8');

        $cfg       = $this->config['linkTool'];
        $wrapClass = $this->classAttr($cfg['class'] ?? '');
        $imgClass  = $this->classAttr($cfg['imageClass'] ?? '');

        $html = "<div{$wrapClass}>\n<a href=\"{$url}\" target=\"_blank\" rel=\"noopener noreferrer\">\n";

        if ($imageUrl !== '') {
            $html .= "<img{$imgClass} src=\"{$imageUrl}\" alt=\"{$title}\">\n";
        }

        $html .= "<strong>{$title}</strong>\n";

        if ($description !== '') {
            $html .= "<span>{$description}</span>\n";
        }

        return $html . "</a>\n</div>\n";
    }

    private function attaches(array $data): string
    {
        $url  = htmlspecialchars($data['file']['url'] ?? '', ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars($data['title'] ?? $data['file']['name'] ?? 'Download', ENT_QUOTES, 'UTF-8');
        $size = isset($data['file']['size']) ? $this->formatFileSize((int)$data['file']['size']) : '';

        if ($url === '') return '';

        $cfg       = $this->config['attaches'];
        $wrapClass = $this->classAttr($cfg['class'] ?? '');

        return "<div{$wrapClass}>\n<a href=\"{$url}\" download>{$name}" . ($size ? " <span>({$size})</span>" : '') . "</a>\n</div>\n";
    }

    private function personality(array $data): string
    {
        $name        = htmlspecialchars($data['name'] ?? '', ENT_QUOTES, 'UTF-8');
        $description = $this->inlineText($data['description'] ?? '');
        $photoUrl    = htmlspecialchars($data['photo'] ?? '', ENT_QUOTES, 'UTF-8');
        $link        = htmlspecialchars($data['link'] ?? '', ENT_QUOTES, 'UTF-8');

        if ($name === '') return '';

        $cfg       = $this->config['personality'];
        $wrapClass = $this->classAttr($cfg['class'] ?? '');
        $imgClass  = $this->classAttr($cfg['imageClass'] ?? '');

        $html = "<div{$wrapClass}>\n";

        if ($photoUrl !== '') {
            $html .= "<img{$imgClass} src=\"{$photoUrl}\" alt=\"{$name}\">\n";
        }

        $nameTag = $link ? "<a href=\"{$link}\" target=\"_blank\" rel=\"noopener noreferrer\">{$name}</a>" : $name;
        $html .= "<strong>{$nameTag}</strong>\n";

        if ($description !== '') {
            $html .= "<p>{$description}</p>\n";
        }

        return $html . "</div>\n";
    }

    private function unknown(string $type, array $data): string
    {
        // Silently skip unknown blocks — do not output anything
        return '';
    }

    // -------------------------------------------------------------------------
    // Inline text / sanitization
    // -------------------------------------------------------------------------

    /**
     * Sanitize or pass through inline HTML from Editor.js text fields.
     *
     * Editor.js produces inline markup like <b>, <i>, <a href="...">, <mark>, etc.
     * When $sanitize is true only allowed tags are kept.
     */
    private function inlineText(string $text): string
    {
        if ($text === '') return '';

        if (!$this->sanitize) {
            return $text;
        }

        $allowed = '<' . implode('><', $this->allowedInlineTags) . '>';

        return strip_tags($text, $allowed);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function classAttr(string $class): string
    {
        $class = trim($class);
        return $class !== '' ? " class=\"{$class}\"" : '';
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }

    // -------------------------------------------------------------------------
    // Default config
    // -------------------------------------------------------------------------

    private function defaultConfig(): array
    {
        return [
            'paragraph'   => ['class' => ''],
            'header'      => ['class' => ''],
            'list'        => ['class' => '', 'itemClass' => ''],
            'nestedList'  => ['class' => '', 'itemClass' => ''],
            'image'       => [
                'class'           => '',
                'figureClass'     => '',
                'captionClass'    => '',
                'borderClass'     => 'image--bordered',
                'stretchedClass'  => 'image--stretched',
                'backgroundClass' => 'image--background',
            ],
            'quote'       => [
                'class'        => '',
                'captionClass' => '',
                'align'        => true,
                'alignPrefix'  => 'quote--',
            ],
            'code'        => ['class' => '', 'codeClass' => ''],
            'table'       => ['class' => '', 'thClass' => '', 'tdClass' => ''],
            'delimiter'   => ['class' => ''],
            'embed'       => [
                'class'         => '',
                'iframeClass'   => '',
                'captionClass'  => '',
                'servicePrefix' => 'embed--',
            ],
            'warning'     => ['class' => 'cdx-warning', 'titleClass' => '', 'messageClass' => ''],
            'checklist'   => ['class' => '', 'itemClass' => '', 'checkedClass' => ''],
            'linkTool'    => ['class' => '', 'imageClass' => ''],
            'attaches'    => ['class' => ''],
            'personality' => ['class' => '', 'imageClass' => ''],
        ];
    }
}
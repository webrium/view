<?php
declare(strict_types=1);

namespace Webrium\View;

/**
 * View helpers for layouts, sections and components.
 */
class View
{
    /**
     * Registered sections: name => html
     *
     * @var array<string,string>
     */
    protected static array $sections = [];

    /**
     * Stack of open section names for nested sections.
     *
     * @var string[]
     */
    protected static array $sectionStack = [];

    /**
     * Simple alias to render a view.
     */
    public static function make(string $view, array $data = []): string
    {
        return Engine::render($view, $data);
    }

    /**
     * Render a view and return it as a reusable component/partial.
     */
    public static function component(string $view, array $data = []): string
    {
        return Engine::render($view, $data);
    }

    /**
     * Render a child view inside a layout using sections.
     *
     * Usage:
     *   echo View::renderWithLayout('pages/home.php', 'layouts/main.php', $data);
     *
     * Child view:
     *   @section('title') Home @endsection
     *   @section('content')
     *       <h1>Hello</h1>
     *   @endsection
     *
     * Layout:
     *   <title>@yield('title', 'Default')</title>
     *   <main>@yield('content')</main>
     */
    public static function renderWithLayout(
        string $view,
        string $layout,
        array $data = []
    ): string {
        self::clearSections();

        try {
            // 1) Render child view first so it can register sections.
            $childHtml = Engine::render($view, $data);

            // 2) If no explicit "content" section is defined, use full child HTML.
            if (!array_key_exists('content', self::$sections)) {
                self::$sections['content'] = $childHtml;
            }

            // 3) Render layout; @yield() inside layout will read from sections.
            return Engine::render($layout, $data);
        } finally {
            self::clearSections();
        }
    }

    /**
     * Render a layout from pre-rendered section HTML.
     *
     * @param array<string,string> $sections
     */
    public static function renderLayoutWithSections(
        string $layout,
        array $sections,
        array $data = []
    ): string {
        self::clearSections();

        try {
            foreach ($sections as $name => $content) {
                self::setSection((string) $name, $content);
            }

            return Engine::render($layout, $data);
        } finally {
            self::clearSections();
        }
    }

    /**
     * Render a child view and return one section without rendering a layout.
     */
    public static function renderSection(
        string $view,
        string $section,
        array $data = []
    ): string {
        $section = trim($section);
        if ($section === '') {
            throw new ViewException('Section name cannot be empty.');
        }

        self::clearSections();

        try {
            Engine::render($view, $data);

            if (!array_key_exists($section, self::$sections)) {
                throw new ViewException("View '{$view}' did not define section '{$section}'.");
            }

            return self::$sections[$section];
        } finally {
            self::clearSections();
        }
    }

    /**
     * Register pre-rendered HTML for a layout section.
     */
    public static function setSection(string $name, string $content): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new ViewException('Section name cannot be empty.');
        }

        self::$sections[$name] = $content;
    }

    /**
     * Start capturing a named section.
     */
    public static function startSection(string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new ViewException('Section name cannot be empty.');
        }

        self::$sectionStack[] = $name;
        ob_start();
    }

    /**
     * End the current section and store its buffered content.
     */
    public static function endSection(): void
    {
        if (empty(self::$sectionStack)) {
            throw new ViewException('Cannot end section: no open section.');
        }

        $name = array_pop(self::$sectionStack);
        $content = (string) ob_get_clean();

        self::$sections[$name] = $content;
    }

    /**
     * Output a section's contents or a default value.
     */
    public static function yieldSection(string $name, string $default = ''): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new ViewException('Section name cannot be empty.');
        }

        if (array_key_exists($name, self::$sections)) {
            return self::$sections[$name];
        }

        return $default;
    }

    /**
     * Clear all sections and section stack.
     */
    public static function clearSections(): void
    {
        self::$sections = [];
        self::$sectionStack = [];
    }
}

<?php

if (! function_exists('view')) {
    /**
     * Render a view template and return the compiled HTML.
     *
     * @param  string $name    View name relative to the views directory.
     * @param  array  $params  Data to pass into the view as variables.
     * @return string          Rendered HTML output.
     */
    function view(string $name, array $params = []): string
    {
        return \Webrium\View\Engine::render($name, $params);
    }
}

if (! function_exists('layout')) {
    /**
     * Render a view inside a layout template.
     *
     * @param  string $layoutView  Layout view path.
     * @param  string $view        Child view path.
     * @param  array  $data        Shared data for both layout and child view.
     * @return string              Rendered HTML output.
     */
    function layout(string $layoutView, string $view, array $data = []): string
    {
        return \Webrium\View\Engine::renderLayout($layoutView, $view, $data);
    }
}
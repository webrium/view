# Webrium View

Lightweight PHP template engine with hybrid static caching (no `eval`, no `DOMDocument`) for the [Webrium](https://github.com/webrium) framework.

- **GitHub:** https://github.com/webrium/view
- **Packagist:** https://packagist.org/packages/webrium/view
- **Install:** `composer require webrium/view`

Webrium View gives you a tiny template engine plus an optional static HTML cache. It compiles your templates to plain PHP files, never uses `eval`, and is designed to play nicely with modern frontend frameworks (Vue, Alpine, Livewire, etc.) by leaving their attributes untouched.

## Features

- **DOM-less streaming compiler** – custom HTML parser, no `DOMDocument`, so attributes like `@click`, `:class`, `x-data`, `wire:click`, `hx-get`, etc. are preserved exactly as written.
- **Hybrid static cache** – render a page once, save it as static HTML with a TTL, and serve the static file on future requests.
- **Blade-style directives** – `@section`, `@yield`, `@component`, `@{{ }}`, `@raw()`, `@json()` / `@tojs()`, `@php()`.
- **Attribute-based control flow** – `w-if`, `w-else-if`, `w-else`, `w-for` on normal HTML elements.
- **Fine-grained opt-out** – `w-skip` to disable DOM-level processing in a subtree (useful when embedding another templating system).
- **Safe by default** – escaped output for `@{{ }}`, explicit opt-in to raw HTML and raw PHP.

## Requirements

- PHP **8.1+**

No extra PHP extensions are required; Webrium View uses only core functions.

## Installation

### 1. Via Composer (recommended)

```bash
composer require webrium/view
```

Then bootstrap it in your project:

```php
<?php

use Webrium\View\Engine;

require __DIR__ . '/vendor/autoload.php';

Engine::setViewDir(__DIR__ . '/views');                      // where your .php templates live
Engine::setStaticDir(__DIR__ . '/static');                   // where hybrid static files are written
Engine::setCompiledDir(__DIR__ . '/storage/view_compiled');  // where compiled PHP templates are stored
```

> The directories will be created automatically if they do not exist.

### 2. Manual install (alternative)

If you prefer not to use Composer, copy `Engine.php`, `View.php`, and `Parser.php` into your project, keep the `Webrium\View` namespace, and load them via your own autoloader or simple `require` statements.

## Quick Start

### 1. Simple render

**views/hello.php**

```php
<h1>Hello @{{ $name }}!</h1>
<p>Today is @{{ $today }}.</p>
```

**public/index.php**

```php
<?php

use Webrium\View\Engine;

require __DIR__ . '/../vendor/autoload.php';

Engine::setViewDir(__DIR__ . '/../views');

echo Engine::render('hello.php', [
    'name'  => 'Reza',
    'today' => date('Y-m-d'),
]);
```

### 2. Layout + section example

**views/layouts/main.php**

```php
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>@{{ $title }}</title>
</head>
<body>
    <header>
        <h1>My Site</h1>
    </header>

    <main>
        @yield('content')
    </main>
</body>
</html>
```

**views/pages/home.php**

```php
@section('content')
    <h2>Welcome, @{{ $userName }}</h2>
    <p>This is the home page.</p>
@endsection
```

**public/index.php**

```php
<?php

use Webrium\View\Engine;

require __DIR__ . '/../vendor/autoload.php';

Engine::setViewDir(__DIR__ . '/../views');

echo Engine::renderLayout(
    'layouts/main.php',
    'pages/home.php',
    [
        'title'    => 'Home',
        'userName' => 'Reza',
    ]
);
```

## Template Syntax & Directives

Webrium View uses a custom streaming HTML parser (not `DOMDocument`). It scans your HTML, rewrites special attributes and directives into plain PHP, and leaves everything else alone.

### Escaped output – `@{{ ... }}`

Escaped output is the default:

```php
<p>@{{ $user->name }}</p>
```

Compiles to:

```php
<?php echo htmlspecialchars($user->name, ENT_QUOTES, 'UTF-8'); ?>
```

### Raw output – `@raw(...)`

Use raw output only when you are sure the content is safe:

```php
<div>@raw($html)</div>
```

Compiles to:

```php
<?php echo $html; ?>
```

### Raw PHP – `@php(...)`

You can inject raw PHP (enabled by default):

```php
@php($i = 0)
```

If you want to disable this directive for security reasons:

```php
Engine::allowRawPhpDirective(false);
```

Any use of `@php(...)` after that will throw a `ViewTemplateException`.

### JSON / JavaScript – `@json(...)` and `@tojs(...)`

Both directives are equivalent and produce `json_encode`'d output:

```php
<script>
    const items = @json($items);
    const user  = @tojs($user);
</script>
```

You can also use them inside attributes:

```php
<div data-payload="@json($payload)"></div>
```

### Layouts – `@section`, `@endsection`, `@yield`

**Child view**

```php
@section('content')
    <h2>Dashboard</h2>
    <p>Hello @{{ $user->name }}!</p>
@endsection
```

**Layout**

```php
<body>
    @yield('content')
</body>
```

Available helpers in `Webrium\View\View`:

```php
View::startSection($name);
View::endSection();
View::yieldSection($name, $default = '');
View::clearSections();
```

### Components – `@component(...)`

```php
<div class="card">
    @component('components/user-card.php', ['user' => $user])
</div>
```

Or call it directly from PHP:

```php
$html = Engine::component('components/user-card.php', [
    'user' => $user,
]);
```

### Loops – `w-for`

Use `w-for` on an element to generate a `foreach`:

```php
<ul>
    <li w-for="$item, $index of $items">
        @{{ $index }} – @{{ $item }}
    </li>
</ul>
```

Compiles to:

```php
<?php foreach ($items as $index => $item): ?>
    <li>...</li>
<?php endforeach; ?>
```

### Conditionals – `w-if`, `w-else-if`, `w-else`

```php
<p w-if="$user->isAdmin">
    You are an admin.
</p>
<p w-else-if="$user->isModerator">
    You are a moderator.
</p>
<p w-else>
    You are a regular user.
</p>
```

Compiles to:

```php
<?php if ($user->isAdmin): ?>
    <p>You are an admin.</p>
<?php elseif ($user->isModerator): ?>
    <p>You are a moderator.</p>
<?php else: ?>
    <p>You are a regular user.</p>
<?php endif; ?>
```

### Disabling processing in a subtree – `w-skip`

Add `w-skip` to any element to disable DOM-level processing for that element and all its descendants:

```php
<div w-skip>
    <!-- View does NOT compile this w-if -->
    <p w-if="$user->isAdmin">
        This will be rendered exactly as-is in the final HTML.
    </p>
</div>
```

Behavior:

* `w-if`, `w-for`, `w-else-if`, and `w-else` inside this subtree are **not** converted to PHP.
* The `w-skip` attribute itself is removed from the final HTML.
* Inline directives such as `@{{ $something }}` **still work** in text nodes.

Useful when embedding another frontend framework's attributes:

```php
<div w-skip>
    <button v-if="isAdmin">Admin button</button>
</div>
```

### `<script>` / `<style>` behavior

Contents are treated as raw text (not parsed as nested HTML):

* By default, inline directives inside `<script>` / `<style>` **do** work.
* If you put `w-skip` directly on the `<script>` or `<style>` tag, nothing inside is processed and the contents are emitted verbatim.

## Hybrid Static Cache

Signature:

```php
Engine::hybrid(
    string $view,
    string|array $key,
    array|callable|null $dataOrFactory = null,
    ?int $cacheTtl = null
);
```

### Cache TTL constants

```php
use Webrium\View\Engine;

Engine::CACHE_NONE;
Engine::CACHE_A_MINUTE;
Engine::CACHE_AN_HOUR;
Engine::CACHE_A_DAY;
Engine::CACHE_A_WEEK;
```

You can also override the default TTL:

```php
Engine::setDefaultHybridCacheTtl(Engine::CACHE_A_DAY);

// or disable default TTL (TTL must be explicit in each hybrid() call)
Engine::setDefaultHybridCacheTtl(null);
```

### Mode 1 – Direct data (always re-render)

```php
$html = Engine::hybrid(
    'pages/home.php',
    'home',
    [
        'title' => 'Home',
        'user'  => $user,
    ],
    Engine::CACHE_AN_HOUR
);
```

### Mode 2 – Lazy factory (only run when needed)

```php
$html = Engine::hybrid(
    'pages/home.php',
    'home',
    function () use ($db, $userId) {
        $user = $db->getUserById($userId);
        return [
            'title' => 'Home',
            'user'  => $user,
        ];
    },
    Engine::CACHE_AN_HOUR
);
```

The factory is called **only** when no valid cache exists or the cache has expired.

### Mode 3 – Read-only access

```php
$content = Engine::hybrid('pages/home.php', 'home', null);

if ($content === false) {
    $content = Engine::render('pages/home.php', ['title' => 'Home', 'user' => $user]);
}

echo $content;
```

Returns `false` if no valid (non-expired) cache exists.

## Directory Helpers & Clearing Caches

```php
Engine::setViewDir(__DIR__ . '/views');
Engine::setStaticDir(__DIR__ . '/static');
Engine::setCompiledDir(__DIR__ . '/storage/view_compiled');

// Remove all static HTML cache files
Engine::clearStatics();

// Remove all compiled template files
Engine::clearCompiled();
```

## Error Handling

```php
try {
    echo Engine::render('pages/home.php', ['user' => $user]);
} catch (\Webrium\View\ViewTemplateException $e) {
    // template compilation error
} catch (\Webrium\View\ViewException $e) {
    // general runtime error
}
```

`ViewException` covers bad directories, missing view files, and I/O failures.
`ViewTemplateException` covers invalid `w-for` / `w-if` syntax, unclosed tags, unmatched directive parentheses, and disabled `@php()` usage.

## Editor.js Integration

Webrium View includes a built-in parser that converts [Editor.js](https://editorjs.io/) JSON output to clean HTML.

### Supported block types

| Block type | Output |
|---|---|
| `paragraph` | `<p>` |
| `header` | `<h1>`–`<h6>` |
| `list` | `<ul>` / `<ol>` |
| `nestedList` | Nested `<ul>` / `<ol>` |
| `image` | `<figure><img></figure>` |
| `quote` | `<blockquote>` |
| `code` | `<pre><code>` |
| `table` | `<table>` with optional `<thead>` |
| `delimiter` | `<hr>` |
| `embed` | `<iframe>` wrapper |
| `warning` | Alert `<div>` |
| `raw` | Verbatim HTML pass-through |
| `checklist` | `<ul>` with checkboxes |
| `linkTool` | Anchor card with optional image |
| `attaches` | Download link with file size |
| `personality` | Author card |

### Basic usage

```php
use Webrium\View\EditorJs\EditorJsParser;

$parser = new EditorJsParser();
$html   = $parser->parse($json); // JSON string or pre-decoded array
```

Then render it in a template:

```php
<article>
    @raw($content)
</article>
```

Full example:

```php
use Webrium\View\Engine;
use Webrium\View\EditorJs\EditorJsParser;

Engine::setViewDir(__DIR__ . '/views');

$parser  = new EditorJsParser();
$content = $parser->parse($jsonFromDatabase);

echo Engine::render('pages/article.php', compact('content'));
```

### Custom CSS classes

```php
$parser = new EditorJsParser([
    'paragraph' => ['class' => 'prose-p'],
    'header'    => ['class' => 'article-heading'],
    'image'     => [
        'figureClass'     => 'image-wrap',
        'class'           => 'article-img',
        'captionClass'    => 'image-caption',
        'borderClass'     => 'image--bordered',
        'stretchedClass'  => 'image--stretched',
        'backgroundClass' => 'image--background',
    ],
    'quote' => ['class' => 'pullquote', 'captionClass' => 'pullquote__author'],
    'code'  => ['class' => 'code-block', 'codeClass' => 'language-php'],
]);
```

### Registering custom block handlers

```php
$parser->registerBlock('alert', function (array $data, array $config): string {
    $type    = htmlspecialchars($data['type'] ?? 'info', ENT_QUOTES, 'UTF-8');
    $message = htmlspecialchars($data['message'] ?? '', ENT_QUOTES, 'UTF-8');
    return "<div class=\"alert alert--{$type}\">{$message}</div>\n";
});
```

### Inline HTML sanitization

By default the parser allows common inline tags and strips everything else. To disable sanitization:

```php
$parser = new EditorJsParser(config: [], sanitize: false);
```

> **Note:** The `raw` block type always passes HTML through as-is, regardless of the `sanitize` flag.

## Notes

* Webrium View does **not** use `eval`; compiled templates are normal PHP files that are `require`d.
* All data passed into `render()` is available both as individual variables (`$user`, `$title`, etc.) and as a `$zogData` array inside the template.

## License

MIT

## Contributing

Open issues and pull requests on [GitHub](https://github.com/webrium/view). Ideas, bug reports, and feature suggestions are very welcome.

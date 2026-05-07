
# Zog PHP

Lightweight PHP template engine with hybrid static caching (no `eval`, no `DOMDocument`).

- **GitHub:** https://github.com/zogjs/zog-php  
- **Packagist:** https://packagist.org/packages/zogjs/zog-php  
- **Install:** `composer require zogjs/zog-php`

Zog gives you a tiny, framework-agnostic view layer plus an optional static HTML cache in front of it. It compiles your templates to plain PHP files, never uses `eval`, and is designed to play nicely with modern frontend frameworks (Vue, Alpine, Livewire, etc.) by leaving their attributes untouched.

## Features

- **DOM-less streaming compiler** – custom HTML parser, no `DOMDocument`, so attributes like `@click`, `:class`, `x-data`, `wire:click`, `hx-get`, etc. are preserved exactly as written.
- **Hybrid static cache** – render a page once, save it as static HTML with a TTL, and serve the static file on future requests.
- **Blade-style directives** – `@section`, `@yield`, `@component`, `@{{ }}`, `@raw()`, `@json()` / `@tojs()`, `@php()`.
- **Attribute-based control flow** – `zp-if`, `zp-else-if`, `zp-else`, `zp-for` on normal HTML elements.
- **Fine-grained opt-out** – `zp-nozog` to disable DOM-level processing in a subtree (useful when embedding another templating system).
- **Safe by default** – escaped output for `@{{ }}`, explicit opt-in to raw HTML and raw PHP.

## Requirements

- PHP **8.1+** :contentReference[oaicite:0]{index=0}  

No extra PHP extensions are required; Zog uses only core functions.

## Installation

### 1. Via Composer (recommended)

```bash
composer require zogjs/zog-php


Then bootstrap it in your project:

```php
<?php

use Zog\Zog;

require __DIR__ . '/vendor/autoload.php';

Zog::setViewDir(__DIR__ . '/views');                     // where your .php templates live
Zog::setStaticDir(__DIR__ . '/static');                  // where hybrid static files are written
Zog::setCompiledDir(__DIR__ . '/storage/zog_compiled');  // where compiled PHP templates are stored
```

> The directories will be created automatically if they do not exist.

### 2. Manual install (alternative)

If you prefer not to use Composer, you can copy `Zog.php` and `View.php` into your project, keep the `Zog` namespace, and load them via your own autoloader or simple `require` statements.

Everything else in this README works the same way.

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

use Zog\Zog;

require __DIR__ . '/../vendor/autoload.php';

Zog::setViewDir(__DIR__ . '/../views');

echo Zog::render('hello.php', [
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

use Zog\Zog;

require __DIR__ . '/../vendor/autoload.php';

Zog::setViewDir(__DIR__ . '/../views');

echo Zog::renderLayout(
    'layouts/main.php',
    'pages/home.php',
    [
        'title'    => 'Home',
        'userName' => 'Reza',
    ]
);
```

## Template Syntax & Directives

Zog uses a custom streaming HTML parser (not `DOMDocument`). It scans your HTML, rewrites special attributes and directives into plain PHP, and leaves everything else alone.

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

<ul>
    @php(for ($i = 0; $i < 3; $i++)):
        <li>@{{ $i }}</li>
    @php(endfor;)
</ul>
```

If you want to disable this directive for security reasons:

```php
Zog::allowRawPhpDirective(false);
```

Any use of `@php(...)` after that will throw a `ZogTemplateException`.

You can also check the current status:

```php
if (!Zog::isRawPhpDirectiveAllowed()) {
    // ...
}
```

### JSON / JavaScript – `@json(...)` and `@tojs(...)`

Both directives are equivalent and produce `json_encode`’d output:

```php
<script>
    const items = @json($items);
    const user  = @tojs($user);
</script>
```

Compiles roughly to:

```php
<?php echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
<?php echo json_encode($user, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
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

At runtime, `Zog\View` handles section buffering and rendering.

Available helpers in `Zog\View`:

```php
View::startSection($name);
View::endSection();
View::yieldSection($name, $default = '');
View::clearSections();
```

### Components – `@component(...)`

You can render partials/components from within a template:

```php
<div class="card">
    @component('components/user-card.php', ['user' => $user])
</div>
```

Or call it directly from PHP:

```php
$html = Zog::component('components/user-card.php', [
    'user' => $user,
]);
```

### Loops – `zp-for`

Use `zp-for` on an element to generate a `foreach`:

```php
<ul>
    <li zp-for="item, index of $items">
        @{{ $index }} – @{{ $item }}
    </li>
</ul>
```

Supported forms:

* `item of $items`
* `item, key of $items`

Behind the scenes, this becomes approximately:

```php
<?php foreach ($items as $index => $item): ?>
    <li>...</li>
<?php endforeach; ?>
```

Invalid `zp-for` expressions cause a `ZogTemplateException`.

### Conditionals – `zp-if`, `zp-else-if`, `zp-else`

Chain conditional attributes at the same DOM level:

```php
<p zp-if="$user->isAdmin">
    You are an admin.
</p>
<p zp-else-if="$user->isModerator">
    You are a moderator.
</p>
<p zp-else>
    You are a regular user.
</p>
```

Compiles roughly to:

```php
<?php if ($user->isAdmin): ?>
    <p>You are an admin.</p>
<?php elseif ($user->isModerator): ?>
    <p>You are a moderator.</p>
<?php else: ?>
    <p>You are a regular user.</p>
<?php endif; ?>
```

If a `zp-else-if` or `zp-else` is found without a preceding `zp-if` at the same level, a `ZogTemplateException` is thrown.

### Disabling Zog in a subtree – `zp-nozog`

Sometimes you want Zog to leave a part of the DOM untouched, especially when embedding the markup of another templating system or frontend framework.

Add `zp-nozog` to any element to disable **DOM-level** Zog processing for that element and all its descendants:

```php
<div zp-nozog>
    <!-- Zog does NOT compile this zp-if -->
    <p zp-if="$user->isAdmin">
        This will be rendered exactly as-is in the final HTML.
    </p>
</div>
```

Behavior:

* Zog does **not** convert `zp-if`, `zp-for`, `zp-else-if`, or `zp-else` inside this subtree into PHP.
* The attribute `zp-nozog` itself is removed from the final HTML.
* Inline text directives such as `@{{ $something }}` and `@raw($something)` **still work** in normal elements, because text processing is independent of DOM-level control attributes.

This is especially useful when you want to keep attributes for a frontend framework:

```php
<div zp-nozog>
    <button v-if="isAdmin">Admin button</button>
</div>
```

Zog will not attempt to interpret `v-if="isAdmin"`.

### `<script>` / `<style>` behavior

`<script>` and `<style>` contents are treated as raw text (not parsed as nested HTML):

* By default, inline Zog directives inside `<script>` / `<style>` **do** work, because the inner text is passed through the same compiler as other text.
* If you put `zp-nozog` directly on the `<script>` or `<style>` tag, Zog will **not** process anything inside it (no inline directives, no control attributes). The contents are emitted verbatim.

This lets you choose exactly how much Zog should do inside your scripts and styles.

## Hybrid Static Cache

Zog’s hybrid cache lets you render a page once, save it as a static file, and serve that file on future requests until a TTL (time to live) expires.

Signature:

```php
Zog::hybrid(
    string $view,
    string|array $key,
    array|callable|null $dataOrFactory = null,
    ?int $cacheTtl = null
);
```

### Cache TTL constants

```php
use Zog\Zog;

Zog::CACHE_NONE;      // 0
Zog::CACHE_A_MINUTE;
Zog::CACHE_AN_HOUR;
Zog::CACHE_A_DAY;
Zog::CACHE_A_WEEK;
```

You can also override the default TTL:

```php
Zog::setDefaultHybridCacheTtl(Zog::CACHE_A_DAY);

// or disable default TTL (TTL must be explicit in hybrid calls)
Zog::setDefaultHybridCacheTtl(null);
```

> Internally, static files start with an HTML comment that holds the expiry date, for example:
> `<!-- Automatically generated by zog: [ex:2025-12-09] -->`
> Browsers and search engines ignore this comment; it has no impact on SEO.

### Mode 1 – Direct data (always re-render)

```php
$html = Zog::hybrid(
    'pages/home.php',
    'home',
    [
        'title' => 'Home',
        'user'  => $user,
    ],
    Zog::CACHE_A_HOUR
);
```

* Always re-renders the view.
* Writes/overwrites the static file.
* Returns the rendered HTML (including the header comment).

This mode behaves like `render()` plus “also save the result to a static file”.

### Mode 2 – Lazy factory (only run when needed)

This is the recommended mode when fetching data from a database or an API:

```php
$html = Zog::hybrid(
    'pages/home.php',
    'home',
    function () use ($db, $userId) {
        // This closure is called only when:
        // - there is no static file, or
        // - it has expired.
        $user = $db->getUserById($userId);

        return [
            'title' => 'Home',
            'user'  => $user,
        ];
    },
    Zog::CACHE_A_HOUR
);
```

Workflow:

1. If a valid static file exists and has not expired, Zog:

   * Returns its contents.
   * Does **not** call the factory.

2. If the file is missing or expired, Zog:

   * Calls the factory.
   * Expects an `array` of data.
   * Renders the view.
   * Writes a new static file with an updated expiry comment.
   * Returns the fresh HTML.

If the factory does not return an array, Zog throws a `ZogException`.

### Mode 3 – Read-only access

You can check or serve an existing static file without rendering or running any data logic:

```php
$content = Zog::hybrid(
    'pages/home.php',
    'home',
    null // read-only mode
);

if ($content === false) {
    // no valid cache yet – decide what to do:
    $content = Zog::render('pages/home.php', [
        'title' => 'Home',
        'user'  => $user,
    ]);
}

echo $content;
```

Returns `false` if:

* The static file does not exist.
* The static file is unreadable.
* The static file is expired or has an invalid expiry comment.

### Static file naming

Static files are stored under the static directory configured with `Zog::setStaticDir()`.

* If `$key` is a **string**, Zog creates a slug-like filename:

  ```text
  viewName-your-key.php
  ```

* If `$key` is an **array**, Zog:

  * Normalizes the array (sorts associative keys, recursively).
  * JSON-encodes it.
  * Hashes the JSON.
  * Uses a short `sha1` prefix like:

  ```text
  viewName-zog-0123456789abcdef.php
  ```

This guarantees that the same logical key always maps to the same static file.

## Directory Helpers & Static Files

```php
use Zog\Zog;

// Change where views are loaded from
Zog::setViewDir(__DIR__ . '/views');

// Change where static cache files are written
Zog::setStaticDir(__DIR__ . '/static');

// Change where compiled PHP templates are stored
Zog::setCompiledDir(__DIR__ . '/storage/zog_compiled');
```

### Clearing caches

```php
// Remove all static HTML files (does not delete the directory itself)
Zog::clearStatics();

// Remove all compiled template files (does not delete the directory itself)
Zog::clearCompiled();
```

### Reading a static file directly

If you know the relative path to a static file under the static directory, you can read it directly:

```php
// Equivalent: Zog::staticFile('pages/home-view-zog-abc123.php');
$content = Zog::static('pages/home-view-zog-abc123.php');
```

This is mainly useful when you manage some static files yourself, or when you want a very thin wrapper around `file_get_contents()` with path-traversal protection.

## Error Handling

Zog uses exceptions for all error conditions:

* `ZogException` – base exception for general runtime issues:

  * bad directories
  * missing view files
  * I/O failures
  * invalid hybrid usage
* `ZogTemplateException` – template compilation errors:

  * invalid `zp-for` / `zp-if` syntax
  * unmatched parentheses in directives
  * `zp-else` without a preceding `zp-if`
  * misuse of section/component directives
  * disabled `@php()` still being used
  * unclosed tags in the HTML source

Example:

```php
try {
    echo Zog::render('pages/home.php', ['user' => $user]);
} catch (\Zog\ZogTemplateException $e) {
    // render a friendly error page for template errors
} catch (\Zog\ZogException $e) {
    // log and render a generic error page
}
```


## Editor.js Integration

Zog includes a built-in parser that converts [Editor.js](https://editorjs.io/) JSON output to clean HTML, ready to be rendered inside any Zog template via `@raw()`.

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
use Zog\EditorJs\EditorJsParser;

$parser = new EditorJsParser();

// $json can be a JSON string or a pre-decoded array
$html = $parser->parse($json);
```

Then render it in a Zog template:

```html
<article>
    @raw($content)
</article>
```

Full example with Zog:

```php
use Zog\Zog;
use Zog\EditorJs\EditorJsParser;

Zog::setViewDir(__DIR__ . '/views');

$parser  = new EditorJsParser();
$content = $parser->parse($jsonFromDatabase);

echo Zog::render('pages/article.php', compact('content'));
```

### Custom CSS classes

Pass a config array to override the CSS class for any block type:

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
    'quote'     => ['class' => 'pullquote', 'captionClass' => 'pullquote__author'],
    'code'      => ['class' => 'code-block', 'codeClass' => 'language-php'],
]);
```

### Registering custom block handlers

If you use custom Editor.js tools, register a handler with a callable:

```php
$parser->registerBlock('alert', function (array $data, array $config): string {
    $type    = htmlspecialchars($data['type'] ?? 'info', ENT_QUOTES, 'UTF-8');
    $message = htmlspecialchars($data['message'] ?? '', ENT_QUOTES, 'UTF-8');
    return "<div class=\"alert alert--{$type}\">{$message}</div>\n";
});
```

Registered handlers take priority over built-in ones, so you can also override any default block type this way.

### Inline HTML sanitization

By default the parser allows common inline tags (`<b>`, `<i>`, `<a>`, `<mark>`, `<code>`, `<br>`, `<span>`, etc.) and strips everything else from text fields. To disable sanitization entirely (only do this if you fully trust the content source):

```php
$parser = new EditorJsParser(config: [], sanitize: false);
```

> **Note:** The `raw` block type always passes its HTML through as-is, regardless of the `sanitize` flag. Sanitize or escape its content on the way into the database if needed.


## Notes

* Zog does **not** use `eval`; compiled templates are normal PHP files that are `require`d.
* All data passed into `render()` (or via `hybrid()`) is available as:

  * Individual variables (`$user`, `$title`, etc.).
  * A full array `$zogData` if you prefer to access everything as an array.
* Zog is intentionally small and framework-agnostic – you can drop it into any PHP project or framework and wire it to your router/controller layer.

## License
MIT

## Contributing

* Open issues and pull requests on GitHub.
* Ideas, bug reports, and feature suggestions are very welcome.
* Help with documentation and logo/design improvements is also appreciated.


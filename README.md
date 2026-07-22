# Webrium View

Lightweight PHP template engine with hybrid static caching (no `eval`, no `DOMDocument`) for the [Webrium](https://github.com/webrium) framework.

- **GitHub:** https://github.com/webrium/view
- **Packagist:** https://packagist.org/packages/webrium/view
- **Install:** `composer require webrium/view`

Webrium View compiles your templates to plain PHP files, never uses `eval`, and is designed to play nicely with modern frontend frameworks (Vue, Alpine, Livewire, etc.) by leaving their attributes untouched.

## Features

- **DOM-less streaming compiler** – custom HTML parser, no `DOMDocument`, so attributes like `@click`, `:class`, `x-data`, `wire:click`, `hx-get`, etc. are preserved exactly as written.
- **Controller-level hybrid cache** – lazily cache views, layouts, named sections, components, or arbitrary rendered HTML with precise TTLs and stampede protection.
- **Directives** – `@{{ }}`, `@raw()`, `@json()` / `@tojs()`, `@php()`, `@php...@endphp`, `@section`, `@yield`, `@component`.
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

Engine::setViewDir(__DIR__ . '/views');                      // where your .php templates live
Engine::setStaticDir(__DIR__ . '/static');                   // where hybrid static files are written
Engine::setCompiledDir(__DIR__ . '/storage/view_compiled');  // where compiled PHP templates are stored
```

> The directories will be created automatically if they do not exist.


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

Engine::setViewDir(__DIR__ . '/../views');

echo Engine::render('hello', [
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

Engine::setViewDir(__DIR__ . '/../views');

echo Engine::renderLayout(
    'layouts/main',
    'pages/home',
    [
        'title'    => 'Home',
        'userName' => 'Reza',
    ]
);
```

## Template Syntax & Directives

Webrium View uses a custom streaming HTML parser (not `DOMDocument`). It scans your HTML, rewrites special attributes and directives into plain PHP, and leaves everything else alone.

### Escaped output – `@{{ ... }}`

Escaped output is the default. The `$` sign is required:

```php
<p>@{{ $user->name }}</p>
<p>@{{ $item['price'] * $qty }}</p>
```

Compiles to:

```php
<?php echo htmlspecialchars($user->name, ENT_QUOTES, 'UTF-8'); ?>
```

Works inside HTML attributes too:

```php
<a href="/users/@{{ $user->id }}">@{{ $user->name }}</a>
```

### Raw output – `@raw(...)`

Use raw output only when you are sure the content is safe (e.g. HTML you generated yourself):

```php
<article>@raw($htmlContent)</article>
```

Compiles to:

```php
<?php echo $htmlContent; ?>
```

### Inline PHP – `@php(...)`

For short, single-line PHP expressions:

```php
@php($count = count($items))
@php($user = Auth::user())
```

Compiles to:

```php
<?php $count = count($items) ?>
<?php $user = Auth::user() ?>
```

### PHP blocks – `@php ... @endphp`

For multiline PHP code, use the block form. `@php` and `@endphp` must each appear alone on their line:

```php
@php
$active  = array_filter($products, fn($p) => $p['stock'] > 0);
$total   = array_sum(array_column($active, 'price'));
$taxRate = 0.09;
@endphp

<p>Total: @{{ $total }}</p>
<p>Tax: @{{ $total * $taxRate }}</p>
```

Compiles to:

```php
<?php
$active  = array_filter($products, fn($p) => $p['stock'] > 0);
$total   = array_sum(array_column($active, 'price'));
$taxRate = 0.09;
?>
```

Both `@php(...)` and `@php...@endphp` can be used in the same template. To disable all `@php` directives for security reasons:

```php
Engine::allowRawPhpDirective(false);
```

Any use of `@php(...)` or `@php...@endphp` after that will throw a `ViewTemplateException`.

### JSON / JavaScript – `@json(...)` and `@tojs(...)`

Both directives are equivalent and produce `json_encode`'d output:

```php
<script>
    const items = @json($items);
    const user  = @tojs($user);
</script>
```

Works inside attributes as well:

```php
<div data-config="@json($config)"></div>
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
    @component('components/user-card', ['user' => $user])
</div>
```

Or call it directly from PHP:

```php
$html = Engine::component('components/user-card', [
    'user' => $user,
]);
```

### Loops – `w-for`

Put `w-for` directly on the HTML element you want to repeat. The syntax follows PHP's own `foreach`:

```php
<ul>
    <li w-for="$items as $item">
        @{{ $item['name'] }}
    </li>
</ul>
```

With key:

```php
<ul>
    <li w-for="$items as $key => $item">
        @{{ $key }}: @{{ $item['name'] }}
    </li>
</ul>
```

Both compile to standard PHP `foreach` / `endforeach` blocks:

```php
<?php foreach ($items as $key => $item): ?>
    <li>...</li>
<?php endforeach; ?>
```

The collection can be any PHP expression:

```php
<tr w-for="$user->orders() as $order">...</tr>
<tr w-for="array_slice($rows, 0, 10) as $row">...</tr>
```

### Conditionals – `w-if`, `w-else-if`, `w-else`

Put `w-if` directly on the element you want to show or hide:

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

`w-if` and `w-for` can be combined on the same element. The `if` wraps the `foreach`:

```php
<li w-if="$showList" w-for="$items as $item">@{{ $item }}</li>
```

### Disabling processing in a subtree – `w-skip`

Add `w-skip` to any element to disable DOM-level processing for that element and all its descendants:

```php
<div w-skip>
    <!-- w-for and w-if inside here are NOT compiled — they are left as-is -->
    <span w-if="$cond">raw attribute, untouched</span>
</div>
```

Behavior:

- `w-if`, `w-for`, `w-else-if`, and `w-else` inside this subtree are **not** converted to PHP.
- The `w-skip` attribute itself is removed from the final HTML.
- Inline directives such as `@{{ $something }}` **still work** in text nodes.

Useful when embedding another frontend framework's syntax:

```php
<div w-skip>
    <button v-if="isAdmin" @click="doSomething">Vue button</button>
    <div x-data="{ open: false }">Alpine component</div>
</div>
```

### `<script>` / `<style>` behavior

Contents are treated as raw text, not parsed as nested HTML:

- By default, inline directives inside `<script>` / `<style>` **do** work.
- Add `w-skip` directly on the tag to emit the contents completely verbatim.

```php
<!-- directives work inside -->
<script>
    const user = @json($user);
</script>

<!-- w-skip: everything inside emitted as-is -->
<script w-skip>
    const tpl = "@{{ this is not compiled }}";
</script>
```

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
    'pages/home',
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
    'pages/home',
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
$content = Engine::hybrid('pages/home', 'home', null);

if ($content === false) {
    $content = Engine::render('pages/home', ['title' => 'Home', 'user' => $user]);
}

echo $content;
```

Returns `false` if no valid (non-expired) cache exists.

### Controller-level layout cache

`hybridLayout()` caches the complete child + layout output. Its lazy factory is
not called on a cache hit, so database queries inside the factory are skipped:

```php
$html = Engine::hybridLayout(
    'layouts/site',
    'pages/article',
    ['article', $locale, $slug],
    function () use ($slug) {
        return ['article' => Article::findBySlug($slug)];
    },
    Engine::CACHE_A_DAY
);
```

Only use a full layout cache when every visitor sharing the key may receive the
same HTML. Do not cache layouts containing session-specific headers, account
details, carts, CSRF tokens, or authorization-dependent content.

### Cached section with a dynamic layout

`hybridSection()` renders and caches one named `@section` from a child view. On
a cache hit its factory and child view are both skipped. Compose the returned
HTML into a freshly-rendered layout with `renderLayoutWithSections()`:

```php
$content = Engine::hybridSection(
    'pages/article',
    'content',
    ['article-content', $locale, $slug],
    function () use ($slug) {
        return [
            'article' => Article::findBySlug($slug),
            'related' => Article::relatedTo($slug),
        ];
    },
    Engine::CACHE_A_DAY
);

$html = Engine::renderLayoutWithSections(
    'layouts/site',
    [
        'seo' => $dynamicSeo,
        'content' => $content,
    ],
    [
        'currentUser' => $currentUser,
        'cartCount' => $cartCount,
    ]
);
```

This keeps the header, footer, SEO, and user-specific layout data dynamic while
avoiding both the expensive content queries and its template render.

### Cached components

Components use the same lazy data contract:

```php
$footer = Engine::hybridComponent(
    'components/footer',
    ['footer', $locale, $settingsVersion],
    fn () => ['links' => FooterLink::published()->get()],
    Engine::CACHE_A_DAY
);
```

### Arbitrary controller HTML

Use a namespace plus key when the cached output is produced by custom code:

```php
$report = Engine::remember(
    'monthly-report',
    [$accountId, $month],
    fn () => $reportRenderer->render($accountId, $month),
    Engine::CACHE_AN_HOUR
);
```

The renderer must return a string.

### Cache policy and TTL behavior

Hybrid expiration is stored as a precise ISO-8601 timestamp. Legacy
date-only cache files remain readable until their recorded day ends.

```php
Engine::enableHybridCache((bool) $config['cache_enabled']);
Engine::setDefaultHybridCacheTtl(Engine::CACHE_A_DAY);
```

- `CACHE_NONE` bypasses rendering cache and removes an existing entry for the
  same identity/key.
- A negative TTL is invalid.
- Setting the default TTL to `null` requires every write call to provide an
  explicit TTL.
- Disabling hybrid cache bypasses reads and writes; lazy factories still run so
  the response renders normally.
- Lazy cache misses are protected by a per-entry file lock and are published
  with an atomic rename.
- Cache keys must contain every value that changes the HTML, such as locale,
  theme, slug, pagination, filters, authorization scope, and content version.

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
    echo Engine::render('pages/home', ['user' => $user]);
} catch (\Webrium\View\ViewTemplateException $e) {
    // Template compilation error.
    error_log($e->getOriginalView() . ':' . $e->getOriginalLine());
} catch (\Webrium\View\ViewException $e) {
    // Runtime error while rendering a view.
    error_log($e->getOriginalView() . ':' . $e->getOriginalLine());
}
```

`ViewException` covers bad directories, missing view files, and I/O failures.  
`ViewTemplateException` covers invalid `w-for` / `w-if` syntax, unclosed tags, unmatched directive parentheses, unclosed `@php` blocks, and disabled `@php` usage.

Runtime and parser failures expose the original template through
`getOriginalView()` and `getOriginalLine()`. The engine stores a small
`.map.json` file beside each compiled template so rewritten or multiline
directives still report their source line. These metadata files are generated
and removed automatically with the compiled templates.

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

echo Engine::render('pages/article', compact('content'));
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

- Webrium View does **not** use `eval`; compiled templates are normal PHP files that are `require`d.
- All data passed into `render()` is available both as individual variables (`$user`, `$title`, etc.) and as a `$zogData` array inside the template.
- The `.php` extension is optional when referencing a view. `Engine::render('hello')` and `Engine::render('hello.php')` resolve to the same file, so you can drop the extension anywhere a view name is expected (`render()`, `component()`, `renderLayout()`, the `view()` / `layout()` helpers, etc.).

## License

MIT

## Contributing

Open issues and pull requests on [GitHub](https://github.com/webrium/view). Ideas, bug reports, and feature suggestions are very welcome.

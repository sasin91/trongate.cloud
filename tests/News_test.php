<?php

/**
 * Tests for the news module's Markdown renderer and the post files in modules/news/posts.
 *
 * Run: php tests/News_test.php (exits non-zero on a failure)
 */

define('ENV', 'prod');

require_once __DIR__ . '/../modules/news/lib/Posts.php';

$failures = 0;

$test = function (string $name, callable $body) use (&$failures): void {
    try {
        $body();
        echo "ok   {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL {$name}\n     {$e->getMessage()}\n";
    }
};

$same = function ($expected, $actual): void {
    if ($expected !== $actual) {
        throw new RuntimeException("Expected:\n" . var_export($expected, true) . "\nGot:\n" . var_export($actual, true));
    }
};

$test('Markdown renders headings, paragraphs and lists', function () use ($same) {
    $same(
        "<h2 id=\"what-changed\">What changed</h2>\n<p>One line and another.</p>\n<ul><li>a <strong>b</strong></li><li>c <em>d</em> continued</li></ul>\n<ol><li>first</li><li>second</li></ol>",
        Markdown::render("## What changed\n\nOne line\nand another.\n\n- a **b**\n- c *d*\n  continued\n\n1. first\n2. second\n")
    );
});

$test('Markdown keeps code as written', function () use ($same) {
    $same("<pre><code>\$a = &#039;&lt;b&gt;&#039;; // **not bold**</code></pre>", Markdown::render("```php\n\$a = '<b>'; // **not bold**\n```"));
    $same('<p>Use <code>getenv(&#039;*X*&#039;)</code> here</p>', Markdown::render("Use `getenv('*X*')` here"));
});

$test('Markdown escapes HTML and drops unsafe links', function () use ($same) {
    $same('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', Markdown::render('<script>alert(1)</script>'));
    $same('<p>click</p>', Markdown::render('[click](javascript:alert)'));
    $same('<p><a href="/register">Start</a> or <a href="https://trongate.io/?a=1&amp;b=2" target="_blank" rel="noopener">read</a></p>', Markdown::render('[Start](/register) or [read](https://trongate.io/?a=1&b=2)'));
    $same('<p><a href="https://x.test/&quot;onmouseover=&quot;" target="_blank" rel="noopener">x</a></p>', Markdown::render('[x](https://x.test/"onmouseover=")'));
});

$test('Markdown renders quotes', function () use ($same) {
    $same('<blockquote><p>Said once.</p></blockquote>', Markdown::render("> Said\n> once."));
});

$test('every post file parses, with a known kind and a summary', function () use ($same) {
    $files = glob(Posts::DIRECTORY . '/*.md');
    $posts = Posts::all();
    $same(count($files), count($posts) + count(array_filter($files, fn($f) => str_contains((string) file_get_contents($f), "\ndraft: true\n"))));
    foreach ($posts as $post) {
        $same(true, $post->summary !== '');
        $same(true, Posts::find($post->slug)?->html !== '');
    }
});

$test('posts are newest first and filter by kind', function () use ($same) {
    $dates = array_column(Posts::all(), 'date');
    $sorted = $dates;
    rsort($sorted);
    $same($sorted, $dates);
    $same(['changelog'], array_values(array_unique(array_column(Posts::all('changelog'), 'kind'))));
});

$test('find() only matches published slugs', function () use ($same) {
    $same(null, Posts::find('../../config/config'));
    $same(null, Posts::find(''));
});

echo $failures === 0 ? "\nAll tests passed\n" : "\n{$failures} failed\n";
exit($failures === 0 ? 0 : 1);

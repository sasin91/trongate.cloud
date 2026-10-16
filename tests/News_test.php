<?php

/**
 * Tests for the news module's Markdown renderer and the post files in modules/news/posts.
 *
 * Run: php tests/News_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/bootstrap.php';

define('ENV', 'prod');

require_once __DIR__ . '/../modules/news/lib/Posts.php';

test('Markdown renders headings, paragraphs and lists', function () {
    assert_same(
        "<h2 id=\"what-changed\">What changed</h2>\n<p>One line and another.</p>\n<ul><li>a <strong>b</strong></li><li>c <em>d</em> continued</li></ul>\n<ol><li>first</li><li>second</li></ol>",
        Markdown::render("## What changed\n\nOne line\nand another.\n\n- a **b**\n- c *d*\n  continued\n\n1. first\n2. second\n")
    );
});

test('Markdown keeps code as written', function () {
    assert_same("<pre><code>\$a = &#039;&lt;b&gt;&#039;; // **not bold**</code></pre>", Markdown::render("```php\n\$a = '<b>'; // **not bold**\n```"));
    assert_same('<p>Use <code>getenv(&#039;*X*&#039;)</code> here</p>', Markdown::render("Use `getenv('*X*')` here"));
});

test('Markdown escapes HTML and drops unsafe links', function () {
    assert_same('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', Markdown::render('<script>alert(1)</script>'));
    assert_same('<p>click</p>', Markdown::render('[click](javascript:alert)'));
    assert_same('<p><a href="/register">Start</a> or <a href="https://trongate.io/?a=1&amp;b=2" target="_blank" rel="noopener">read</a></p>', Markdown::render('[Start](/register) or [read](https://trongate.io/?a=1&b=2)'));
    assert_same('<p><a href="https://x.test/&quot;onmouseover=&quot;" target="_blank" rel="noopener">x</a></p>', Markdown::render('[x](https://x.test/"onmouseover=")'));
});

test('Markdown renders quotes', function () {
    assert_same('<blockquote><p>Said once.</p></blockquote>', Markdown::render("> Said\n> once."));
});

test('Markdown renders images on their own line, sized from the file', function () {
    assert_same(
        '<figure class="lp-figure lp-figure-tall"><a href="/images/news/2026-10-09/tabs-phone.webp" target="_blank" rel="noopener"><img src="/images/news/2026-10-09/tabs-phone.webp" alt="Tabs &quot;on&quot; a phone" width="780" height="900" loading="lazy"></a></figure>',
        Markdown::render('![Tabs "on" a phone](/images/news/2026-10-09/tabs-phone.webp)')
    );
    assert_same('<figure class="lp-figure"><a href="/missing.png" target="_blank" rel="noopener"><img src="/missing.png" alt="" loading="lazy"></a></figure>', Markdown::render('![](/missing.png)'));
    // Only site-relative images are shown; anything else is at most a link
    assert_same('<p>!<a href="https://evil.test/a.png" target="_blank" rel="noopener">x</a></p>', Markdown::render('![x](https://evil.test/a.png)'));
    assert_same('<p>!x</p>', Markdown::render('![x](//evil.test/a.png)'));
});

test('headings link to themselves and list as sections', function () {
    assert_same(
        '<h2 id="mail-tab">Mail tab <a class="lp-anchor" href="news/today#mail-tab" aria-label="Link to this section">#</a></h2>',
        Markdown::render('## Mail tab', 'news/today')
    );
    assert_same(['a-database-tab' => 'A *Database* tab', 'more' => 'More'], Markdown::headings("## A *Database* tab\n\n### Not this\n\n```\n## Not code\n```\n## More"));
});

test('every image in a post is in public/', function () {
    foreach (glob(__DIR__ . '/../modules/news/posts/*.md') as $path) {
        preg_match_all('#^!\[[^\]]*\]\((/[^)\s]+)\)$#m', file_get_contents($path), $images);
        foreach ($images[1] as $src) {
            if (!is_file(__DIR__ . '/../public' . $src)) {
                throw new RuntimeException(basename($path) . " shows {$src}, which is not in public/");
            }
        }
    }
});

test('every post file parses, with a known kind and a summary', function () {
    $files = glob(Posts::DIRECTORY . '/*.md');
    $posts = Posts::all();
    assert_same(count($files), count($posts) + count(array_filter($files, fn($f) => str_contains((string) file_get_contents($f), "\ndraft: true\n"))));
    foreach ($posts as $post) {
        assert_same(true, $post->summary !== '');
        assert_same(true, Posts::find($post->slug)?->html !== '');
    }
});

test('posts are newest first and filter by kind', function () {
    $dates = array_column(Posts::all(), 'date');
    $sorted = $dates;
    rsort($sorted);
    assert_same($sorted, $dates);
    assert_same(['changelog'], array_values(array_unique(array_column(Posts::all('changelog'), 'kind'))));
});

test('no slug is taken by a route of its own', function () {
    foreach (Posts::all() as $post) {
        assert_same(false, in_array($post->slug, ['feed', ...array_keys(Posts::KINDS)], true));
    }
});

test('find() only matches published slugs', function () {
    assert_same(null, Posts::find('../../config/config'));
    assert_same(null, Posts::find(''));
});

test('merged posts lead to an existing section of the post that replaced them', function () {
    foreach (Posts::all() as $post) {
        $html = Markdown::render($post->body);
        foreach ($post->replaces as $old => $section) {
            assert_same(null, Posts::find($old));
            assert_same('news/' . $post->slug . ($section !== '' ? '#' . $section : ''), Posts::moved($old));
            if ($section !== '') {
                assert_same(true, str_contains($html, ' id="' . $section . '"'));
            }
        }
    }
    assert_same(null, Posts::moved('never-existed'));
});

finish_tests();

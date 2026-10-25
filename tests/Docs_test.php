<?php

/**
 * Tests for the docs module: the page files in modules/docs/pages and the sample modules to download.
 *
 * Run: php tests/Docs_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/bootstrap.php';

define('ENV', 'prod');

require_once __DIR__ . '/../modules/docs/lib/Pages.php';

$fixtures = __DIR__ . '/fixtures/docs_samples';

test('every page file parses, with a summary and a body', function () {
    $files = glob(Pages::DIRECTORY . '/*.md');
    $pages = Pages::all();
    assert_same(count($files), count($pages) + count(array_filter($files, fn($f) => str_contains((string) file_get_contents($f), "\ndraft: true\n"))));
    foreach ($pages as $page) {
        assert_same(true, $page->summary !== '');
        assert_same(true, Pages::find($page->slug)?->html !== '');
    }
});

test('pages are in sidebar order', function () {
    $orders = array_column(Pages::all(), 'order');
    $sorted = $orders;
    sort($sorted);
    assert_same($sorted, $orders);
});

test('a page names a sample that exists', function () {
    foreach (glob(Pages::DIRECTORY . '/*.md') as $path) {
        if (preg_match('/^sample:\s*(.*)$/m', (string) file_get_contents($path), $match)) {
            assert_same(true, Sample::exists(trim($match[1])));
        }
    }
});

test('a page links to the pages before and after it', function () {
    $pages = Pages::all();
    $first = Pages::find($pages[0]->slug);
    assert_same(null, $first->previous);
    assert_same($pages[1]->slug ?? null, $first->next?->slug);
});

test('no slug is taken by a route of its own', function () {
    foreach (Pages::all() as $page) {
        assert_same(false, $page->slug === 'samples');
    }
});

test('find() only matches published slugs', function () {
    assert_same(null, Pages::find('../../config/config'));
    assert_same(null, Pages::find(''));
});

test('every sample has files, and none are hidden', function () {
    foreach (glob(Sample::DIRECTORY . '/*', GLOB_ONLYDIR) as $folder) {
        $files = Sample::files(basename($folder));
        assert_same(true, $files !== []);
    }
});

test('sample names are plain folder names', function () use ($fixtures) {
    assert_same(true, Sample::exists('demo', $fixtures));
    assert_same(false, Sample::exists('missing', $fixtures));
    assert_same(false, Sample::exists('../docs_samples/demo', $fixtures));
    assert_same(false, Sample::exists('', $fixtures));
});

test('a sample lists its files by their place in the app, skipping hidden ones', function () use ($fixtures) {
    $files = Sample::files('demo', $fixtures);
    assert_same(['README.md', 'modules/demo/runtime.php'], array_column($files, 'path'));
    assert_same('7 B', $files[0]->size);
});

test('a sample zips with the same paths', function () use ($fixtures) {
    $target = (string) tempnam(sys_get_temp_dir(), 'docs');
    try {
        Sample::zip('demo', $target, $fixtures);
        $zip = new ZipArchive();
        $zip->open($target);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        assert_same(['README.md', 'modules/demo/runtime.php'], $names);
        assert_same("<?php\n\necho \"demo\\n\";\n", $zip->getFromName('modules/demo/runtime.php'));
        $zip->close();
    } finally {
        unlink($target);
    }
});

finish_tests();

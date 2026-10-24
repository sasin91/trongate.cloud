<?php

/**
 * Tests for App_database::tsv (the Database tab's Copy on a SQL result): the
 * text a result becomes when pasted into a spreadsheet.
 *
 * Run: php tests/App_database_tsv_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/bootstrap.php';

define('APPPATH', dirname(__DIR__) . '/');

require_once APPPATH . 'modules/platform/live/App_database.php';

test('column names first, then one line per row', function () {
    $text = App_database::tsv(['id', 'email'], [[1 => '1', 2 => 'a@example.com'], ['2', 'b@example.com']]);

    assert_same("id\temail\n1\ta@example.com\n2\tb@example.com", $text);
});

test('NULL stays NULL and an empty string stays empty', function () {
    assert_same("a\tb\nNULL\t", App_database::tsv(['a', 'b'], [[null, '']]));
});

test('a value with a tab, line break or quote is quoted', function () {
    $text = App_database::tsv(['note'], [["one\ttwo"], ["line\nbreak"], ['say "hi"']]);

    assert_same("note\n\"one\ttwo\"\n\"line\nbreak\"\n\"say \"\"hi\"\"\"", $text);
});

test('a value that is not UTF-8 becomes its size', function () {
    assert_same("blob\n3 bytes", App_database::tsv(['blob'], [["\xff\xfe\x00"]]));
});

test('a value a spreadsheet would run as a formula gets a quote in front', function () {
    $text = App_database::tsv(['=1+1'], [['=HYPERLINK("http://example.com")'], ['+45 12 34'], ['@SUM(A1)'], ['-cmd'], ["\tx"]]);

    assert_same("'=1+1\n\"'=HYPERLINK(\"\"http://example.com\"\")\"\n'+45 12 34\n'@SUM(A1)\n'-cmd\n\"'\tx\"", $text);
});

test('a plain number keeps its sign', function () {
    assert_same("n\n-5\n+3.5\n-1e3", App_database::tsv(['n'], [['-5'], ['+3.5'], ['-1e3']]));
});

test('a result with no rows is just its column names', function () {
    assert_same("count", App_database::tsv(['count'], []));
});

finish_tests();

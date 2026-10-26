--TEST--
a callback gets each line as it comes, from both streams
--SKIPIF--
<?php (PHP_OS_FAMILY === 'Windows') && die('skip needs sh'); ?>
--FILE--
<?php
require __DIR__ . '/setup.inc';
$lines = [];
(new Process(['sh', '-c', 'echo a; echo b >&2; printf c']))->run(function (string $type, string $line) use (&$lines) {
    $lines[] = "{$type} {$line}";
});
sort($lines);
echo implode("\n", $lines), "\n";
?>
--EXPECT--
err b
out a
out c

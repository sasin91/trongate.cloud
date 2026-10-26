--TEST--
output without line breaks reaches the callback in bounded pieces
--SKIPIF--
<?php (PHP_OS_FAMILY === 'Windows') && die('skip needs sh'); ?>
--FILE--
<?php
require __DIR__ . '/setup.inc';
$largest = 0;
$total = 0;
(new Process(['head', '-c', '3000000', '/dev/zero']))->run(function (string $type, string $piece) use (&$largest, &$total) {
    $largest = max($largest, strlen($piece));
    $total += strlen($piece);
});
var_dump($total, $largest <= Process::MAX_LINE_BYTES + Process::READ_BYTES);
?>
--EXPECT--
int(3000000)
bool(true)

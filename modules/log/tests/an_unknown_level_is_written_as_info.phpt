--TEST--
an unknown level is written as info
--FILE--
<?php
require __DIR__ . '/setup.inc';
$kept = new Kept_lines();
(new Log([$kept]))->write('loud', 'x');
echo implode("\n", $kept->lines), "\n";
?>
--EXPECT--
info x

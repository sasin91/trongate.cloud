--TEST--
a program that does not exist cannot start
--FILE--
<?php
require __DIR__ . '/setup.inc';
echo thrown(fn() => (new Process(['/no/such/program']))->run()), "\n";
?>
--EXPECTF--
RuntimeException: %SCannot start /no/such/program%S

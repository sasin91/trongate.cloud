--TEST--
a process runs once; running it again is refused
--SKIPIF--
<?php (PHP_OS_FAMILY === 'Windows') && die('skip needs sh'); ?>
--FILE--
<?php
require __DIR__ . '/setup.inc';
$process = new Process(['true']);
$process->run();
echo thrown(fn() => $process->run()), "\n";
?>
--EXPECT--
LogicException: true has already run; make a new Process

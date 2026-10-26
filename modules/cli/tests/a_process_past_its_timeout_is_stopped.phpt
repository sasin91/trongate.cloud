--TEST--
a process that runs past its timeout is stopped
--SKIPIF--
<?php (PHP_OS_FAMILY === 'Windows') && die('skip needs sh'); ?>
--FILE--
<?php
require __DIR__ . '/setup.inc';
$started = microtime(true);
$process = new Process(['sleep', '30'], timeout: 1);
echo thrown(fn() => $process->must_run()), "\n";
var_dump($process->timed_out(), microtime(true) - $started < 10);
?>
--EXPECTF--
RuntimeException: %Stimed out after 1 seconds%S
bool(true)
bool(true)

--TEST--
a process runs in its folder with its environment
--SKIPIF--
<?php (PHP_OS_FAMILY === 'Windows') && die('skip needs sh'); ?>
--FILE--
<?php
require __DIR__ . '/setup.inc';
$process = new Process(['sh', '-c', 'pwd; echo "$GREETING"'], '/tmp', ['GREETING' => 'hej']);
$process->run();
var_dump($process->output() === realpath('/tmp') . "\nhej\n");
?>
--EXPECT--
bool(true)

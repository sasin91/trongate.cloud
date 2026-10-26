--TEST--
a process keeps its output byte for byte when there is no callback
--SKIPIF--
<?php (PHP_OS_FAMILY === 'Windows') && die('skip needs sh'); ?>
--FILE--
<?php
require __DIR__ . '/setup.inc';
$process = new Process(['sh', '-c', 'printf "one\ntwo"; printf "oops" >&2; exit 1']);
var_dump($process->run(), $process->output(), $process->error_output(), $process->is_successful());
?>
--EXPECT--
int(1)
string(7) "one
two"
string(4) "oops"
bool(false)

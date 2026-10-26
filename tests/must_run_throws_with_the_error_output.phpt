--TEST--
must_run passes on success and throws with the error output otherwise
--SKIPIF--
<?php (PHP_OS_FAMILY === 'Windows') && die('skip needs sh'); ?>
--FILE--
<?php
require __DIR__ . '/setup.inc';
echo (new Process(['true']))->must_run()->exit_code(), "\n";
echo thrown(fn() => (new Process(['sh', '-c', 'echo nope >&2; exit 3']))->must_run()), "\n";
?>
--EXPECT--
0
RuntimeException: sh -c 'echo nope >&2; exit 3' exited with 3: nope

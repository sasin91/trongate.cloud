--TEST--
the error_log writer writes the prefix, the level and the line
--FILE--
<?php
require __DIR__ . '/setup.inc';
$file = tempnam(sys_get_temp_dir(), 'log-test');
ini_set('error_log', $file);
(new Log([new Error_log_writer('[webhooks] ')]))->warn('Unknown event');
var_dump(str_contains((string) file_get_contents($file), '[webhooks] warn: Unknown event'));
unlink($file);
?>
--EXPECT--
bool(true)

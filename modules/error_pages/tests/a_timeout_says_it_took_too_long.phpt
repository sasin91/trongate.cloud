--TEST--
a fatal timeout says it took too long (503); other fatal errors are a 500
--FILE--
<?php
require __DIR__ . '/setup.inc';
$timeout = ['type' => E_ERROR, 'message' => 'Maximum execution time of 30 seconds exceeded', 'file' => 'x.php', 'line' => 3];
$r = Error_report::from_fatal($timeout);
echo $r->status, ' ', $r->title, "\n";
$oom = ['type' => E_ERROR, 'message' => 'Allowed memory size of 134217728 bytes exhausted', 'file' => 'x.php', 'line' => 3];
echo Error_report::from_fatal($oom)->status, "\n";
var_dump((E_WARNING & Error_report::FATAL) === 0, (E_PARSE & Error_report::FATAL) !== 0);
?>
--EXPECT--
503 That took too long
500
bool(true)
bool(true)

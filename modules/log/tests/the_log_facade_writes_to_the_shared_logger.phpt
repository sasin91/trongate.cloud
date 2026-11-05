--TEST--
the Log facade writes to the shared logger, which a script can replace
--FILE--
<?php
require __DIR__ . '/setup.inc';
$kept = new Kept_lines();
Log::use(new Logger([$kept]));
Log::info('started');
Log::warn('slow');
Log::error('failed');
var_dump($kept->lines);
?>
--EXPECT--
array(3) {
  [0]=>
  string(12) "info started"
  [1]=>
  string(9) "warn slow"
  [2]=>
  string(12) "error failed"
}

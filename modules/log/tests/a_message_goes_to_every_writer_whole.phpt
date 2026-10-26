--TEST--
a message goes to every writer whole; a blank one is left out
--FILE--
<?php
require __DIR__ . '/setup.inc';
$a = new Kept_lines();
$b = new Kept_lines();
$log = new Log([$a, $b]);
$log->warn("one\ntwo\n");
$log->info("  \n");
var_dump($a->lines, $a->lines === $b->lines);
?>
--EXPECT--
array(1) {
  [0]=>
  string(12) "warn one
two"
}
bool(true)

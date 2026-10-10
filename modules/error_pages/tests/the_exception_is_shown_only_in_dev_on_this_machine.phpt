--TEST--
the exception itself is shown only with ENV dev and a request from this machine
--FILE--
<?php
require __DIR__ . '/setup.inc';
var_dump(Error_report::shows_detail('dev', ['REMOTE_ADDR' => '127.0.0.1']));
var_dump(Error_report::shows_detail('dev', ['REMOTE_ADDR' => '::1']));
var_dump(Error_report::shows_detail('dev', ['REMOTE_ADDR' => '203.0.113.7']));
var_dump(Error_report::shows_detail('prod', ['REMOTE_ADDR' => '127.0.0.1']));
$e = new LogicException('secret path');
var_dump(Error_report::from_exception($e)->detail);
var_dump(str_starts_with(Error_report::from_exception($e, true)->detail, 'LogicException: secret path'));
?>
--EXPECT--
bool(true)
bool(true)
bool(false)
bool(false)
string(0) ""
bool(true)

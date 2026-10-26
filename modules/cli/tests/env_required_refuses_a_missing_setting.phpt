--TEST--
Env::required returns the value, and refuses a missing one
--FILE--
<?php
require __DIR__ . '/setup.inc';
putenv('CLI_TEST_SETTING=yes');
echo Env::required('CLI_TEST_SETTING'), "\n";
putenv('CLI_TEST_SETTING');
echo thrown(fn() => Env::required('CLI_TEST_SETTING')), "\n";
?>
--EXPECTF--
yes
RuntimeException: %SCLI_TEST_SETTING is not set%S

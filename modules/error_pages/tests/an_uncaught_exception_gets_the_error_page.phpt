--TEST--
an uncaught exception gets the error page, escaped and without internals; fetch() gets JSON
--INI--
error_log=/dev/null
--FILE--
<?php
define('APPPATH', dirname(__DIR__, 3) . '/');
define('BASE_URL', 'https://example.test/');
define('ENV', 'prod');
require_once APPPATH . 'engine/Trongate.php';
require_once __DIR__ . '/../Error_pages.php';
$_SERVER = ['REMOTE_ADDR' => '127.0.0.1'];
ob_start();
Error_pages::_on_exception(new RuntimeException('<b>secret</b> /srv/app/x.php'));
$html = ob_get_clean();
var_dump(str_contains($html, '<h1>Something went wrong</h1>'));
var_dump(str_contains($html, '<base href="https://example.test/">'));
var_dump(str_contains($html, 'secret'));
$_SERVER = ['HTTP_SEC_FETCH_MODE' => 'cors'];
Error_pages::_on_exception(new RuntimeException('secret'));
?>
--EXPECT--
bool(true)
bool(true)
bool(false)
{"error":"This page couldn't load because of an error on our side. It has been logged. Please try again."}

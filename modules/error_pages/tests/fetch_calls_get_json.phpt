--TEST--
fetch() calls and API clients get JSON; a normal page load gets a page
--FILE--
<?php
require __DIR__ . '/setup.inc';
foreach ([
    'page load' => ['HTTP_SEC_FETCH_MODE' => 'navigate', 'HTTP_ACCEPT' => 'text/html,application/xhtml+xml'],
    'fetch' => ['HTTP_SEC_FETCH_MODE' => 'cors'],
    'same-origin fetch' => ['HTTP_SEC_FETCH_MODE' => 'same-origin'],
    'xhr' => ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
    'json body' => ['CONTENT_TYPE' => 'application/json; charset=utf-8'],
    'json accept' => ['HTTP_ACCEPT' => 'application/json'],
    'nothing' => [],
] as $name => $server) {
    echo $name, ': ', Error_report::wants_json($server) ? 'json' : 'page', "\n";
}
?>
--EXPECT--
page load: page
fetch: json
same-origin fetch: json
xhr: json
json body: json
json accept: json
nothing: page

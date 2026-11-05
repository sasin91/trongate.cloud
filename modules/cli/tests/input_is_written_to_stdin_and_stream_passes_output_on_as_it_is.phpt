--TEST--
input is written to stdin, and stream() passes stdout on byte for byte
--SKIPIF--
<?php (PHP_OS_FAMILY === 'Windows') && die('skip needs cat'); ?>
--FILE--
<?php
require __DIR__ . '/setup.inc';
// More than a pipe buffer holds, with and without line breaks, so input and
// output must flow at the same time
$data = str_repeat("line\n", 50000) . str_repeat('x', 300000) . "\0end";
$input = fopen('php://memory', 'w+b');
fwrite($input, $data);
rewind($input);
$out = '';
$process = new Process(['cat'], input: $input);
$code = $process->stream(function (string $chunk) use (&$out) { $out .= $chunk; });
var_dump($code, $out === $data, strlen($out));
?>
--EXPECT--
int(0)
bool(true)
int(550004)

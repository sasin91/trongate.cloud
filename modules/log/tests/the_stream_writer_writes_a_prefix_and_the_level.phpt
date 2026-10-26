--TEST--
the stream writer writes a prefix and the level, or only the prefix
--FILE--
<?php
require __DIR__ . '/setup.inc';
foreach ([['[task #3] ', true], ['[Runtime] ', false]] as [$prefix, $with_level]) {
    $stream = fopen('php://memory', 'w+');
    (new Log([new Stream_writer($prefix, $with_level, $stream)]))->info('hi');
    rewind($stream);
    echo stream_get_contents($stream);
}
?>
--EXPECT--
[task #3] info: hi
[Runtime] hi

--TEST--
a failing writer does not stop the others
--FILE--
<?php
require __DIR__ . '/setup.inc';
$broken = new class implements Log_writer {
    function write(string $level, string $message): void {
        throw new RuntimeException('down');
    }
};
$kept = new Kept_lines();
(new Log([$broken, $kept]))->error('still here');
echo implode("\n", $kept->lines), "\n";
?>
--EXPECTF--
%Aerror still here

<?php

/**
 * modules/pipeline: Pipeline.
 *
 * Run: php tests/Pipeline_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../modules/pipeline/Pipeline.php';

final class Doubles {
    function handle(int $n, Closure $next): mixed {
        return $next($n * 2);
    }

    function twice(int $n, Closure $next): mixed {
        return $next($n * 4);
    }
}

test('the passable goes through the pipes in order, then to the destination', function () {
    $seen = [];
    $result = (new Pipeline())
        ->send(1)
        ->through([
            function (int $n, Closure $next) use (&$seen) {
                $seen[] = "a{$n}";

                return $next($n + 1);
            },
            function (int $n, Closure $next) use (&$seen) {
                $seen[] = "b{$n}";

                return $next($n * 10);
            },
        ])
        ->then(fn(int $n) => "done {$n}");

    assert_same(['a1', 'b2'], $seen);
    assert_same('done 20', $result);
});

test('an object pipe is called through handle, or the method given with via', function () {
    assert_same(6, (new Pipeline())->send(3)->through([new Doubles()])->then_return());
    assert_same(12, (new Pipeline())->send(3)->through([new Doubles()])->via('twice')->then_return());
});

test('pipe adds one more at the end', function () {
    $result = (new Pipeline())->send('a')
        ->through([fn(string $s, Closure $next) => $next($s . 'b')])
        ->pipe(fn(string $s, Closure $next) => $next($s . 'c'))
        ->then_return();

    assert_same('abc', $result);
});

test('a pipe that does not call next stops the pipeline', function () {
    $reached = false;
    $result = (new Pipeline())->send(1)
        ->through([
            fn(int $n, Closure $next) => 'stopped',
            function (int $n, Closure $next) use (&$reached) {
                $reached = true;

                return $next($n);
            },
        ])
        ->then_return();

    assert_same('stopped', $result);
    assert_same(false, $reached);
});

test('a pipe that throws stops the pipeline and the exception reaches the caller', function () {
    $pipeline = (new Pipeline())->send(1)->through([fn(int $n, Closure $next) => throw new RuntimeException('disk full')]);

    assert_throws(fn() => $pipeline->then_return(), 'disk full');
});

finish_tests();

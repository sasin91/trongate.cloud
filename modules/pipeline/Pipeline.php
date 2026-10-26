<?php

/**
 * Sends something through a list of pipes, after Laravel's Pipeline. Each
 * pipe gets the passable and $next, does its work, and passes it on with
 * $next($passable), or stops by returning without calling it:
 *
 *   $result = (new Pipeline())
 *       ->send($order)
 *       ->through([
 *           fn(Order $order, Closure $next) => $next($order->with_tax()),
 *           new Check_stock(),                 // an object: its handle($order, $next)
 *       ])
 *       ->then(fn(Order $order) => $order->save());
 *
 * then_return() ends the pipeline with the passable itself. A pipe that
 * throws stops the pipeline, and the exception reaches the caller.
 *
 * Framework-free.
 */
final class Pipeline {

    private mixed $passable = null;

    /** @var list<callable|object> */
    private array $pipes = [];

    /** The method called on a pipe that is an object, not a callable */
    private string $method = 'handle';

    function send(mixed $passable): self {
        $this->passable = $passable;

        return $this;
    }

    /** @param list<callable|object> $pipes */
    function through(array $pipes): self {
        $this->pipes = $pipes;

        return $this;
    }

    /** One more pipe at the end */
    function pipe(callable|object $pipe): self {
        $this->pipes[] = $pipe;

        return $this;
    }

    function via(string $method): self {
        $this->method = $method;

        return $this;
    }

    /** Runs the pipes, then $destination with what comes out of the last one */
    function then(callable $destination): mixed {
        $next = fn(mixed $passable) => $destination($passable);

        foreach (array_reverse($this->pipes) as $pipe) {
            $next = fn(mixed $passable) => $this->call($pipe, $passable, $next);
        }

        return $next($this->passable);
    }

    function then_return(): mixed {
        return $this->then(fn(mixed $passable) => $passable);
    }

    private function call(callable|object $pipe, mixed $passable, Closure $next): mixed {
        if (is_callable($pipe)) {
            return $pipe($passable, $next);
        }

        return $pipe->{$this->method}($passable, $next);
    }
}

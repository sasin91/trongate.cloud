# pipeline

Sends something through a list of pipes, after Laravel's Pipeline; framework-free, for web requests and command-line scripts alike.

```php
$result = (new Pipeline())
    ->send($passable)
    ->through([
        fn($passable, Closure $next) => $next($passable),
        new Some_pipe(),          // an object: handle($passable, $next), or ->via('other_method')
    ])
    ->then(fn($passable) => ...); // or ->then_return()
```

A pipe stops the pipeline by returning without calling `$next`, or by throwing (the exception reaches the caller).

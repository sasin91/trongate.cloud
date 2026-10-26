<?php

require_once __DIR__ . '/Log.php';

/**
 * A command-line script as named pipelines of actions, run in order:
 *
 *   $pipeline = new Pipeline($log, [
 *       'dump' => [fn() => ..., fn() => ...],
 *       'upload' => [...],
 *   ]);
 *   exit($pipeline->run($argv[1] ?? ''));
 *
 * The first action that throws stops its pipeline: the error is logged,
 * $on_failure runs (to clean up), and run() returns 1.
 *
 * Framework-free.
 */
final class Pipeline {

    /**
     * @param array<string, list<callable(): void>> $pipelines
     * @param (callable(string $name): void)|null $on_failure
     */
    function __construct(private Log $log, private array $pipelines, private $on_failure = null) {
    }

    /** @return int the exit code: 0, or 1 when an action failed or there is no such pipeline */
    function run(string $name): int {
        if (!isset($this->pipelines[$name])) {
            $this->log->error('Usage: ' . implode('|', array_keys($this->pipelines)));

            return 1;
        }

        try {
            foreach ($this->pipelines[$name] as $action) {
                $action();
            }
        } catch (Throwable $e) {
            $this->log->error("{$name} failed: " . $e->getMessage());

            if ($this->on_failure !== null) {
                try {
                    ($this->on_failure)($name);
                } catch (Throwable $cleanup) {
                    $this->log->error('Cleaning up failed too: ' . $cleanup->getMessage());
                }
            }

            return 1;
        }

        $this->log->info("{$name} done");

        return 0;
    }
}

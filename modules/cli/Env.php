<?php

/**
 * Settings a command-line script reads from its environment.
 *
 * Framework-free.
 */
final class Env {

    /**
     * The value of $name
     *
     * @throws RuntimeException when it is not set or empty
     */
    static function required(string $name): string {
        $value = getenv($name);

        if ($value === false || $value === '') {
            throw new RuntimeException("{$name} is not set");
        }

        return $value;
    }
}

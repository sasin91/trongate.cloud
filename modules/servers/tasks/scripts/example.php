<?php

/**
 * Example task script.
 *
 * Available variables:
 *   $payload   - array from server_tasks.payload JSON
 *   $server_id - int from server_tasks.server_id
 *
 * Return: a shell script string to be executed via `sh -c`.
 */

$name = escapeshellarg($payload['name'] ?? 'world');

return "echo \"Hello, {$name} (server {$server_id})\"";

<?php

/**
 * Worker DB
 *
 * The slice of the framework's Db API that Platform_actions uses (query_bind,
 * insert, update, transaction), over the worker's mysqli connection. It lets
 * the worker queue deploys through the same trait as the UI, webhooks and MCP
 * instead of a copy of their rules (see Worker_actions).
 *
 * Named parameters (:name) are rewritten to positional ones, so SQL passed in
 * must not contain ":word" inside string literals.
 */
class Worker_db {
    private mysqli $db;

    function __construct(mysqli $db) {
        $this->db = $db;
    }

    /**
     * Run SQL with named parameters.
     *
     * @param string|null $return_type 'object' (list of rows), 'array' (list of
     *                                 assoc rows), 'column' (first column of the
     *                                 first row, false if none) or null
     */
    function query_bind(string $sql, array $data, ?string $return_type = null): mixed {
        $values = [];
        $sql = preg_replace_callback('/(?<![:\w]):([A-Za-z_][A-Za-z0-9_]*)/', function ($match) use ($data, &$values) {
            if (!array_key_exists($match[1], $data)) {
                throw new InvalidArgumentException("Missing query parameter: {$match[1]}");
            }

            $values[] = $data[$match[1]];
            return '?';
        }, $sql);

        $stmt = $this->_execute((string) $sql, $values);
        $result = $stmt->get_result();
        $stmt->close();

        if ($result === false) {
            return match ($return_type) {
                null => null,
                'column' => false,
                default => [],
            };
        }

        if ($return_type === null) {
            $rows = null;
        } elseif ($return_type === 'object') {
            $rows = [];
            while ($row = $result->fetch_object()) {
                $rows[] = $row;
            }
        } elseif ($return_type === 'column') {
            $row = $result->fetch_row();
            $rows = $row === null ? false : $row[0];
        } else {
            $rows = $result->fetch_all(MYSQLI_ASSOC);
        }

        $result->free();

        return $rows;
    }

    function insert(array $data, string $table): int {
        $columns = implode(', ', array_map(fn($column) => "`{$column}`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $this->_execute("INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})", array_values($data))->close();

        return (int) $this->db->insert_id;
    }

    function update(int $id, array $data, string $table): bool {
        $sets = implode(', ', array_map(fn($column) => "`{$column}` = ?", array_keys($data)));
        $values = [...array_values($data), $id];

        $this->_execute("UPDATE `{$table}` SET {$sets} WHERE `id` = ?", $values)->close();

        return true;
    }

    /**
     * Like Db::transaction: commits when the callback returns a truthy value,
     * rolls back otherwise or when it throws.
     */
    function transaction(callable $callback): mixed {
        $this->db->begin_transaction();

        try {
            $result = $callback($this);
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        $result ? $this->db->commit() : $this->db->rollback();

        return $result;
    }

    private function _execute(string $sql, array $values): mysqli_stmt {
        $stmt = $this->db->prepare($sql);

        if ($values !== []) {
            $types = '';
            foreach ($values as $i => $value) {
                $value = $values[$i] = is_bool($value) ? (int) $value : $value;
                $types .= is_int($value) ? 'i' : (is_float($value) ? 'd' : 's');
            }
            $stmt->bind_param($types, ...$values);
        }

        $stmt->execute();

        return $stmt;
    }
}

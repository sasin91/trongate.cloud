<?php

require_once __DIR__ . '/../manifests/Manifests.php';

/**
 * A customer app's own MariaDB database, opened as the app's own user
 * (Manifests::app creates it with a Grant on its database only), so the
 * control panel can show and edit it without any access the app lacks.
 *
 * Framework-free, like App_logs. Talks to the database server only, never to
 * the app's pod, so reading it never wakes an asleep app.
 */
class App_database {

    /** Rows per page when browsing a table */
    const PAGE_SIZE = 50;

    /** Rows shown for a query; the rest are dropped */
    const MAX_RESULT_ROWS = 500;

    /** MariaDB aborts a statement after this long, so one query cannot tie up a web worker */
    const STATEMENT_SECONDS = 15;

    const CONNECT_SECONDS = 5;

    /** Characters of a long text column read per row when browsing */
    const PREVIEW_CHARS = 1000;

    /** Column types shown as a byte count and never edited (no safe text form) */
    const BINARY_TYPES = ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'geometry', 'point', 'linestring', 'polygon'];

    /** Column types edited in a textarea */
    const LONG_TYPES = ['text', 'tinytext', 'mediumtext', 'longtext', 'json'];

    private function __construct(private PDO $pdo, public readonly string $name) {
    }

    /**
     * @param array $server database_server row (host, port)
     * @throws PDOException when the server cannot be reached or refuses the app's user
     */
    static function open(array $server, int $app_id): self {
        $name = Manifests::database_name($app_id);

        // Client-side cap too: the statement's own session could lift max_statement_time
        ini_set('mysqlnd.net_read_timeout', (string) (self::STATEMENT_SECONDS + 5));

        $dsn = 'mysql:host=' . $server['host'] . ';port=' . (int) ($server['port'] ?? 3306) . ';dbname=' . $name . ';charset=utf8mb4';

        $pdo = new PDO($dsn, $name, Manifests::database_password($app_id), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => self::CONNECT_SECONDS,
            // The server could otherwise ask this pod to upload any of its files (LOAD DATA LOCAL)
            PDO::MYSQL_ATTR_LOCAL_INFILE => false,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        ]);
        $pdo->exec('SET SESSION max_statement_time = ' . self::STATEMENT_SECONDS);

        return new self($pdo, $name);
    }

    /**
     * Tables and views, by name. rows is InnoDB's estimate, not a count.
     *
     * @return array<string, array{name: string, view: bool, rows: int|null, bytes: int|null}>
     */
    function tables(): array {
        $stmt = $this->pdo->query(<<<SQL
            SELECT TABLE_NAME, TABLE_TYPE, TABLE_ROWS, DATA_LENGTH + INDEX_LENGTH AS bytes
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
            ORDER BY TABLE_NAME
        SQL);

        $tables = [];

        foreach ($stmt->fetchAll() as $row) {
            $view = $row['TABLE_TYPE'] === 'VIEW';
            $tables[(string) $row['TABLE_NAME']] = [
                'name' => (string) $row['TABLE_NAME'],
                'view' => $view,
                'rows' => $view || $row['TABLE_ROWS'] === null ? null : (int) $row['TABLE_ROWS'],
                'bytes' => $row['bytes'] === null ? null : (int) $row['bytes'],
            ];
        }

        return $tables;
    }

    /**
     * A table's columns in order. editable: shown in the row form (not
     * binary, not generated).
     *
     * @return list<array{name: string, type: string, column_type: string, nullable: bool, key: bool, auto_increment: bool, default: string|null, editable: bool, long: bool}>
     */
    function columns(string $table): array {
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA, COLUMN_DEFAULT
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION
        SQL);
        $stmt->execute([$table]);

        $columns = [];

        foreach ($stmt->fetchAll() as $row) {
            $type = strtolower((string) $row['DATA_TYPE']);
            $extra = strtolower((string) $row['EXTRA']);
            $generated = str_contains($extra, 'generated') || str_contains($extra, 'virtual') || str_contains($extra, 'persistent') || str_contains($extra, 'stored');

            $columns[] = [
                'name' => (string) $row['COLUMN_NAME'],
                'type' => $type,
                'column_type' => (string) $row['COLUMN_TYPE'],
                'nullable' => $row['IS_NULLABLE'] === 'YES',
                'key' => $row['COLUMN_KEY'] === 'PRI',
                'auto_increment' => str_contains($extra, 'auto_increment'),
                'default' => $row['COLUMN_DEFAULT'] === null ? null : (string) $row['COLUMN_DEFAULT'],
                'editable' => !$generated && !in_array($type, self::BINARY_TYPES, true),
                'long' => in_array($type, self::LONG_TYPES, true),
            ];
        }

        return $columns;
    }

    /**
     * Names of the primary key's columns; empty when the table has none
     *
     * @param list<array{name: string, key: bool}> $columns
     * @return list<string>
     */
    static function primary_key(array $columns): array {
        return array_values(array_map(fn($c) => $c['name'], array_filter($columns, fn($c) => $c['key'])));
    }

    /**
     * One page of a table, in primary key order when it has one. more: a later page exists.
     * Long text is cut to PREVIEW_CHARS and binary columns hold their size in bytes,
     * so a page of large values stays small.
     *
     * @param list<array{name: string, type: string, key: bool, long: bool}> $columns
     * @return array{rows: list<array<string, string|null>>, more: bool}
     */
    function rows(string $table, array $columns, int $page): array {
        $key = self::primary_key($columns);
        $order = $key === [] ? '' : ' ORDER BY ' . implode(', ', array_map([self::class, 'quote_name'], $key));
        $offset = max(0, $page - 1) * self::PAGE_SIZE;

        $select = array_map(function (array $column): string {
            $name = self::quote_name($column['name']);

            return match (true) {
                $column['key'] => $name, // read whole: it picks the row to edit
                self::is_binary($column) => "OCTET_LENGTH({$name}) AS {$name}",
                $column['long'] => 'LEFT(' . $name . ', ' . self::PREVIEW_CHARS . ") AS {$name}",
                default => $name,
            };
        }, $columns);

        $rows = $this->pdo->query(
            'SELECT ' . implode(', ', $select) . ' FROM ' . self::quote_name($table) . $order
            . ' LIMIT ' . (self::PAGE_SIZE + 1) . ' OFFSET ' . $offset
        )->fetchAll();

        return ['rows' => array_slice($rows, 0, self::PAGE_SIZE), 'more' => count($rows) > self::PAGE_SIZE];
    }

    /**
     * The row whose primary key is $key, or null
     *
     * @param array<string, string> $key primary key column => value
     * @return array<string, string|null>|null
     */
    function row(string $table, array $key): ?array {
        [$where, $params] = self::where($key);
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::quote_name($table) . $where . ' LIMIT 1');
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, string|null> $values column => value (null stores NULL)
     * @param array<string, string> $key primary key column => value
     * @return int rows changed (0 when nothing differed)
     */
    function update(string $table, array $key, array $values): int {
        if ($values === []) {
            return 0;
        }

        [$where, $params] = self::where($key);
        $set = implode(', ', array_map(fn($column) => self::quote_name($column) . ' = ?', array_keys($values)));
        $stmt = $this->pdo->prepare('UPDATE ' . self::quote_name($table) . ' SET ' . $set . $where . ' LIMIT 1');
        $stmt->execute([...array_values($values), ...$params]);

        return $stmt->rowCount();
    }

    /**
     * @param array<string, string|null> $values column => value; left-out columns get their default
     */
    function insert(string $table, array $values): void {
        $sql = $values === []
            ? 'INSERT INTO ' . self::quote_name($table) . ' () VALUES ()'
            : 'INSERT INTO ' . self::quote_name($table)
                . ' (' . implode(', ', array_map([self::class, 'quote_name'], array_keys($values))) . ')'
                . ' VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ')';

        $this->pdo->prepare($sql)->execute(array_values($values));
    }

    /**
     * @param array<string, string> $key primary key column => value
     * @return int rows deleted
     */
    function delete(string $table, array $key): int {
        [$where, $params] = self::where($key);
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::quote_name($table) . $where . ' LIMIT 1');
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Run one SQL statement as typed. A statement that returns rows gives its
     * first MAX_RESULT_ROWS rows; any other gives the number of rows it changed.
     *
     * @return array{columns: list<string>, rows: list<list<string|null>>, truncated: bool, affected: int|null, seconds: float}
     * @throws PDOException with MariaDB's own message when the statement fails
     */
    function run(string $sql): array {
        $started = microtime(true);
        $stmt = $this->pdo->query($sql);

        if ($stmt->columnCount() === 0) {
            return ['columns' => [], 'rows' => [], 'truncated' => false, 'affected' => $stmt->rowCount(), 'seconds' => microtime(true) - $started];
        }

        $columns = [];

        for ($i = 0; $i < $stmt->columnCount(); $i++) {
            $columns[] = (string) ($stmt->getColumnMeta($i)['name'] ?? '?');
        }

        $rows = [];
        $truncated = false;

        while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
            if (count($rows) === self::MAX_RESULT_ROWS) {
                $truncated = true;
                break;
            }

            $rows[] = $row;
        }

        $stmt->closeCursor();

        return ['columns' => $columns, 'rows' => $rows, 'truncated' => $truncated, 'affected' => null, 'seconds' => microtime(true) - $started];
    }

    /**
     * @param array{type: string} $column
     */
    static function is_binary(array $column): bool {
        return in_array($column['type'], self::BINARY_TYPES, true);
    }

    /**
     * Whether a value is shown as text (valid UTF-8) rather than as its size
     */
    static function is_text(?string $value): bool {
        return $value === null || preg_match('//u', $value) === 1;
    }

    /**
     * Backtick-quoted identifier
     */
    static function quote_name(string $name): string {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * @param array<string, string> $key
     * @return array{0: string, 1: list<string>}
     */
    private static function where(array $key): array {
        if ($key === []) {
            throw new InvalidArgumentException('A row is picked by its primary key');
        }

        $parts = array_map(fn($column) => self::quote_name((string) $column) . ' = ?', array_keys($key));

        return [' WHERE ' . implode(' AND ', $parts), array_values($key)];
    }
}

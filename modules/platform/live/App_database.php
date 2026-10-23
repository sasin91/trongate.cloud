<?php

require_once __DIR__ . '/../manifests/Manifests.php';
require_once __DIR__ . '/Sql_query_rules.php';

/**
 * A customer app's own MariaDB database, opened as the app's own user
 * (Manifests::app creates it with a Grant on its database only), so the
 * control panel can show and edit it without any access the app lacks.
 *
 * Framework-free, like App_logs. Talks to the database server only, never to
 * the app's pod, so reading it never wakes an asleep app.
 *
 * @phpstan-type Backup_table array{name: string, create: string, triggers: list<string>, columns: list<string>, rows: int, keys: list<array<string, string>>|null}
 * @phpstan-type Backup array{tables: list<Backup_table>, data: string, rows: int, bytes: int}
 */
class App_database {

    /** Rows per page when browsing a table */
    const PAGE_SIZE = 50;

    /** Rows shown for a query; the rest are dropped */
    const MAX_RESULT_ROWS = 500;

    /** Bytes of result data read for a query, so a SELECT of large blobs cannot exhaust PHP's memory */
    const MAX_RESULT_BYTES = 2 * 1024 * 1024;

    /** Longer SQL is refused (the SQL box is for queries, not imports) */
    const MAX_STATEMENT_BYTES = 64 * 1024;

    /** First keywords of statements that only read. They run in a read-only transaction. */
    const READ_STATEMENTS = ['SELECT', 'WITH', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'];

    /** First keywords of statements that change data or tables; they run only with $allow_changes */
    const WRITE_STATEMENTS = ['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME'];

    /** USE and CREATE, ALTER or DROP DATABASE (or SCHEMA): refused, see database_refusal() */
    const DATABASE_STATEMENT = '/^(USE\b|(CREATE|ALTER|DROP)\s+(DATABASE|SCHEMA)\b)/i';

    /** MariaDB aborts a statement (or the rest of several, see run()) after this long, so one query cannot tie up a web worker */
    const STATEMENT_SECONDS = 15;

    const CONNECT_SECONDS = 5;

    /** Characters of a long text column read per row when browsing */
    const PREVIEW_CHARS = 1000;

    /** Column types shown as a byte count and never edited (no safe text form) */
    const BINARY_TYPES = ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'geometry', 'point', 'linestring', 'polygon'];

    /** Column types => Trongate Validation rules for a value typed into the row form (see rules()) */
    const TYPE_RULES = [
        'tinyint' => 'integer', 'smallint' => 'integer', 'mediumint' => 'integer', 'int' => 'integer', 'bigint' => 'integer', 'year' => 'integer',
        'decimal' => 'numeric', 'float' => 'numeric', 'double' => 'numeric',
        'date' => 'valid_date', 'time' => 'valid_time',
        'datetime' => 'callback__valid_db_datetime', 'timestamp' => 'callback__valid_db_datetime',
    ];

    /** Column types edited in a textarea */
    const LONG_TYPES = ['text', 'tinytext', 'mediumtext', 'longtext', 'json'];

    /** Bytes of rows one backup may hold, before compression (see back_up_tables()) */
    const MAX_BACKUP_BYTES = 16 * 1024 * 1024;

    /** Rows, and bytes, per INSERT when restoring a backup */
    const RESTORE_BATCH_ROWS = 500;
    const RESTORE_BATCH_BYTES = 1024 * 1024;

    private function __construct(private PDO $pdo, public readonly string $name) {
    }

    /**
     * @param array $server database_server row (host, port)
     * @param int $statement_seconds how long one statement may run (a file import's run longer, see Sql_import)
     * @throws PDOException when the server cannot be reached or refuses the app's user
     */
    static function open(array $server, int $app_id, int $statement_seconds = self::STATEMENT_SECONDS): self {
        $name = Manifests::database_name($app_id);

        // Client-side cap too: the statement's own session could lift max_statement_time
        ini_set('mysqlnd.net_read_timeout', (string) ($statement_seconds + 5));

        $dsn = 'mysql:host=' . $server['host'] . ';port=' . (int) ($server['port'] ?? 3306) . ';dbname=' . $name . ';charset=utf8mb4';

        $pdo = new PDO($dsn, $name, Manifests::database_password($app_id), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => self::CONNECT_SECONDS,
            // The server could otherwise ask this pod to upload any of its files (LOAD DATA LOCAL)
            PDO::MYSQL_ATTR_LOCAL_INFILE => false,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            // Numbers as MariaDB writes them, so a backed-up DOUBLE is restored exactly
            PDO::ATTR_STRINGIFY_FETCHES => true,
        ]);
        $pdo->exec('SET SESSION max_statement_time = ' . $statement_seconds);

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
     * binary, not generated). generated: computed by MariaDB, never written.
     *
     * @return list<array{name: string, type: string, column_type: string, nullable: bool, key: bool, auto_increment: bool, default: string|null, editable: bool, long: bool, generated: bool}>
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
                'generated' => $generated,
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
     * The table's foreign keys to tables in this database, in constraint name
     * order. to_key: they point at the referenced table's primary key, so each
     * value picks one row. Keys with a binary column are left out (their
     * values are not shown as text).
     *
     * @param list<array{name: string, type: string}> $columns the table's columns()
     * @return list<array{columns: list<string>, table: string, referenced: list<string>, to_key: bool}>
     */
    function foreign_keys(string $table, array $columns): array {
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION
        SQL);
        $stmt->execute([$table]);

        $binary = array_column(array_filter($columns, [self::class, 'is_binary']), 'name');
        $keys = [];

        foreach ($stmt->fetchAll() as $row) {
            $name = (string) $row['CONSTRAINT_NAME'];
            $keys[$name] ??= ['columns' => [], 'table' => (string) $row['REFERENCED_TABLE_NAME'], 'referenced' => [], 'to_key' => false];
            $keys[$name]['columns'][] = (string) $row['COLUMN_NAME'];
            $keys[$name]['referenced'][] = (string) $row['REFERENCED_COLUMN_NAME'];
        }

        $primary_keys = [];

        foreach ($keys as $name => $key) {
            if (array_intersect($key['columns'], $binary) !== []) {
                unset($keys[$name]);
                continue;
            }

            $primary_keys[$key['table']] ??= self::primary_key($this->columns($key['table']));
            $referenced = $key['referenced'];
            $primary_key = $primary_keys[$key['table']];
            sort($referenced);
            sort($primary_key);
            $keys[$name]['to_key'] = $referenced === $primary_key;
        }

        return array_values($keys);
    }

    /**
     * Every base table with its columns, for the Diagram view (Schema_diagram).
     * Two queries for the whole database, however many tables it has.
     *
     * @param array<string, array{name: string, view: bool, rows: int|null}> $tables from tables()
     * @return array<string, array{rows: int|null, columns: list<array{name: string, column_type: string, key: bool}>}>
     */
    function schema_tables(array $tables): array {
        $schema = [];

        foreach ($tables as $name => $table) {
            if (!$table['view']) {
                $schema[$name] = ['rows' => $table['rows'], 'columns' => []];
            }
        }

        $stmt = $this->pdo->query(<<<SQL
            SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, COLUMN_KEY
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            ORDER BY TABLE_NAME, ORDINAL_POSITION
        SQL);

        foreach ($stmt->fetchAll() as $row) {
            $name = (string) $row['TABLE_NAME'];

            if (isset($schema[$name])) {
                $schema[$name]['columns'][] = [
                    'name' => (string) $row['COLUMN_NAME'],
                    'column_type' => (string) $row['COLUMN_TYPE'],
                    'key' => $row['COLUMN_KEY'] === 'PRI',
                ];
            }
        }

        return $schema;
    }

    /**
     * Every foreign key column in the database, one row per column
     *
     * @return list<array{table: string, column: string, parent: string, parent_column: string}>
     */
    function schema_foreign_keys(): array {
        $stmt = $this->pdo->query(<<<SQL
            SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
                AND REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION
        SQL);

        $keys = [];

        foreach ($stmt->fetchAll() as $row) {
            $keys[] = [
                'table' => (string) $row['TABLE_NAME'],
                'column' => (string) $row['COLUMN_NAME'],
                'parent' => (string) $row['REFERENCED_TABLE_NAME'],
                'parent_column' => (string) $row['REFERENCED_COLUMN_NAME'],
            ];
        }

        return $keys;
    }

    /**
     * Every base table's columns, keys and foreign keys, for the Schema view
     * (Schema_diff::from_database), plus whether the server has
     * explicit_defaults_for_timestamp on (it changes what a TIMESTAMP column
     * in schema.sql becomes)
     *
     * @return array{columns: list<array{table: string, name: string, column_type: string, nullable: bool, default: string|null, extra: string}>, keys: list<array{table: string, name: string, non_unique: bool, index_type: string, column: string, sub_part: string|null}>, foreign_keys: list<array{table: string, name: string, column: string, parent: string, parent_column: string, on_delete: string, on_update: string}>, explicit_timestamps: bool}
     */
    function schema_definition(): array {
        $columns = $this->pdo->query(<<<SQL
            SELECT c.TABLE_NAME, c.COLUMN_NAME, c.COLUMN_TYPE, c.IS_NULLABLE, c.COLUMN_DEFAULT, c.EXTRA
            FROM information_schema.COLUMNS c
            JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
            WHERE c.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE'
            ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION
        SQL)->fetchAll();

        $keys = $this->pdo->query(<<<SQL
            SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, INDEX_TYPE, COLUMN_NAME, SUB_PART
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
            ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
        SQL)->fetchAll();

        $foreign_keys = $this->pdo->query(<<<SQL
            SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE
            FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
            WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION
        SQL)->fetchAll();

        $explicit_timestamps = (string) $this->pdo->query('SELECT @@explicit_defaults_for_timestamp')->fetchColumn();

        $definition = [
            'columns' => [],
            'keys' => [],
            'foreign_keys' => [],
            'explicit_timestamps' => $explicit_timestamps === '1' || strtoupper($explicit_timestamps) === 'ON',
        ];

        foreach ($columns as $row) {
            $definition['columns'][] = [
                'table' => (string) $row['TABLE_NAME'],
                'name' => (string) $row['COLUMN_NAME'],
                'column_type' => (string) $row['COLUMN_TYPE'],
                'nullable' => $row['IS_NULLABLE'] === 'YES',
                'default' => $row['COLUMN_DEFAULT'] === null ? null : (string) $row['COLUMN_DEFAULT'],
                'extra' => (string) $row['EXTRA'],
            ];
        }

        foreach ($keys as $row) {
            $definition['keys'][] = [
                'table' => (string) $row['TABLE_NAME'],
                'name' => (string) $row['INDEX_NAME'],
                'non_unique' => (string) $row['NON_UNIQUE'] !== '0',
                'index_type' => (string) $row['INDEX_TYPE'],
                'column' => (string) $row['COLUMN_NAME'],
                'sub_part' => $row['SUB_PART'] === null ? null : (string) $row['SUB_PART'],
            ];
        }

        foreach ($foreign_keys as $row) {
            $definition['foreign_keys'][] = [
                'table' => (string) $row['TABLE_NAME'],
                'name' => (string) $row['CONSTRAINT_NAME'],
                'column' => (string) $row['COLUMN_NAME'],
                'parent' => (string) $row['REFERENCED_TABLE_NAME'],
                'parent_column' => (string) $row['REFERENCED_COLUMN_NAME'],
                'on_delete' => (string) $row['DELETE_RULE'],
                'on_update' => (string) $row['UPDATE_RULE'],
            ];
        }

        return $definition;
    }

    /**
     * Where a row's foreign key leads, as the query of a Database tab URL:
     * the referenced row (table, key) when the key points at a primary key,
     * else the matching rows (table, where). Null when a value is NULL.
     *
     * @param array{columns: list<string>, table: string, referenced: list<string>, to_key: bool} $foreign_key from foreign_keys()
     * @param array<string, mixed> $row
     * @return array{table: string, key?: array<string, string>, where?: array<string, string>}|null
     */
    static function reference(array $foreign_key, array $row): ?array {
        $values = [];

        foreach ($foreign_key['columns'] as $i => $column) {
            if (!isset($row[$column])) {
                return null;
            }

            $values[$foreign_key['referenced'][$i]] = (string) $row[$column];
        }

        return ['table' => $foreign_key['table'], ($foreign_key['to_key'] ? 'key' : 'where') => $values];
    }

    /**
     * One page of a table, in primary key order when it has one, optionally
     * only the rows where columns equal values. more: a later page exists.
     * Long text is cut to PREVIEW_CHARS and binary columns hold their size in bytes,
     * so a page of large values stays small.
     *
     * @param list<array{name: string, type: string, key: bool, long: bool}> $columns
     * @param array<string, string> $where column => value, columns of $columns only
     * @return array{rows: list<array<string, string|null>>, more: bool}
     */
    function rows(string $table, array $columns, int $page, array $where = []): array {
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

        [$filter, $params] = $where === [] ? ['', []] : self::where($where);
        $stmt = $this->pdo->prepare(
            'SELECT ' . implode(', ', $select) . ' FROM ' . self::quote_name($table) . $filter . $order
            . ' LIMIT ' . (self::PAGE_SIZE + 1) . ' OFFSET ' . $offset
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

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
     * @return int rows added
     */
    function insert(string $table, array $values): int {
        $sql = $values === []
            ? 'INSERT INTO ' . self::quote_name($table) . ' () VALUES ()'
            : 'INSERT INTO ' . self::quote_name($table)
                . ' (' . implode(', ', array_map([self::class, 'quote_name'], array_keys($values))) . ')'
                . ' VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ')';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($values));

        return $stmt->rowCount();
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
     * Delete several rows in one transaction: all of them, or none when one fails
     *
     * @param list<array<string, string>> $keys primary keys (column => value)
     * @return int rows deleted (rows already gone count as none)
     * @throws PDOException when MariaDB refuses one, after rolling back
     */
    function delete_rows(string $table, array $keys): int {
        $this->pdo->exec('SET SESSION TRANSACTION READ WRITE');
        $this->pdo->beginTransaction();
        $deleted = 0;

        try {
            foreach ($keys as $key) {
                $deleted += $this->delete($table, $key);
            }

            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $deleted;
    }

    /**
     * Why the SQL may not run here, or null when it may (see run()), by
     * Sql_query_rules. It may hold several statements separated by ";".
     */
    static function refusal(string $sql, bool $allow_changes): ?string {
        return (new Sql_query_rules($allow_changes))->refusal($sql);
    }

    /**
     * Why one statement of refusal() may not run, or null when it may
     */
    static function statement_refusal(string $statement, bool $allow_changes): ?string {
        $syntax_refusal = self::syntax_refusal($statement);

        if ($syntax_refusal !== null) {
            return $syntax_refusal;
        }

        $database_refusal = self::database_refusal($statement);

        if ($database_refusal !== null) {
            return $database_refusal;
        }

        $kind = self::keyword_kind($statement);

        if ($kind === null) {
            return 'Only SELECT, SHOW, DESCRIBE and EXPLAIN run here, or with "Allow changes" INSERT, UPDATE, DELETE, REPLACE, CREATE, ALTER, DROP, TRUNCATE and RENAME.';
        }

        if ($kind === 'write' && !$allow_changes) {
            return 'This changes your database. Tick "Allow changes" to run it.';
        }

        return null;
    }

    /**
     * Statements about which database to use (USE, CREATE DATABASE and the
     * like), which dumps often start with. The app's user is already in the
     * app's own database and may not create or pick another.
     */
    static function database_refusal(string $statement): ?string {
        if (preg_match(self::DATABASE_STATEMENT, self::without_comments($statement))) {
            return 'Your app is already connected to its own database, so USE and CREATE, ALTER or DROP DATABASE are not needed.';
        }

        return null;
    }

    /**
     * Mistakes in one statement that MariaDB would refuse anyway, found
     * without sending it anywhere (syntax_problem()), or null
     */
    static function syntax_refusal(string $statement): ?string {
        $problem = self::syntax_problem($statement);

        return $problem === null ? null : $problem['message'];
    }

    /**
     * A quote or comment that is never closed, or brackets that do not pair
     * up, with where in the statement it is and a few words to show there.
     * Quotes, `quoted names` and comments are read the way statements() reads them.
     *
     * @return array{message: string, label: string, offset: int}|null
     */
    static function syntax_problem(string $statement): ?array {
        $open_brackets = [];
        $length = strlen($statement);

        for ($i = 0; $i < $length; $i++) {
            $char = $statement[$i];

            if ($char === "'" || $char === '"' || $char === '`') {
                $close = self::closing_quote($statement, $i);

                if ($close === null) {
                    return ['message' => 'A ' . $char . ' is never closed.', 'label' => 'opened here, never closed', 'offset' => $i];
                }

                $i = $close;
            } elseif ($char === '#' || ($char === '-' && substr($statement, $i, 2) === '--' && ctype_space($statement[$i + 2] ?? ' '))) {
                $end = strpos($statement, "\n", $i);
                $i = $end === false ? $length : $end;
            } elseif ($char === '/' && ($statement[$i + 1] ?? '') === '*') {
                $end = strpos($statement, '*/', $i + 2);

                if ($end === false) {
                    return ['message' => 'A /* comment is never closed.', 'label' => 'opened here, never closed', 'offset' => $i];
                }

                $i = $end + 1;
            } elseif ($char === '(') {
                $open_brackets[] = $i;
            } elseif ($char === ')') {
                if ($open_brackets === []) {
                    return ['message' => 'A ) has no ( before it.', 'label' => 'no ( before it', 'offset' => $i];
                }

                array_pop($open_brackets);
            }
        }

        if ($open_brackets !== []) {
            return ['message' => 'A ( is never closed.', 'label' => 'opened here, never closed', 'offset' => end($open_brackets)];
        }

        return null;
    }

    /**
     * Where the quote opened at $open closes, or null when it never does. A
     * doubled quote, or a backslash in a string, escapes.
     */
    static function closing_quote(string $sql, int $open): ?int {
        $quote = $sql[$open];
        $length = strlen($sql);

        for ($i = $open + 1; $i < $length; $i++) {
            if ($sql[$i] === '\\' && $quote !== '`') {
                $i++;
            } elseif ($sql[$i] === $quote) {
                if (($sql[$i + 1] ?? '') !== $quote) {
                    return $i;
                }

                $i++;
            }
        }

        return null;
    }

    /**
     * Why SQL may not run anywhere in the panel, whatever its statements:
     * executable comments and files on the database server
     */
    static function content_refusal(string $sql): ?string {
        // Executable comments (/*! ... */, /*M! ... */) run their contents as SQL
        if (preg_match('#/\*M?!#', $sql)) {
            return 'Executable comments (/*! ... */) are not allowed here.';
        }

        // Files on the database server; the app's user lacks the FILE privilege anyway
        if (preg_match('/\bINTO\s+(OUT|DUMP)FILE\b|\bLOAD_FILE\s*\(/i', $sql)) {
            return 'Reading or writing files on the database server is not allowed.';
        }

        return null;
    }

    /**
     * 'write' when any statement in the SQL changes data or tables, 'read' when
     * all only read, or null when one is neither
     */
    static function statement_kind(string $sql): ?string {
        $kinds = array_map([self::class, 'keyword_kind'], self::statements($sql));

        return match (true) {
            $kinds === [] || in_array(null, $kinds, true) => null,
            in_array('write', $kinds, true) => 'write',
            default => 'read',
        };
    }

    /**
     * The statements in SQL separated by ";", without empty ones. A ";" inside
     * a string, a `quoted name` or a comment does not separate.
     *
     * @return list<string>
     */
    static function statements(string $sql): array {
        $statements = [];
        $start = 0;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($char === "'" || $char === '"' || $char === '`') {
                // To the closing quote; a doubled quote, or a backslash in a string, escapes
                for ($i++; $i < $length; $i++) {
                    if ($sql[$i] === '\\' && $char !== '`') {
                        $i++;
                    } elseif ($sql[$i] === $char) {
                        if (($sql[$i + 1] ?? '') !== $char) {
                            break;
                        }

                        $i++;
                    }
                }
            } elseif ($char === '#' || ($char === '-' && substr($sql, $i, 2) === '--' && ctype_space($sql[$i + 2] ?? ' '))) {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
            } elseif ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
            } elseif ($char === ';') {
                $statements[] = substr($sql, $start, $i - $start);
                $start = $i + 1;
            }
        }

        $statements[] = substr($sql, $start);

        return array_values(array_filter(array_map('trim', $statements), fn($statement) => self::without_comments($statement) !== ''));
    }

    /**
     * 'read' or 'write' by one statement's first keyword (after comments), or null when it is neither
     */
    static function keyword_kind(string $statement): ?string {
        $keyword = strtoupper((string) preg_replace('/^([A-Za-z]+).*$/s', '$1', self::without_comments($statement)));

        return match (true) {
            in_array($keyword, self::READ_STATEMENTS, true) => 'read',
            in_array($keyword, self::WRITE_STATEMENTS, true) => 'write',
            default => null,
        };
    }

    /**
     * A statement without its leading whitespace and comments
     */
    static function without_comments(string $statement): string {
        return (string) preg_replace('#^(\s+|--(\s[^\n]*)?(\n|$)|\#[^\n]*(\n|$)|/\*.*?\*/)*#s', '', $statement);
    }

    /**
     * Run the SQL as typed, once refusal() allows it: its statements one after
     * another in one transaction, so when one fails the ones before it are
     * rolled back (except CREATE, ALTER, DROP, TRUNCATE and RENAME, which
     * MariaDB commits at once). Without $allow_changes the transaction is
     * read-only, so MariaDB itself refuses any write (a function with side
     * effects included). All of them together get STATEMENT_SECONDS.
     *
     * Per statement: one that returns rows gives its first MAX_RESULT_ROWS rows
     * (and at most MAX_RESULT_BYTES for all of them); any other gives the number
     * of rows it changed.
     *
     * @return list<array{statement: string, columns: list<string>, rows: list<list<string|null>>, truncated: bool, affected: int|null, seconds: float}>
     * @throws InvalidArgumentException with refusal() when the SQL may not run
     * @throws PDOException with MariaDB's own message when a single statement fails
     * @throws RuntimeException naming the statement when one of several fails
     */
    function run(string $sql, bool $allow_changes = false): array {
        $refusal = self::refusal($sql, $allow_changes);

        if ($refusal !== null) {
            throw new InvalidArgumentException($refusal);
        }

        $statements = self::statements($sql);
        $results = [];
        $bytes_left = self::MAX_RESULT_BYTES;
        $started = microtime(true);
        $committed = []; // statements MariaDB committed at once

        $this->pdo->exec('SET SESSION TRANSACTION ' . ($allow_changes ? 'READ WRITE' : 'READ ONLY'));
        $this->pdo->beginTransaction();

        try {
            foreach ($statements as $i => $statement) {
                $seconds_left = self::STATEMENT_SECONDS - (microtime(true) - $started);

                if ($seconds_left < 0.1) {
                    throw new PDOException('Query execution was interrupted (max_statement_time exceeded)');
                }

                $this->pdo->exec('SET SESSION max_statement_time = ' . round($seconds_left, 1));
                $results[] = $this->run_statement($statement, $bytes_left);

                if (preg_match('/^(CREATE|ALTER|DROP|TRUNCATE|RENAME)\b/i', self::without_comments($statement))) {
                    $committed[] = $i + 1;
                }
            }

            if ($this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if (count($statements) === 1) {
                throw $e;
            }

            $failed = count($results) + 1;
            $message = 'Statement ' . $failed . ' failed: ' . rtrim(self::error_message($e), '.') . '. ';

            if ($committed === []) {
                $message .= 'The statements before it were rolled back, so nothing changed.';
            } else {
                // A CREATE, ALTER, ... commits the transaction so far, itself included
                $last = max($committed);
                $message .= ($last === 1 ? 'Statement 1 stays' : 'Statements 1 to ' . $last . ' stay') . ' changed: MariaDB commits CREATE, ALTER, DROP, TRUNCATE and RENAME at once, with everything before them.';
                $message .= $last + 1 < $failed ? ' The ones after were rolled back.' : '';
            }

            throw new RuntimeException($message, 0, $e);
        } finally {
            $this->pdo->exec('SET SESSION max_statement_time = ' . self::STATEMENT_SECONDS);
        }

        return $results;
    }

    /**
     * Run statements Setup_sql_plan::plan() allowed, one after another, each on
     * its own with STATEMENT_SECONDS: nothing is rolled back, as an import
     * into an empty database is mostly CREATE TABLE, which MariaDB commits at
     * once anyway. Stops at the first one that fails.
     *
     * @param list<string> $statements
     * @return array{index: int, error: string}|null the failed statement (0-based) and MariaDB's message, or null when all ran
     */
    function import(array $statements): ?array {
        $this->prepare_import();

        foreach ($statements as $index => $statement) {
            $error = $this->import_statement($statement);

            if ($error !== null) {
                return ['index' => $index, 'error' => $error];
            }
        }

        return null;
    }

    /**
     * Before import_statement(): statements may change data
     */
    function prepare_import(): void {
        $this->pdo->exec('SET SESSION TRANSACTION READ WRITE');
    }

    /**
     * Run one statement Setup_sql_plan or Sql_import allowed, on its own
     *
     * @return string|null MariaDB's message when it failed
     */
    function import_statement(string $statement): ?string {
        try {
            $this->pdo->exec($statement);
        } catch (PDOException $e) {
            return self::error_message($e);
        }

        return null;
    }

    /**
     * MariaDB's message without PDO's SQLSTATE prefix
     */
    static function error_message(PDOException $e): string {
        return (string) preg_replace('/^SQLSTATE\[\w+\]: [^:]*: (\d+ )?/', '', $e->getMessage());
    }

    /**
     * @param int $bytes_left result bytes still allowed, shared by the statements of one run()
     * @return array{statement: string, columns: list<string>, rows: list<list<string|null>>, truncated: bool, affected: int|null, seconds: float}
     */
    private function run_statement(string $statement, int &$bytes_left): array {
        $started = microtime(true);
        $stmt = $this->pdo->query($statement);

        if ($stmt->columnCount() === 0) {
            return ['statement' => $statement, 'columns' => [], 'rows' => [], 'truncated' => false, 'affected' => $stmt->rowCount(), 'seconds' => microtime(true) - $started];
        }

        $columns = [];

        for ($i = 0; $i < $stmt->columnCount(); $i++) {
            $columns[] = (string) ($stmt->getColumnMeta($i)['name'] ?? '?');
        }

        $rows = [];
        $truncated = false;

        while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
            $bytes_left -= array_sum(array_map(fn($value) => strlen((string) $value), $row));

            if (count($rows) === self::MAX_RESULT_ROWS || $bytes_left < 0) {
                $truncated = true;
                break;
            }

            $rows[] = $row;
        }

        $stmt->closeCursor();

        return ['statement' => $statement, 'columns' => $columns, 'rows' => $rows, 'truncated' => $truncated, 'affected' => null, 'seconds' => microtime(true) - $started];
    }

    /**
     * Base tables (not views) a statement names, to back up before it runs.
     * Generous on purpose: any word or `quoted name` equal to a table's name
     * counts, also as a column name or inside a string.
     *
     * @param array<string, array{name: string, view: bool}> $tables tables()
     * @return list<string>
     */
    static function tables_named_in(string $sql, array $tables): array {
        preg_match_all('/`((?:[^`]|``)+)`|([A-Za-z0-9_$]+)/', $sql, $matches, PREG_SET_ORDER);
        $named = [];

        foreach ($matches as $match) {
            $name = ($match[2] ?? '') !== '' ? $match[2] : str_replace('``', '`', $match[1]);

            if (isset($tables[$name]) && !$tables[$name]['view']) {
                $named[$name] = $name;
            }
        }

        return array_values($named);
    }

    /**
     * CREATE TABLE statements of every base table, without rows, for
     * create_tables() on another app's database (a staging app's copy of its
     * production app). AUTO_INCREMENT counters are left out, so the copy
     * starts from 1. Views and triggers are not copied: theirs name this
     * database and its user.
     *
     * @return array<string, string> table => CREATE TABLE
     */
    function table_definitions(): array {
        $definitions = [];

        foreach ($this->tables() as $table) {
            if ($table['view']) {
                continue;
            }

            $create = (string) $this->pdo->query('SHOW CREATE TABLE ' . self::quote_name($table['name']))->fetch(PDO::FETCH_NUM)[1];
            $definitions[$table['name']] = (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', $create);
        }

        return $definitions;
    }

    /**
     * Create tables from table_definitions(), in any order: foreign keys are
     * not checked meanwhile. Fails on the first table that already exists.
     *
     * @param array<string, string> $definitions table => CREATE TABLE
     * @throws PDOException when MariaDB refuses one (the earlier ones stay)
     */
    function create_tables(array $definitions): void {
        $this->pdo->exec('SET SESSION TRANSACTION READ WRITE');
        $this->pdo->exec('SET SESSION foreign_key_checks = 0');

        try {
            foreach ($definitions as $create) {
                $this->pdo->exec($create);
            }
        } finally {
            $this->pdo->exec('SET SESSION foreign_key_checks = 1');
        }
    }

    /**
     * Back up whole tables (structure, triggers and every row) for restore().
     *
     * @param list<string> $tables base tables
     * @return Backup
     * @throws RuntimeException when the rows are larger than MAX_BACKUP_BYTES
     */
    function back_up_tables(array $tables): array {
        $sizes = $this->tables();

        foreach ($tables as $table) {
            // Data and indexes, an estimate: refuse the hopeless ones before reading a row
            if (($sizes[$table]['bytes'] ?? 0) > 4 * self::MAX_BACKUP_BYTES) {
                throw new RuntimeException(self::too_large($table));
            }
        }

        return $this->back_up(array_fill_keys($tables, null));
    }

    /**
     * Back up one row, for restore() to put back. $replaced_key: the key the
     * row gets from the change (when it changes the primary key), so restoring
     * removes the changed row too.
     *
     * @param array<string, string> $key
     * @param array<string, string> $replaced_key
     * @return Backup
     */
    function back_up_row(string $table, array $key, array $replaced_key = []): array {
        $keys = $replaced_key === [] || $replaced_key === $key ? [$key] : [$key, $replaced_key];

        return $this->back_up([$table => $keys]);
    }

    /**
     * Back up several rows of one table, for restore() to put back
     *
     * @param list<array<string, string>> $keys
     * @return Backup
     */
    function back_up_rows(string $table, array $keys): array {
        return $this->back_up([$table => $keys]);
    }

    /**
     * Put tables back as a backup holds them. A row backup replaces only its
     * rows. A whole table without triggers whose structure is unchanged gets its
     * rows replaced in one transaction; any other is dropped and created again
     * as backed up, triggers included. Foreign keys are not checked meanwhile,
     * so nothing cascades and the tables may come back in any order.
     *
     * @param list<Backup_table> $tables
     * @param string $data the backup's compressed rows
     * @throws RuntimeException when the backup cannot be read or a row's table is gone
     * @throws PDOException when MariaDB refuses a step (an earlier table may already be restored)
     */
    function restore(array $tables, string $data): void {
        $rows = gzinflate($data, 2 * self::MAX_BACKUP_BYTES);

        if ($rows === false) {
            throw new RuntimeException('This backup cannot be read.');
        }

        $records = self::records($rows);
        $current = $this->tables();

        $this->pdo->exec('SET SESSION TRANSACTION READ WRITE');
        $this->pdo->exec('SET SESSION foreign_key_checks = 0');

        try {
            foreach ($tables as $index => $table) {
                $name = self::quote_name($table['name']);

                if ($table['keys'] !== null && !isset($current[$table['name']])) {
                    throw new RuntimeException('The table ' . $table['name'] . ' is gone, so its row cannot be put back.');
                }

                if ($table['keys'] !== null || $this->unchanged($table, $current)) {
                    $this->pdo->beginTransaction();

                    foreach ($table['keys'] ?? [] as $key) {
                        [$where, $params] = self::where($key);
                        $this->pdo->prepare('DELETE FROM ' . $name . $where . ' LIMIT 1')->execute($params);
                    }

                    if ($table['keys'] === null) {
                        $this->pdo->exec('DELETE FROM ' . $name);
                    }

                    $this->insert_rows($table, $records, $index);
                    $this->pdo->commit();
                    continue;
                }

                $this->pdo->exec(($current[$table['name']]['view'] ?? false ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ') . $name);
                $this->pdo->exec($table['create']);
                $this->insert_rows($table, $records, $index);

                foreach ($table['triggers'] as $trigger) {
                    $this->pdo->exec($trigger); // after the rows, so they do not fire for them
                }
            }
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        } finally {
            $this->pdo->exec('SET SESSION foreign_key_checks = 1');
        }
    }

    /**
     * Back up tables, whole (null) or only the rows of their keys. restore()
     * removes the row of every key before putting the backed-up rows back, so a
     * key with no row yet (the key a change gives a row) is removed on restore.
     *
     * Rows are stored as records (table index, length, serialize()d row) in one
     * deflate stream, read unbuffered so a large table never sits in memory twice.
     *
     * @param array<string, list<array<string, string>>|null> $targets table => keys
     * @return Backup
     */
    private function back_up(array $targets): array {
        $deflate = deflate_init(ZLIB_ENCODING_RAW);
        $data = '';
        $bytes = 0;
        $tables = [];

        foreach ($targets as $table => $keys) {
            $table = (string) $table;
            $columns = array_values(array_map(fn($c) => $c['name'], array_filter($this->columns($table), fn($c) => !$c['generated'])));
            $index = count($tables);
            $tables[] = [
                'name' => $table,
                'create' => (string) $this->pdo->query('SHOW CREATE TABLE ' . self::quote_name($table))->fetch(PDO::FETCH_NUM)[1],
                'triggers' => $this->triggers($table),
                'columns' => $columns,
                'rows' => 0,
                'keys' => $keys,
            ];

            $select = 'SELECT ' . implode(', ', array_map([self::class, 'quote_name'], $columns)) . ' FROM ' . self::quote_name($table);
            $queries = [];

            if ($keys === null) {
                $queries[] = [$select, []];
            }

            foreach ($keys ?? [] as $key) {
                [$where, $params] = self::where($key);
                $queries[] = [$select . $where . ' LIMIT 1', $params];
            }

            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

            try {
                foreach ($queries as [$sql, $params]) {
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute($params);

                    while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
                        $record = serialize($row);
                        $bytes += strlen($record);

                        if ($bytes > self::MAX_BACKUP_BYTES) {
                            $stmt->closeCursor();
                            throw new RuntimeException(self::too_large($table));
                        }

                        $data .= deflate_add($deflate, pack('nN', $index, strlen($record)) . $record, ZLIB_NO_FLUSH);
                        $tables[$index]['rows']++;
                    }

                    $stmt->closeCursor();
                }
            } finally {
                $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }
        }

        return [
            'tables' => $tables,
            'data' => $data . deflate_add($deflate, '', ZLIB_FINISH),
            'rows' => array_sum(array_column($tables, 'rows')),
            'bytes' => $bytes,
        ];
    }

    /**
     * CREATE TRIGGER statements of a table, in firing order
     *
     * @return list<string>
     */
    private function triggers(string $table): array {
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?
            ORDER BY ACTION_TIMING, EVENT_MANIPULATION, ACTION_ORDER
        SQL);
        $stmt->execute([$table]);
        $triggers = [];

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $sql = (string) $this->pdo->query('SHOW CREATE TRIGGER ' . self::quote_name((string) $name))->fetch()['SQL Original Statement'];
            // Recreated as the app's own user, which may not name another definer
            $triggers[] = (string) preg_replace('/^CREATE\s+DEFINER\s*=\s*(`[^`]*`|\S+?)@(`[^`]*`|\S+)\s+/i', 'CREATE ', $sql);
        }

        return $triggers;
    }

    /**
     * Whether a backed-up whole table can get its rows back in place: it is
     * still a table with the same structure, and neither has triggers (they would fire)
     *
     * @param Backup_table $table
     * @param array<string, array{view: bool}> $current tables()
     */
    private function unchanged(array $table, array $current): bool {
        if (!isset($current[$table['name']]) || $current[$table['name']]['view'] || $table['triggers'] !== [] || $this->triggers($table['name']) !== []) {
            return false;
        }

        $create = (string) $this->pdo->query('SHOW CREATE TABLE ' . self::quote_name($table['name']))->fetch(PDO::FETCH_NUM)[1];
        $without_counter = fn(string $sql) => preg_replace('/ AUTO_INCREMENT=\d+/', '', $sql);

        return $without_counter($create) === $without_counter($table['create']);
    }

    /**
     * Insert the backup's rows of one table (its records come next in $records)
     *
     * @param Backup_table $table
     * @param Generator<int, array{0: int, 1: list<string|null>}> $records
     */
    private function insert_rows(array $table, Generator $records, int $index): void {
        $insert = 'INSERT INTO ' . self::quote_name($table['name']) . ' (' . implode(', ', array_map([self::class, 'quote_name'], $table['columns'])) . ') VALUES ';
        $placeholders = '(' . implode(', ', array_fill(0, count($table['columns']), '?')) . ')';
        $batch = 0;
        $params = [];
        $bytes = 0;

        while ($records->valid() && $records->current()[0] === $index) {
            $row = $records->current()[1];
            array_push($params, ...$row);
            $bytes += array_sum(array_map(fn($value) => strlen((string) $value), $row));
            $batch++;
            $records->next();

            if ($batch === self::RESTORE_BATCH_ROWS || $bytes >= self::RESTORE_BATCH_BYTES) {
                $this->pdo->prepare($insert . implode(', ', array_fill(0, $batch, $placeholders)))->execute($params);
                [$batch, $params, $bytes] = [0, [], 0];
            }
        }

        if ($batch > 0) {
            $this->pdo->prepare($insert . implode(', ', array_fill(0, $batch, $placeholders)))->execute($params);
        }
    }

    /**
     * The records back_up() wrote: [table index, row]
     *
     * @return Generator<int, array{0: int, 1: list<string|null>}>
     */
    private static function records(string $rows): Generator {
        $offset = 0;

        while ($offset < strlen($rows)) {
            $header = unpack('nindex/Nlength', $rows, $offset);
            $row = $header === false ? false : unserialize(substr($rows, $offset + 6, $header['length']), ['allowed_classes' => false]);

            if ($header === false || !is_array($row)) {
                throw new RuntimeException('This backup cannot be read.');
            }

            $offset += 6 + $header['length'];

            yield [$header['index'], array_values($row)];
        }
    }

    private static function too_large(string $table): string {
        return 'The table ' . $table . ' is too large to back up here (over ' . (self::MAX_BACKUP_BYTES / 1024 / 1024) . ' MB).';
    }

    /**
     * Trongate Validation rules for a value typed into a column, '' when only
     * MariaDB checks it (text: its limits are in characters, max_length counts bytes).
     * An empty value never reaches these columns as text: it means NULL or the default.
     *
     * @param array{type: string, column_type: string} $column
     */
    static function rules(array $column): string {
        $rule = self::TYPE_RULES[$column['type']] ?? null;

        if ($rule === null) {
            return '';
        }

        $unsigned = $rule === 'integer' && str_contains($column['column_type'], 'unsigned');

        return 'required|' . $rule . ($unsigned ? '|callback__valid_db_unsigned' : '');
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
     * A query result as tab-separated text, the column names first, so it
     * pastes into a spreadsheet: NULL as NULL, a value that is not UTF-8 as
     * its size, and a value holding a tab, line break or quote in quotes
     *
     * @param list<string> $columns
     * @param list<array<int|string, string|null>> $rows
     */
    static function tsv(array $columns, array $rows): string {
        $lines = [self::tsv_line($columns)];

        foreach ($rows as $row) {
            $values = [];

            foreach ($row as $value) {
                $values[] = self::tsv_value($value);
            }

            $lines[] = self::tsv_line($values);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $values
     */
    static function tsv_line(array $values): string {
        $fields = [];

        foreach ($values as $value) {
            $fields[] = strpbrk($value, "\t\r\n\"") === false ? $value : '"' . str_replace('"', '""', $value) . '"';
        }

        return implode("\t", $fields);
    }

    static function tsv_value(?string $value): string {
        if ($value === null) {
            return 'NULL';
        }

        if (!self::is_text($value)) {
            return strlen($value) . ' bytes';
        }

        return $value;
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
